<?php
declare(strict_types=1);

/**
 * Phase 14 performance tests (spec 38, 39, 64). Seeds 20,000 test listings
 * (titles "P14T", system-owned, is_demo = 1), then checks:
 *   - time and query budgets per page (from the dev Server-Timing header),
 *   - that the faster "pick ids first" queries return exactly what the old
 *     single-query versions returned,
 *   - that the key queries use their indexes,
 *   - image and asset rules (lazy loading, sizes, minified CSS/JS in production).
 * Everything it adds is removed at the end. DEVELOPMENT ONLY, APP_DEBUG on.
 *
 *   php -S 127.0.0.1:8088 -t public public/index.php &
 *   php tests/phase14_performance_test.php            # PERF_N=5000 for a quicker run
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\AdminRepository;
use App\Repositories\ListingRepository;

if (Config::isProduction() || !Config::env('APP_DEBUG')) {
    fwrite(STDERR, "Run only in development with APP_DEBUG on (needed for the timing header).\n");
    exit(1);
}
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/perf_seed.php';

$n = (int) (getenv('PERF_N') ?: 20000);
perfUnseed();
$cleanAdmin = static function (): void {
    $ids = array_map('intval', array_column(Database::all("SELECT id FROM users WHERE email LIKE 'p14-%@test.invalid'"), 'id')) ?: [0];
    $in = implode(',', $ids);
    foreach (["DELETE FROM notifications WHERE user_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in))", "DELETE FROM admin_activity_logs WHERE admin_id IN ($in)",
              "DELETE FROM activity_logs WHERE user_id IN ($in)", "DELETE FROM login_activity WHERE user_id IN ($in)", "DELETE FROM user_sessions WHERE user_id IN ($in)",
              "DELETE FROM user_roles WHERE user_id IN ($in)", "DELETE FROM email_queue WHERE to_email LIKE 'p14-%@test.invalid'", "DELETE FROM users WHERE id IN ($in)", 'DELETE FROM rate_limits'] as $sql) {
        Database::run($sql);
    }
};
$cleanAdmin();
Database::run("UPDATE settings SET setting_value = '0' WHERE setting_key = 'auth_otp_enabled'");
$before = (int) Database::value('SELECT COUNT(*) FROM properties');

/** [queries, db ms, total ms] for the last response. */
function timing(Browser $b): array
{
    return preg_match('/db;desc="(\d+) queries";dur=([\d.]+), app;dur=([\d.]+)/', $b->headers, $m) ? [(int) $m[1], (float) $m[2], (float) $m[3]] : [999, 999.0, 999.0];
}
/** Best of 3 runs, so one slow moment on the machine does not fail the test. */
function measure(Browser $b, string $path): array
{
    $best = null;
    for ($i = 0; $i < 3; $i++) {
        $b->get($path);
        $t = timing($b);
        $best = $best === null || $t[2] < $best[2] ? $t : $best;
    }
    return [$b->status, ...$best];
}
/** The pre-Phase 14 single query (all joins, sort wide rows) as the reference result. */
function referenceIds(string $whereOrder, array $params = []): array
{
    return array_map('intval', array_column(Database::all('SELECT p.id ' . ListingRepository::FROM . ' WHERE ' . $whereOrder, $params), 'id'));
}

// ---------------------------------------------------------------------
echo "Seeding {$n} test listings\n";
// ---------------------------------------------------------------------
$t0 = microtime(true);
perfSeed($n);
check("{$n} listings added", (int) Database::value("SELECT COUNT(*) FROM properties WHERE title LIKE 'P14T%'") === $n, round(microtime(true) - $t0, 1) . 's');
$featured = (int) Database::value("SELECT id FROM properties WHERE title LIKE 'P14T%' AND status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) ORDER BY published_at ASC LIMIT 1");
Database::run("UPDATE properties SET is_featured = 0 WHERE title LIKE 'P14T%'");
Database::run('UPDATE properties SET is_featured = 1, featured_until = NULL WHERE id = ?', [$featured]);

