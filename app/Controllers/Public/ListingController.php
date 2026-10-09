<?php
declare(strict_types=1);

namespace App\Controllers\Public;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Repositories\ListingRepository;
use App\Services\BookingService;
use App\Services\EnquiryService;
use App\Services\Media\MediaService;
use App\Services\PropertyForm;
use App\Services\PropertyViewService;
use App\Services\ShortlistService;

/**
 * Public pages (spec 20-22, 49-51):
 *   GET  /rent  /pg  /lease                     listing + filters, server-side paging
 *   GET  /{purpose}/{slug}-p{id}                 detail page (301 to the canonical URL)
 *   POST /shortlist/{id}                         add/remove ❤️ (login + active account)
 */
final class ListingController
{
    public function index(Request $request): Response
    {
        $purpose = strtolower($request->params['purpose']);
        $f = self::filters($request, $purpose);
        $perPage = max(6, min(60, Settings::int('listings_per_page', 12)));
        $page = current_page();
        $result = ListingRepository::search($f, $page, $perPage);
        $pages = max(1, (int) ceil($result['total'] / $perPage));
        if ($page > $pages && $result['total'] > 0) {
            $query = array_filter(['page' => $pages] + $_GET, static fn ($v) => is_string($v) && $v !== '' || is_int($v));
            return Response::redirect('/' . $purpose . '?' . http_build_query($query));
        }

        $cities = PropertyForm::cities();
        $cityName = $f['city'] ? (array_column($cities, 'name', 'id')[$f['city']] ?? null) : null;
        $label = purpose_label($purpose);
        $heading = ($purpose === 'pg' ? 'PG accommodation' : 'Properties for ' . $label) . ($cityName ? ' in ' . $cityName : '');
        // Only the plain purpose page (optionally one city) is indexed; other filter combinations are noindex.
        $extra = array_diff(array_keys(array_filter($f, static fn ($v) => $v !== null && $v !== '' && $v !== 'recommended')), ['purpose', 'city']);
        $ids = array_map(static fn ($r) => (int) $r['id'], $result['rows']);

        return Response::html(View::render('public/listings', [
            'title'           => $heading,
            'metaDescription' => sprintf('%s %s listed directly by owners%s. Compare rent, photos and facilities, shortlist and contact owners.',
                number_format($result['total']), $result['total'] === 1 ? 'property' : 'properties', $cityName ? ' in ' . $cityName : ''),
            'canonical'       => url('/' . $purpose . ($f['city'] ? '?city=' . $f['city'] : '') . ($page > 1 ? ($f['city'] ? '&' : '?') . 'page=' . $page : '')),
            'noindex'         => $extra !== [],
            'purpose'         => $purpose,
            'heading'         => $heading,
            'f'               => $f,
            'rows'            => $result['rows'],
            'total'           => $result['total'],
            'page'            => $page,
            'perPage'         => $perPage,
            'shortlisted'     => ListingRepository::shortlistedIds(Auth::id(), $ids),
            'cities'          => $cities,
            'localities'      => PropertyForm::localities(),
            'categories'      => self::categories($purpose),
            'types'           => self::types($purpose),
            'budgets'         => ListingRepository::budgets(),
            'scripts'         => ['js/listings.js'],
        ]));
    }

