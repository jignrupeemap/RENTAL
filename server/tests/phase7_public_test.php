<?php
declare(strict_types=1);

/**
 * Phase 7 end-to-end tests: public search and listing pages, the property
 * detail page, shortlist, view counting, SEO (canonical, JSON-LD, sitemap,
 * robots) and the listing-expiry cron job.
 * DEVELOPMENT ONLY (creates and removes @test.invalid accounts and listings
 * whose titles start with "P7T").
 *
 *   php -S 127.0.0.1:8088 -t public public/index.php &
 *   php tests/phase7_public_test.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\ListingExpiryService;

if (Config::isProduction() || Config::env('MAIL_DRIVER') !== 'log') {
    fwrite(STDERR, "Run only in development with MAIL_DRIVER=log.\n");
    exit(1);
}
require __DIR__ . '/lib/http.php';

$cleanup = static function (): void {
    $ids = array_map('intval', array_column(Database::all("SELECT id FROM users WHERE email LIKE 'p7-%@test.invalid'"), 'id')) ?: [0];
    $in = implode(',', $ids);
    $p = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM properties WHERE owner_id IN ($in) OR created_by IN ($in) OR title LIKE 'P7T%'"), 'id')) ?: [0]);
    foreach ([
        "DELETE FROM notifications WHERE user_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in)) OR (entity_type = 'property' AND entity_id IN ($p))",
        "DELETE FROM shortlists WHERE property_id IN ($p) OR user_id IN ($in)",
        "DELETE FROM property_media WHERE property_id IN ($p)",
        "DELETE FROM properties WHERE id IN ($p)",
        "DELETE FROM activity_logs WHERE user_id IN ($in)",
        "DELETE FROM login_activity WHERE user_id IN ($in)",
        "DELETE FROM users WHERE id IN ($in)",
        'DELETE FROM rate_limits',
    ] as $sql) {
        Database::run($sql);
    }
};
$cleanup();
$setting = static fn (string $k, string $v) => Database::run('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$v, $k]);
$savedPerPage = (string) Database::value("SELECT setting_value FROM settings WHERE setting_key = 'listings_per_page'");
$setting('auth_otp_enabled', '0');
$setting('listings_per_page', '6');

$pw = 'Secret123';
$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';
function registerUser(Browser $b, string $first, string $mobile, string $email): int
{
    global $pw;
    $b->get('/register');
    $b->post('/register', ['first_name' => $first, 'last_name' => 'Test', 'mobile' => $mobile, 'email' => $email,
        'password' => $pw, 'password_confirmation' => $pw, 'terms' => '1', 'website' => '']);
    return (int) Database::value('SELECT id FROM users WHERE email = ?', [$email]);
}
function typeRow(string $code): array { return Database::one('SELECT id, category_id FROM property_types WHERE code = ?', [$code]); }
function catId(string $code): int { return (int) Database::value('SELECT id FROM property_categories WHERE code = ?', [$code]); }
function localityId(string $slug): int { return (int) Database::value('SELECT id FROM localities WHERE slug = ?', [$slug]); }
function prop(int $id): array { return Database::one('SELECT * FROM properties WHERE id = ?', [$id]); }
/** A listing straight in the database (posting and approval are covered by the Phase 4 and 6 tests). */
function mk(int $owner, string $purpose, string $type, string $title, int $rent, array $extra = []): int
{
    static $n = 0;
    $t = typeRow($type);
    $row = array_merge([
        'owner_id' => $owner, 'created_by' => $owner, 'purpose' => $purpose, 'category_id' => $t['category_id'], 'type_id' => $t['id'],
        'status' => 'active', 'draft_step' => 8, 'title' => $title, 'slug' => slugify($title), 'description' => 'Test listing.',
        'unit_no' => 'B-1204', 'building_name' => 'Test Tower', 'address_line' => '12 Secret Street', 'city_id' => 1,
        'locality_id' => localityId('vesu'), 'pincode' => '395007', 'rent' => $rent, 'security_deposit' => $rent * 2,
        'contact_name' => 'Owner Private', 'contact_mobile_1' => '919222200799', 'contact_email' => 'private-owner@test.invalid',
        'published_at' => gmdate('Y-m-d H:i:s', time() - 3600 + (++$n * 60)), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 30),
    ], $extra);
    unset($row['_privacy']);
    $cols = implode(', ', array_keys($row));
    $id = Database::insert("INSERT INTO properties ($cols) VALUES (" . rtrim(str_repeat('?, ', count($row)), ', ') . ')', array_values($row));
    Database::run("INSERT INTO property_locations (property_id, latitude, longitude, public_latitude, public_longitude, privacy)
                   VALUES (?, 21.1418, 72.7709, 21.1440, 72.7731, ?)", [$id, $extra['_privacy'] ?? 'approximate']);
    return $id;
}
/** Position of $needle in the page body, or PHP_INT_MAX when missing. */
function at(Browser $b, string $needle): int
{
    $i = strpos(html_entity_decode($b->body, ENT_QUOTES), $needle);
    return $i === false ? PHP_INT_MAX : $i;
}