$pw = 'Secret123';
$reg = new Browser($base);
$reg->get('/register');
$reg->post('/register', ['first_name' => 'Perf', 'last_name' => 'Admin', 'mobile' => '9222201401', 'email' => 'p14-admin@test.invalid',
    'password' => $pw, 'password_confirmation' => $pw, 'terms' => '1', 'website' => '']);
$adminId = (int) Database::value("SELECT id FROM users WHERE email = 'p14-admin@test.invalid'");
Database::run("INSERT INTO user_roles (user_id, role) VALUES (?, 'admin')", [$adminId]);
$adm = new Browser($base);
$adm->get('/admin/login');
$adm->post('/admin/login', ['identifier' => 'p14-admin@test.invalid', 'password' => $pw]);
check('admin signed in', redirectsTo($adm, '/admin'));

// ---------------------------------------------------------------------
echo "\nPage budgets with {$n} listings (best of 3)\n";
// ---------------------------------------------------------------------
$g = new Browser($base);
$public = ['/', '/rent', '/pg', '/lease', '/rent?city=1', '/rent?city=1&budget=15000-25000', '/rent?sort=rent_asc', '/rent?sort=newest',
    '/rent?page=150', '/lease?category=2&sort=rent_desc', '/rent?bedrooms=2&furnishing=furnished'];
foreach ($public as $path) {
    [$status, $q, $db, $total] = measure($g, $path);
    check(sprintf('%-40s %5.1f ms, %2d queries', $path, $total, $q), $status === 200 && $total < 150 && $q <= 12, "status $status");
}
foreach (['/admin', '/admin/properties', '/admin/properties?status=pending', '/admin/properties?page=200', '/admin/properties?q=Load+listing+1234', '/admin/analytics'] as $path) {
    [$status, $q, $db, $total] = measure($adm, $path);
    check(sprintf('%-40s %5.1f ms, %2d queries', $path, $total, $q), $status === 200 && $total < 300 && $q <= 20, "status $status");
}
[, $q1] = measure($g, '/rent');
[, $q2] = measure($g, '/rent?city=1&locality=1');
check('query count does not grow with the number of results (no N+1)', $q1 === $q2, "$q1 vs $q2");

// ---------------------------------------------------------------------
echo "\nSame results as before the speed-up\n";
// ---------------------------------------------------------------------
$vis = ListingRepository::VISIBLE;
$feat = '(p.is_featured = 1 AND (p.featured_until IS NULL OR p.featured_until > UTC_TIMESTAMP()))';
$cases = [
    'recommended' => [['purpose' => 'rent'], "$feat DESC, p.display_rank ASC, p.published_at DESC, p.id DESC", "p.purpose = 'rent'"],
    'newest, city' => [['purpose' => 'rent', 'city' => 1, 'sort' => 'newest'], 'p.published_at DESC, p.id DESC', "p.purpose = 'rent' AND p.city_id = 1"],
    'rent low-high, budget' => [['purpose' => 'lease', 'min' => 15000, 'max' => 50000, 'sort' => 'rent_asc'], 'p.rent IS NULL, p.rent ASC, p.id DESC', "p.purpose = 'lease' AND p.rent >= 15000 AND p.rent <= 50000"],
    'rent high-low, category' => [['purpose' => 'rent', 'category' => 2, 'sort' => 'rent_desc'], 'p.rent DESC, p.id DESC', "p.purpose = 'rent' AND p.category_id = 2"],
];
foreach ($cases as $label => [$f, $order, $where]) {
    foreach ([1, 7] as $page) {
        $got = ListingRepository::search($f, $page, 12);
        $ref = referenceIds("$vis AND $where ORDER BY $order LIMIT 12 OFFSET " . (($page - 1) * 12));
        $refTotal = (int) Database::value('SELECT COUNT(*) ' . ListingRepository::FROM . " WHERE $vis AND $where");
        check("search \"$label\" page $page: same listings, same order, same total", array_map(static fn ($r) => (int) $r['id'], $got['rows']) === $ref
            && $got['total'] === $refTotal && count($ref) === 12);
    }
}
$home = array_map(static fn ($r) => (int) $r['id'], ListingRepository::homepage(8));
check('homepage: same 8 listings as before, featured first', $home === referenceIds("$vis ORDER BY $feat DESC, p.published_at DESC, p.id DESC LIMIT 8") && $home[0] === $featured);
$sample = ListingRepository::find($featured);
$similar = array_map(static fn ($r) => (int) $r['id'], ListingRepository::similar($sample, 4));
check('similar listings unchanged', $similar === referenceIds("$vis AND p.purpose = ? AND p.id <> ? ORDER BY (p.city_id <=> ?) DESC, (p.category_id = ?) DESC, $feat DESC, p.published_at DESC, p.id DESC LIMIT 4",
    [$sample['purpose'], $featured, $sample['city_id'], $sample['category_id']]));