    /** GET /search from the homepage card: forwards to /rent, /pg or /lease with the chosen filters. */
    public function search(Request $request): Response
    {
        $purpose = in_array($request->str('purpose'), ['rent', 'pg', 'lease'], true) ? $request->str('purpose') : 'rent';
        $query = [];
        foreach (['city', 'locality', 'category', 'budget'] as $k) {
            $v = $request->str($k);
            if ($v !== '' && ctype_digit($v)) {
                $query[$k] = $v;
            }
        }
        return Response::redirect('/' . $purpose . ($query ? '?' . http_build_query($query) : ''));
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $p = ListingRepository::find($id);
        if ($p === null) {
            return self::gone($id);
        }
        $canonical = property_url($p);
        if ($request->path !== $canonical) {
            return Response::redirect($canonical, 301);
        }
        if (PropertyViewService::shouldCount($request->userAgent(), $request->method, Auth::id(), $p['owner_id'] !== null ? (int) $p['owner_id'] : null)) {
            PropertyViewService::record($id);
        }

        $type = PropertyForm::type((int) $p['type_id']);
        $profile = PropertyForm::profile((string) $p['purpose'], $type);
        $amenities = Database::all('SELECT a.name FROM property_amenities pa JOIN amenities a ON a.id = pa.amenity_id
                                     WHERE pa.property_id = ? AND a.is_active = 1 ORDER BY a.sort_order, a.name', [$id]);
        $photos = MediaService::photos($id);
        $location = Database::one('SELECT privacy, public_latitude, public_longitude FROM property_locations WHERE property_id = ?', [$id]);
        $place = implode(', ', array_filter([$p['locality_label'], $p['city_name']]));
        $headline = self::headline($p);
        $similar = ListingRepository::similar($p);
        $shortlisted = ListingRepository::shortlistedIds(Auth::id(), [$id, ...array_map(static fn ($r) => (int) $r['id'], $similar)]);

        return Response::html(View::render('public/property', [
            'title'           => $headline . ($place !== '' ? ' in ' . $place : ''),
            'metaDescription' => self::description($p, $place),
            'canonical'       => url($canonical),
            'og'              => ['type' => 'website', 'image' => $photos ? media_url($photos[0], 'lg') : null],
            'jsonLd'          => self::jsonLd($p, $canonical, $photos, $headline),
            'p'               => $p,
            'headline'        => $headline,
            'place'           => $place,
            'shortlisted'     => $shortlisted,
            'isOwner'         => Auth::id() !== null && (int) ($p['owner_id'] ?? 0) === Auth::id(),
            'openVisit'       => Auth::id() !== null ? BookingService::openRequest((int) Auth::id(), $id) : null,
            'lastContact'     => Auth::id() !== null ? EnquiryService::lastContact((int) Auth::id(), $id) : null,
            'similar'         => $similar,
            'scripts'         => ['js/listings.js'],
            'preview'         => [
                'p' => $p, 'type' => $type, 'profile' => $profile,
                'city' => $p['city_name'], 'locality' => $p['locality_label'],
                'amenities' => array_column($amenities, 'name'),
                'services' => array_column(Database::all('SELECT service, price FROM property_pg_services WHERE property_id = ?', [$id]), 'price', 'service'),
                'location' => $location, 'pendingRent' => null,
                'photos' => $photos, 'video' => MediaService::video($id),
                'public' => true,
            ],
        ]));
    }

    public function shortlist(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $want = match ($request->str('state')) { 'on' => true, 'off' => false, default => null };
        [$on, $error] = ShortlistService::set((int) Auth::id(), $id, $want, $request->ip());
        if ($request->isAjax()) {
            return Response::json($error === null ? ['ok' => true, 'shortlisted' => $on] : ['ok' => false, 'error' => $error], $error === null ? 200 : 422);
        }
        Session::flash($error === null ? 'success' : 'error', $error ?? ($on ? 'Added to your shortlist.' : 'Removed from your shortlist.'));
        return Response::redirect(safe_next($request->str('return'), '/'));
    }

    /** Validated filters; anything unknown is dropped rather than trusted. */
    private static function filters(Request $request, string $purpose): array
    {
        $pick = static fn (string $key, string $opt): ?string => array_key_exists($request->str($key), PropertyForm::options($opt)) ? $request->str($key) : null;
        $f = [
            'purpose'     => $purpose,
            'city'        => $request->int('city') ?: null,
            'locality'    => $request->int('locality') ?: null,
            'category'    => $request->int('category') ?: null,
            'type'        => $request->int('type') ?: null,
            'budget'      => null,
            'min'         => max(0, (int) $request->int('min')) ?: null,
            'max'         => max(0, (int) $request->int('max')) ?: null,
            'bedrooms'    => in_array($request->int('bedrooms'), [1, 2, 3, 4], true) ? $request->int('bedrooms') : null,
            'furnishing'  => $pick('furnishing', 'furnishing'),
            'pg_for'      => $purpose === 'pg' ? $pick('pg_for', 'pg_available_for') : null,
            'pg_suitable' => $purpose === 'pg' ? $pick('pg_suitable', 'pg_suitable_for') : null,
            'sort'        => array_key_exists($request->str('sort'), ListingRepository::SORTS) ? $request->str('sort') : 'recommended',
        ];
        $budgets = ListingRepository::budgets();
        $b = $request->query('budget');
        if ($b !== null && $b !== '' && ctype_digit((string) $b) && isset($budgets[(int) $b])) {
            $f['budget'] = (int) $b;
            $f['min'] = (int) ($budgets[(int) $b]['min'] ?? 0) ?: null;
            $f['max'] = isset($budgets[(int) $b]['max']) ? (int) $budgets[(int) $b]['max'] : null;
        }
        if ($f['min'] !== null && $f['max'] !== null && $f['min'] > $f['max']) {
            [$f['min'], $f['max']] = [$f['max'], $f['min']];
        }
        // A sub-type implies its category; a type from another category is ignored.
        if ($f['type'] !== null) {
            $t = PropertyForm::type($f['type']);
            if ($t === null || ($f['category'] !== null && (int) $t['category_id'] !== $f['category'])) {
                $f['type'] = null;
            }
        }
        return $f;
    }

    private static function categories(string $purpose): array
    {
        return Database::all('SELECT c.id, c.code, c.name FROM property_categories c WHERE c.is_active = 1'
            . ($purpose === 'pg' ? ' AND EXISTS (SELECT 1 FROM property_types t WHERE t.category_id = c.id AND t.allows_pg = 1 AND t.is_active = 1)' : '')
            . ' ORDER BY c.sort_order');
    }

    private static function types(string $purpose): array
    {
        return Database::all('SELECT id, category_id, name FROM property_types WHERE is_active = 1'
            . ($purpose === 'pg' ? ' AND allows_pg = 1' : '') . ' ORDER BY sort_order, name');
    }

    /** "2 BHK Flat for Rent" / "Shop for Lease" / "PG in a Flat for Girls". */
    public static function headline(array $p): string
    {
        if ($p['purpose'] === 'pg') {
            $for = ['boys' => ' for Boys', 'girls' => ' for Girls'][(string) $p['pg_available_for']] ?? '';
            return 'PG in a ' . $p['type_name'] . $for;
        }
        $bhk = $p['bedrooms'] !== null && $p['field_profile'] === 'residential' ? (PropertyForm::options('bedrooms')[(int) $p['bedrooms']] ?? '') . ' ' : '';
        return $bhk . $p['type_name'] . ' for ' . purpose_label((string) $p['purpose']);
    }

    /** Meta description from listing facts only; never the owner's name, phone or exact address. */
    private static function description(array $p, string $place): string
    {
        $bits = [self::headline($p) . ($place !== '' ? ' in ' . $place : '')];
        if ($p['rent'] !== null) {
            $bits[] = money_inr((int) $p['rent']) . ' per month';
        }
        if ($p['furnishing']) {
            $bits[] = PropertyForm::options('furnishing')[$p['furnishing']] ?? '';
        }
        if ($p['area_value'] !== null) {
            $bits[] = rtrim(rtrim((string) $p['area_value'], '0'), '.') . ' ' . (PropertyForm::options('area_unit')[(string) $p['area_unit']] ?? '');
        }
        $text = implode('. ', array_filter($bits)) . '. ' . trim((string) $p['title']);
        return mb_substr(trim($text), 0, 160);
    }

    /** schema.org structured data (spec 50); address limited to locality/city/pincode. */
    private static function jsonLd(array $p, string $path, array $photos, string $headline): string
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'RealEstateListing',
            'name'     => $p['title'] !== '' ? $p['title'] : $headline,
            'url'      => url($path),
            'datePosted' => $p['published_at'] ? gmdate('Y-m-d', strtotime($p['published_at'] . ' UTC')) : null,
            'image'    => array_map(static fn ($m) => media_url($m, 'lg'), array_slice($photos, 0, 5)) ?: null,
            'offers'   => $p['rent'] !== null ? [
                '@type' => 'Offer', 'price' => (int) $p['rent'], 'priceCurrency' => 'INR',
                'priceSpecification' => ['@type' => 'UnitPriceSpecification', 'price' => (int) $p['rent'], 'priceCurrency' => 'INR', 'unitText' => 'MONTH'],
            ] : null,
            'address'  => array_filter([
                '@type' => 'PostalAddress', 'addressLocality' => $p['locality_label'] ?: $p['city_name'],
                'addressRegion' => $p['city_state'] ?? null, 'postalCode' => $p['pincode'], 'addressCountry' => 'IN',
            ]),
        ];
        return (string) json_encode(array_filter($data, static fn ($v) => $v !== null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    /** 410 for a listing that was public once (paused, expired, deleted), 404 for anything else. */
    public static function gone(int $id): Response
    {
        $old = Database::one('SELECT p.purpose, p.city_id, c.name AS city_name FROM properties p LEFT JOIN cities c ON c.id = p.city_id
                               WHERE p.id = ? AND p.published_at IS NOT NULL', [$id]);
        if ($old === null) {
            return Response::html(View::render('errors/404', ['title' => 'Page not found']), 404);
        }
        return Response::html(View::render('public/listing-gone', [
            'title' => 'Listing no longer available', 'noindex' => true, 'old' => $old,
        ]), 410);
    }
}