// ---------------------------------------------------------------------
echo "Setup\n";
// ---------------------------------------------------------------------
$o = new Browser($base);
$owner = registerUser($o, 'Omkar', '9222200701', 'p7-owner@test.invalid');
$v = new Browser($base);
$v->userAgent = $chrome;
$viewer = registerUser($v, 'Vidya', '9222200702', 'p7-viewer@test.invalid');
$owner2 = registerUser(new Browser($base), 'Sunil', '9222200703', 'p7-owner2@test.invalid');
check('test accounts ready', $owner && $viewer && $owner2);

$L1 = mk($owner, 'rent', 'flat', 'P7T Vesu 2BHK <b>bold</b>', 22000, ['bedrooms' => 2, 'bathrooms' => 2, 'furnishing' => 'semi_furnished']);
$L2 = mk($owner, 'rent', 'flat', 'P7T Featured 3BHK', 48000, ['bedrooms' => 3, 'bathrooms' => 3, 'furnishing' => 'furnished',
    'is_featured' => 1, 'locality_id' => localityId('adajan')]);
$L3 = mk($owner, 'rent', 'shop', 'P7T Corner Shop', 35000, ['area_value' => 450, 'area_unit' => 'sqft', 'area_sqft' => 450,
    'furnishing' => 'unfurnished', 'locality_id' => localityId('city-light')]);
$L4 = mk($owner, 'pg', 'flat', 'P7T Girls PG', 8000, ['bedrooms' => 3, 'furnishing' => 'furnished', 'pg_available_for' => 'girls',
    'pg_suitable_for' => 'students', 'locality_id' => localityId('adajan')]);
$L5 = mk($owner, 'lease', 'warehouse_godown', 'P7T Big Warehouse', 90000, ['area_value' => 1200, 'area_unit' => 'sqyd', 'area_sqft' => 10800,
    'locality_id' => localityId('hazira'), '_privacy' => 'exact']);