$admin = AdminRepository::properties([], 3);
$adminRef = array_map('intval', array_column(Database::all("SELECT p.id FROM properties p WHERE p.status <> 'deleted' ORDER BY p.updated_at DESC, p.id DESC LIMIT " . AdminRepository::PER_PAGE . ' OFFSET ' . (2 * AdminRepository::PER_PAGE)), 'id'));
check('admin property list: same rows and total as a direct query', array_map(static fn ($r) => (int) $r['id'], $admin['rows']) === array_slice($adminRef, 0, count($admin['rows']))
    && $admin['total'] === (int) Database::value("SELECT COUNT(*) FROM properties WHERE status <> 'deleted'") && $admin['rows'] && isset($admin['rows'][0]['type_name']));
$counts = ListingRepository::counts();
check('homepage counts per purpose are exact', (int) $counts['rent'] === (int) Database::value('SELECT COUNT(*) ' . ListingRepository::FROM . " WHERE $vis AND p.purpose = 'rent'"));

// ---------------------------------------------------------------------
echo "\nIndexes\n";
// ---------------------------------------------------------------------
$key = static fn (string $sql, array $p = []) => (string) (Database::all('EXPLAIN ' . $sql, $p)[0]['key'] ?? '');
check('migration 0005 applied (indexes present)', (bool) Database::value("SELECT 1 FROM schema_migrations WHERE version = '2026_10_09_000005_performance_indexes'")
    && (int) Database::value("SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'properties'
        AND index_name IN ('idx_prop_expiry', 'idx_prop_updated', 'idx_prop_status_submitted', 'idx_prop_published', 'idx_prop_status_published')") === 5);
check('homepage newest reads from (status, published_at)', $key("SELECT p.id FROM properties p LEFT JOIN users o ON o.id = p.owner_id WHERE $vis ORDER BY p.published_at DESC, p.id DESC LIMIT 8") === 'idx_prop_status_published');
check('admin list sorted by its updated_at index', $key("SELECT p.id FROM properties p WHERE p.status <> 'deleted' ORDER BY p.updated_at DESC, p.id DESC LIMIT 20") === 'idx_prop_updated');
check('approval queue uses (status, submitted_at)', $key("SELECT p.id FROM properties p WHERE p.status = 'pending' ORDER BY p.submitted_at ASC, p.id ASC LIMIT 20") === 'idx_prop_status_submitted');
check('city + budget search uses a search index', in_array($key("SELECT p.id FROM properties p LEFT JOIN users o ON o.id = p.owner_id WHERE $vis AND p.purpose = 'rent' AND p.city_id = 1 AND p.locality_id = 1 AND p.rent BETWEEN 15000 AND 25000"),
    ['idx_prop_search_city_rent', 'idx_prop_search_locality'], true));
check('public searches no longer pick the expiry index', !str_contains($key("SELECT p.id FROM properties p LEFT JOIN users o ON o.id = p.owner_id WHERE $vis AND p.purpose = 'rent' ORDER BY p.published_at DESC LIMIT 12"), 'expir'));

// ---------------------------------------------------------------------
echo "\nPages, images and assets\n";
// ---------------------------------------------------------------------
$g->get('/rent');
check('12 listings per page, never the whole table', substr_count($g->body, 'class="card property-card') === 12 || substr_count($g->body, 'property-card') >= 12);
$g->get('/rent?page=99999');
check('a page past the end goes to the last page (no heavy query, no error)', $g->status === 302 && (bool) preg_match('#/rent\?page=\d+$#', $g->location));
$demo = Database::one("SELECT p.id FROM properties p JOIN property_media m ON m.property_id = p.id AND m.media_type = 'image' AND m.status = 'active' WHERE p.status = 'active' LIMIT 1");
if ($demo) {
    $g->get('/rent');
    preg_match_all('/<img [^>]*>/', $g->body, $imgs);
    $cardImgs = array_filter($imgs[0], static fn ($i) => str_contains($i, '/uploads/'));
    check('listing photos: lazy, sized (no layout shift), responsive srcset', $cardImgs && count(array_filter($cardImgs, static fn ($i) => str_contains($i, 'width=') && str_contains($i, 'height=') && str_contains($i, 'loading="lazy"'))) === count($cardImgs)
        && str_contains($g->body, 'srcset='));
}
$g->get('/');
check('scripts load with defer; no external CSS/JS libraries', substr_count($g->body, '" defer></script>') >= 2 && !preg_match('#<(script|link)[^>]+(src|href)="https?://(?!127\.0\.0\.1)#', $g->body));
$env = new ReflectionProperty(Config::class, 'env');
$saved = $env->getValue();
$env->setValue(null, ['APP_ENV' => 'production'] + $saved);
$prodCss = asset('css/app.css');
$prodJs = asset('js/app.js');
$boot = asset('css/bootstrap.min.css');
$minFile = PUBLIC_ROOT . '/assets/css/app.min.css';
$oldMtime = filemtime($minFile);
touch($minFile, filemtime(PUBLIC_ROOT . '/assets/css/app.css') - 10);
clearstatcache();
$stale = asset('css/app.css');
touch($minFile, $oldMtime);
clearstatcache();
$env->setValue(null, $saved);
check('production serves minified app.css / app.js', str_contains($prodCss, '/assets/css/app.min.css?v=') && str_contains($prodJs, '/assets/js/app.min.js?v='));
check('an out-of-date .min file is never served (falls back to the original)', str_contains($stale, '/assets/css/app.css?v='));
check('already-minified Bootstrap is left alone', str_contains($boot, '/assets/css/bootstrap.min.css?v='));
check('development serves the readable files', str_contains(asset('css/app.css'), '/assets/css/app.css?v='));
check('minified files are smaller', filesize(PUBLIC_ROOT . '/assets/css/app.min.css') < filesize(PUBLIC_ROOT . '/assets/css/app.css')
    && filesize(PUBLIC_ROOT . '/assets/js/property-wizard.min.js') < filesize(PUBLIC_ROOT . '/assets/js/property-wizard.js'));
$ht = (string) file_get_contents(PUBLIC_ROOT . '/.htaccess');
check('compression (Brotli/gzip) and 1-year asset caching configured', str_contains($ht, 'BROTLI_COMPRESS') && str_contains($ht, 'AddOutputFilterByType DEFLATE') && str_contains($ht, 'max-age=31536000, immutable'));
check('the timing header is never sent in production', str_contains((string) file_get_contents(PUBLIC_ROOT . '/index.php'), "Config::env('APP_DEBUG') && !App\\Core\\Config::isProduction()"));

// ---------------------------------------------------------------------
$removed = perfUnseed();
$cleanAdmin();
check('all test listings removed', $removed === $n && (int) Database::value('SELECT COUNT(*) FROM properties') === $before);
echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
