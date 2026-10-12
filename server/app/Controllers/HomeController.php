<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\View;
use App\Repositories\ListingRepository;
use App\Services\PropertyForm;
use App\Services\SliderService;

final class HomeController
{
    /** Homepage: search card + featured/latest listings (the slider arrives in Phase 11). */
    public function index(Request $request): Response
    {
        $listings = ListingRepository::homepage(12);
        $siteName = (string) Settings::get('site_name', 'Homeforrent');
        // What Google shows for the site: editable in Admin → Settings → General (migration 0009).
        $description = trim((string) Settings::get('seo_home_description', ''))
            ?: 'Find flats, houses, PG, shops and offices for rent or lease in Surat, Ankleshwar, Bharuch, Vapi and across Gujarat. Listed direct by owners. Search free.';
        return Response::html(View::render('public/home', [
            'title'           => trim((string) Settings::get('seo_home_title', '')) ?: 'Rent, PG & Lease in Surat, Ankleshwar, Bharuch, Vapi',
            'metaDescription' => $description,
            'canonical'       => url('/'),
            'og'              => ['type' => 'website', 'image' => url('assets/img/og-image.jpg')],
            'jsonLd'          => self::jsonLd($siteName, $description),
            'slides'          => SliderService::live(),
            'cities'          => PropertyForm::cities(),
            'localities'      => PropertyForm::localities(),
            'categories'      => Database::all('SELECT id, code, name FROM property_categories WHERE is_active = 1 ORDER BY sort_order'),
            'budgets'         => ListingRepository::budgets(),
            'listings'        => $listings,
            'shortlisted'     => ListingRepository::shortlistedIds(Auth::id(), array_map(static fn ($r) => (int) $r['id'], $listings)),
            'scripts'         => ['js/listings.js'],
        ]));
    }

    /** /page/{slug}: About, Terms, Privacy, Cookie policy from cms_pages. */
    public function page(Request $request): Response
    {
        $page = Database::one('SELECT * FROM cms_pages WHERE slug = ? AND is_published = 1', [strtolower($request->params['slug'])]);
        if ($page === null) {
            return Response::html(View::render('errors/404', ['title' => 'Page not found']), 404);
        }
        return Response::html(View::render('public/page', [
            'title' => $page['title'], 'page' => $page, 'metaDescription' => (string) $page['meta_description'],
        ]));
    }

    /**
     * Deployment check: confirms PHP version, extensions, DB connection and
     * migration state. Details are only shown outside production.
     */
    public function health(Request $request): Response
    {
        $checks = [
            'php'        => PHP_VERSION,
            'pdo_mysql'  => extension_loaded('pdo_mysql'),
            'gd'         => extension_loaded('gd'),
            'gd_webp'    => function_exists('imagewebp'),
            'fileinfo'   => extension_loaded('fileinfo'),
            'mbstring'   => extension_loaded('mbstring'),
            'storage_writable' => is_writable(APP_ROOT . '/storage/logs'),
            'uploads_writable' => is_writable(PUBLIC_ROOT . '/uploads'),
        ];
        try {
            $checks['database'] = (bool) Database::value('SELECT 1');
            $checks['migrations'] = (int) Database::value('SELECT COUNT(*) FROM schema_migrations');
        } catch (\Throwable) {
            $checks['database'] = false;
        }
        $ok = $checks['database'] && $checks['pdo_mysql'] && $checks['gd'] && $checks['fileinfo'];

        return Config::isProduction()
            ? Response::json(['ok' => $ok], $ok ? 200 : 503)
            : Response::json(['ok' => $ok, 'checks' => $checks], $ok ? 200 : 503);
    }

    /** WebSite + Organization data so Google shows the site name and logo. */
    private static function jsonLd(string $siteName, string $description): string
    {
        $org = array_filter([
            '@type' => 'Organization', 'name' => $siteName, 'url' => url('/'),
            'logo' => url('assets/img/icon-192.png'),
            'email' => (string) Settings::get('support_email', '') ?: null,
            'telephone' => (string) Settings::get('support_phone', '') ?: null,
        ]);
        $data = ['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'WebSite', 'name' => $siteName, 'url' => url('/'), 'description' => $description, 'inLanguage' => 'en-IN'],
            $org,
        ]];
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