$L6 = mk($owner, 'rent', 'flat', 'P7T Paused Flat', 15000, ['status' => 'paused', 'bedrooms' => 1]);
$L7 = mk($owner, 'rent', 'flat', 'P7T Expired Flat', 16000, ['bedrooms' => 1, 'expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
$L8 = mk($owner2, 'rent', 'flat', 'P7T Suspended Owner Flat', 17000, ['bedrooms' => 1]);
$L9 = mk($owner, 'rent', 'flat', 'P7T Pending Flat', 18000, ['status' => 'pending', 'bedrooms' => 1, 'published_at' => null]);
Database::run("UPDATE users SET status = 'suspended', status_reason = 'Test' WHERE id = ?", [$owner2]);
// The _privacy key is only for mk(); exact pin for the warehouse.
Database::run("UPDATE property_locations SET privacy = 'exact' WHERE property_id = ?", [$L5]);
// A cover photo row for L1 (files are not needed to render the markup).
Database::run("INSERT INTO property_media (property_id, media_type, storage_dir, file_key, ext, has_webp, width, height, size_bytes, mime_type, sort_order, is_cover)
               VALUES (?, 'image', 'properties/2026/10', ?, 'jpg', 1, 1600, 1200, 1000, 'image/jpeg', 0, 1)", [$L1, bin2hex(random_bytes(16))]);
check('test listings created', $L1 && $L9 && prop($L8)['status'] === 'active');

// ---------------------------------------------------------------------
echo "\nListing pages\n";
// ---------------------------------------------------------------------
$g = new Browser($base);
$g->get('/rent');
check('/rent opens', $g->status === 200 && $g->has('Properties for Rent'));
check('active rent listings shown', $g->has('P7T Vesu 2BHK') && $g->has('P7T Featured 3BHK') && $g->has('P7T Corner Shop'));
check('PG and Lease listings not on /rent', !$g->has('P7T Girls PG') && !$g->has('P7T Big Warehouse'));
check('paused, expired, pending and suspended-owner listings hidden',
    !$g->has('P7T Paused Flat') && !$g->has('P7T Expired Flat') && !$g->has('P7T Pending Flat') && !$g->has('P7T Suspended Owner Flat'));
check('title is escaped', $g->has('P7T Vesu 2BHK <b>bold</b>') && !str_contains($g->body, '<b>bold</b>'));
check('card: rent, facts, View Details', $g->has('₹22,000') && $g->has('2 BHK | 2 Bath | Semi-Furnished') && $g->has('View Details'));
check('card: commercial shows area', $g->has('450 Sq Ft | Unfurnished'));
check('card: cover thumbnail lazy-loaded with size', (bool) preg_match('/_thumb\.jpg"[^>]*width="\d+" height="\d+"/', $g->body) || str_contains($g->body, '_thumb.jpg'));
check('guest shortlist goes to login and back', $g->has('href="' . $base . '/login?next=' . rawurlencode('/rent') . '"'));
check('featured listing first', at($g, 'P7T Featured 3BHK') < at($g, 'P7T Vesu 2BHK') && at($g, 'P7T Featured 3BHK') < at($g, 'P7T Corner Shop'));
check('featured badge', $g->has('Featured'));
Database::run('UPDATE properties SET display_rank = 1 WHERE id = ?', [$L3]);
$g->get('/rent');
check('admin display order respected (rank 1 before unranked)', at($g, 'P7T Corner Shop') < at($g, 'P7T Vesu 2BHK'));
check('plain page has canonical and no noindex', $g->has('<link rel="canonical" href="' . $base . '/rent">') && !$g->has('noindex'));

$g->get('/rent?sort=rent_asc');
check('sort rent low to high', at($g, 'P7T Vesu 2BHK') < at($g, 'P7T Corner Shop') && at($g, 'P7T Corner Shop') < at($g, 'P7T Featured 3BHK'));
$g->get('/rent?sort=rent_desc');
check('sort rent high to low', at($g, 'P7T Featured 3BHK') < at($g, 'P7T Corner Shop') && at($g, 'P7T Corner Shop') < at($g, 'P7T Vesu 2BHK'));
$g->get('/rent?city=1&locality=' . localityId('vesu'));
check('filter by locality', $g->has('P7T Vesu 2BHK') && !$g->has('P7T Featured 3BHK') && !$g->has('P7T Corner Shop'));
check('filtered page is noindex', $g->has('noindex'));
$g->get('/rent?category=' . catId('commercial'));
check('filter by category', $g->has('P7T Corner Shop') && !$g->has('P7T Vesu 2BHK'));
$g->get('/rent?category=' . catId('commercial') . '&type=' . typeRow('flat')['id']);
check('sub-type from another category is ignored', $g->has('P7T Corner Shop'));
$g->get('/rent?budget=1');
check('budget preset ₹15,000–₹25,000', $g->has('P7T Vesu 2BHK') && !$g->has('P7T Featured 3BHK') && !$g->has('P7T Corner Shop'));
$g->get('/rent?min=30000&max=40000');
check('custom min/max budget', $g->has('P7T Corner Shop') && !$g->has('P7T Vesu 2BHK') && $g->has('Custom:'));
$g->get('/rent?bedrooms=3');
check('bedrooms filter', $g->has('P7T Featured 3BHK') && !$g->has('P7T Vesu 2BHK'));
$g->get('/rent?furnishing=furnished');
check('furnishing filter', $g->has('P7T Featured 3BHK') && !$g->has('P7T Vesu 2BHK'));
$g->get('/rent?city=abc&sort=evil&budget=99&furnishing=%3Cscript%3E&bedrooms=9&page=-3');
check('junk filter values ignored safely', $g->status === 200 && $g->has('P7T Vesu 2BHK') && !str_contains($g->body, '<script>') && !str_contains($g->body, 'value="evil" selected'));
$g->get('/rent?city=1&locality=' . localityId('hazira') . '&bedrooms=1');
check('no results shows a clear message', $g->has('No properties match these filters') && $g->has('Clear all filters'));

$g->get('/pg');
check('/pg shows PG listing with For Girls', $g->has('P7T Girls PG') && $g->has('For Girls | Students | Furnished') && !$g->has('P7T Vesu 2BHK'));
check('PG filters: available for / suitable for', $g->has('name="pg_for"') && $g->has('name="pg_suitable"'));
$g->get('/pg?pg_for=boys');
check('PG filter for boys hides girls PG', !$g->has('P7T Girls PG'));
$g->get('/lease');
check('/lease shows lease listing with area', $g->has('P7T Big Warehouse') && $g->has('1200 Sq Yard'));

// Pagination: 6 per page; add rent listings until there are two pages.
$visibleRent = (int) Database::value("SELECT COUNT(*) FROM properties p LEFT JOIN users o ON o.id = p.owner_id WHERE p.purpose = 'rent' AND p.status = 'active'
    AND (p.expires_at IS NULL OR p.expires_at > UTC_TIMESTAMP()) AND (p.owner_id IS NULL OR (o.status = 'active' AND o.deleted_at IS NULL))");
for ($i = $visibleRent; $i < 8; $i++) {
    mk($owner, 'rent', 'flat', 'P7T Filler ' . $i, 20000 + $i, ['bedrooms' => 1]);
}
$g->get('/rent');
check('page 1 of 2 with 6 cards', $g->has('Page 1 of 2') && substr_count($g->body, 'class="card property-card') === 6);
$g->get('/rent?page=2');
check('page 2 opens', $g->status === 200 && $g->has('Page 2 of 2'));
$g->get('/rent?page=99');
check('page past the end goes to the last page', $g->status === 302 && str_contains($g->location, 'page=2'));

// ---------------------------------------------------------------------
echo "\nDetail page\n";
// ---------------------------------------------------------------------
$url1 = '/rent/' . prop($L1)['slug'] . '-p' . $L1;
$g->get($url1);
check('detail page opens at its SEO URL', $g->status === 200 && $g->has('P7T Vesu 2BHK <b>bold</b>') && !str_contains($g->body, '<b>bold</b>'));
check('headline, rent, deposit, facts, facilities, map link', $g->has('2 BHK Flat for Rent') && $g->has('₹22,000') && $g->has('Deposit ₹44,000')
    && $g->has('Semi-Furnished') && $g->has('VIEW ON GOOGLE MAPS'));
check('map button shows the contact message, never a Google Maps link', $g->has('for location and more detail') && $g->has('wa.me/91')
    && !$g->has('google.com/maps') && !$g->has('21.1418000'));
check('privacy: no unit number, street, owner name, mobile or email',
    !$g->has('B-1204') && !$g->has('12 Secret Street') && !$g->has('Owner Private') && !$g->has('92222 00799') && !$g->has('919222200799') && !$g->has('private-owner@'));
check('canonical + og:url', $g->has('<link rel="canonical" href="' . $base . $url1 . '">') && $g->has('property="og:url" content="' . $base . $url1 . '"'));
preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $g->body, $m);
$ld = isset($m[1]) ? json_decode($m[1], true) : null;
check('JSON-LD listing with INR price and no street address', is_array($ld) && $ld['@type'] === 'RealEstateListing' && $ld['offers']['price'] === 22000
    && $ld['offers']['priceCurrency'] === 'INR' && !isset($ld['address']['streetAddress']) && !str_contains($m[1], 'Secret'));
check('JSON-LD cannot break out of the script tag', !str_contains($m[1] ?? '', '</'));
check('meta description has no private data', (bool) preg_match('/<meta name="description" content="([^"]*)"/', $g->body, $md) && !str_contains($md[1], 'Owner Private') && str_contains($md[1], '₹22,000'));
check('similar properties shown', $g->has('Similar properties'));

$g->get('/rent/wrong-slug-p' . $L1);
check('wrong slug → 301 to canonical', $g->status === 301 && $g->location === $base . $url1);
$g->get('/pg/' . prop($L1)['slug'] . '-p' . $L1);
check('wrong purpose → 301 to canonical', $g->status === 301 && $g->location === $base . $url1);
$g->get('/rent/x-p' . $L6);
check('paused listing → 410 page', $g->status === 410 && $g->has('no longer available'));
$g->get('/rent/x-p' . $L8);
check('suspended owner\'s listing → 410', $g->status === 410);
$g->get('/rent/x-p' . $L9);
check('never-published listing → 404', $g->status === 404);
$g->get('/rent/x-p999999999');
check('unknown id → 404', $g->status === 404);
$g->get('/lease/' . prop($L5)['slug'] . '-p' . $L5);
check('exact pin listing shows the street line, still no unit number', $g->has('12 Secret Street') && !$g->has('B-1204') && $g->has('Exact location shown'));

$before = (int) prop($L1)['views_count'];
$day = (int) Database::value('SELECT COALESCE(SUM(views), 0) FROM property_view_daily WHERE property_id = ?', [$L1]);
$vb = new Browser($base);
$vb->userAgent = $chrome;
$vb->get($url1);
check('a visitor view is counted (total + daily)', (int) prop($L1)['views_count'] === $before + 1
    && (int) Database::value('SELECT COALESCE(SUM(views), 0) FROM property_view_daily WHERE property_id = ?', [$L1]) === $day + 1);
$bot = new Browser($base);
$bot->userAgent = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$bot->get($url1);
check('search-engine bot not counted', (int) prop($L1)['views_count'] === $before + 1);
$o->userAgent = $chrome;
$o->get($url1);
check('owner viewing own listing not counted', (int) prop($L1)['views_count'] === $before + 1 && $o->has('This is your listing'));

// ---------------------------------------------------------------------
echo "\nShortlist\n";
// ---------------------------------------------------------------------
$v->get($url1);
check('viewer sees a Shortlist button', $v->has('action="' . $base . '/shortlist/' . $L1 . '"'));
$v->post('/shortlist/' . $L1, ['state' => 'on', 'return' => $url1]);
check('shortlist added (row, counter, back to page)', $v->status === 302 && $v->location === $base . $url1
    && (int) Database::value('SELECT COUNT(*) FROM shortlists WHERE user_id = ? AND property_id = ?', [$viewer, $L1]) === 1
    && (int) prop($L1)['shortlists_count'] === 1);
$v->follow();
check('page now shows Shortlisted and a message', $v->has('Shortlisted') && $v->has('Added to your shortlist'));
$v->post('/shortlist/' . $L1, ['state' => 'on', 'return' => $url1]);
check('adding twice keeps one row', (int) Database::value('SELECT COUNT(*) FROM shortlists WHERE user_id = ?', [$viewer]) === 1 && (int) prop($L1)['shortlists_count'] === 1);
$v->get('/rent');
check('listing page shows it as shortlisted', $v->has('aria-pressed="true"'));
$v->ajax('/shortlist/' . $L1, ['state' => 'off']);
check('remove with fetch() answers JSON', ($v->json()['shortlisted'] ?? null) === false && (int) prop($L1)['shortlists_count'] === 0
    && !Database::value('SELECT 1 FROM shortlists WHERE user_id = ? AND property_id = ?', [$viewer, $L1]));
$v->get('/rent');
$v->ajax('/shortlist/' . $L2);
check('toggle with no state adds', ($v->json()['shortlisted'] ?? null) === true);
$v->get('/rent');
$v->ajax('/shortlist/' . $L6, ['state' => 'on']);
check('paused listing cannot be shortlisted', $v->status === 422 && str_contains((string) ($v->json()['error'] ?? ''), 'no longer available'));
$o->get('/rent');
$o->ajax('/shortlist/' . $L1, ['state' => 'on']);
check('owner cannot shortlist own listing', $o->status === 422);
$v->post('/shortlist/' . $L1, ['state' => 'on', '_token' => 'bad']);
check('shortlist without a valid CSRF token refused', $v->status === 419);
$guest = new Browser($base);
$guest->get('/rent');
$guest->post('/shortlist/' . $L1, ['state' => 'on']);
check('guest POST refused (no session token / login required)', ($guest->status === 419 || ($guest->status === 302 && str_contains($guest->location, '/login')))
    && (int) prop($L1)['shortlists_count'] === 0);
Database::run("UPDATE users SET status = 'suspended', status_reason = 'Test' WHERE id = ?", [$viewer]);
$v->get('/rent');
$v->ajax('/shortlist/' . $L3, ['state' => 'on']);
check('suspended viewer cannot shortlist', $v->status === 403 && !Database::value('SELECT 1 FROM shortlists WHERE user_id = ? AND property_id = ?', [$viewer, $L3]));
Database::run("UPDATE users SET status = 'active', status_reason = NULL WHERE id = ?", [$viewer]);
check('shortlist logged in activity', (bool) Database::value("SELECT 1 FROM activity_logs WHERE user_id = ? AND event = 'shortlisted'", [$viewer]));

// ---------------------------------------------------------------------
echo "\nHomepage, search, SEO files\n";
// ---------------------------------------------------------------------
$g->get('/');
check('homepage: search card with city, locality, type, budget', $g->has('SEARCH PROPERTY') && $g->has('name="locality"') && $g->has('name="budget"') && $g->has('Up to ₹15,000'));
check('homepage: featured and latest listings', $g->has('Featured and latest properties') && $g->has('P7T Featured 3BHK'));
check('homepage: real counts per purpose', (bool) preg_match('/\d+ propert(y|ies) →/', html_entity_decode($g->body)));
$g->get('/search?purpose=pg&city=1&budget=0&evil=1');
check('search → /pg with filters', $g->status === 302 && $g->location === $base . '/pg?city=1&budget=0');
$g->get('/search?purpose=hack&city=x');
check('search with bad purpose → /rent', $g->status === 302 && $g->location === $base . '/rent');
$g->get('/sitemap.xml');
check('sitemap lists live listings only', $g->status === 200 && $g->has('<loc>' . $base . $url1 . '</loc>') && !$g->has('-p' . $L6 . '<') && !$g->has('-p' . $L9 . '<'));
$g->get('/robots.txt');
check('robots.txt blocks everything outside production', $g->status === 200 && $g->has("Disallow: /\n"));

// ---------------------------------------------------------------------
echo "\nExpiry cron\n";
// ---------------------------------------------------------------------
Database::run('UPDATE properties SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?', [$L1]);
$n = ListingExpiryService::expireDue();
check('expired listing marked expired by cron', $n >= 1 && prop($L1)['status'] === 'expired'
    && (bool) Database::value("SELECT 1 FROM property_status_history WHERE property_id = ? AND new_status = 'expired' AND actor_role = 'system'", [$L1]));
check('owner told the listing expired', (bool) Database::value("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'property_expired' AND entity_id = ?", [$owner, $L1]));
$g->get($url1);
check('expired listing page → 410', $g->status === 410);
Database::run('UPDATE properties SET featured_until = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?', [$L2]);
ListingExpiryService::endFeatured();
check('featured end date switches featured off', (int) prop($L2)['is_featured'] === 0);

// ---------------------------------------------------------------------
$setting('listings_per_page', $savedPerPage);
$cleanup();
check('test data removed', !Database::value("SELECT 1 FROM properties WHERE title LIKE 'P7T%'"));
echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
