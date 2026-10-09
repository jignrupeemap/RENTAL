<?php
declare(strict_types=1);

/**
 * Phase 12 end-to-end tests: Admin → Analytics (spec 33, 58) and the owner
 * Performance page (spec 6). Every number on the pages is compared with a
 * direct COUNT/SUM on the database, so the tests prove the figures are real.
 * DEVELOPMENT ONLY (creates and removes @test.invalid accounts and listings
 * whose titles start with "P12T").
 *
 *   php -S 127.0.0.1:8088 -t public public/index.php &
 *   php tests/phase12_analytics_test.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\AnalyticsRepository;

if (Config::isProduction() || Config::env('MAIL_DRIVER') !== 'log') {
    fwrite(STDERR, "Run only in development with MAIL_DRIVER=log.\n");
    exit(1);
}
require __DIR__ . '/lib/http.php';

$cleanup = static function (): void {
    $ids = array_map('intval', array_column(Database::all("SELECT id FROM users WHERE email LIKE 'p12-%@test.invalid'"), 'id')) ?: [0];
    $in = implode(',', $ids);
    $p = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM properties WHERE owner_id IN ($in) OR created_by IN ($in) OR title LIKE 'P12T%'"), 'id')) ?: [0]);
    $b = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM bookings WHERE property_id IN ($p) OR user_id IN ($in)"), 'id')) ?: [0]);
    $q = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM enquiries WHERE property_id IN ($p) OR user_id IN ($in)"), 'id')) ?: [0]);
    $r = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM rental_change_history WHERE property_id IN ($p)"), 'id')) ?: [0]);
    foreach ([
        "DELETE FROM notifications WHERE user_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in)) OR (entity_type = 'booking' AND entity_id IN ($b))
            OR (entity_type = 'enquiry' AND entity_id IN ($q)) OR (entity_type = 'rent_change' AND entity_id IN ($r)) OR (entity_type = 'property' AND entity_id IN ($p))",
        "DELETE FROM booking_status_history WHERE booking_id IN ($b)",
        "DELETE FROM bookings WHERE id IN ($b)",
        "DELETE FROM contact_history WHERE enquiry_id IN ($q) OR user_id IN ($in) OR property_id IN ($p)",
        "DELETE FROM enquiries WHERE id IN ($q)",
        "DELETE FROM shortlists WHERE property_id IN ($p) OR user_id IN ($in)",
        "UPDATE properties SET pending_rent_change_id = NULL WHERE id IN ($p)",
        "DELETE FROM rental_change_history WHERE id IN ($r)",
        "DELETE FROM property_status_history WHERE property_id IN ($p)",
        "DELETE FROM property_locations WHERE property_id IN ($p)",
        "DELETE FROM properties WHERE id IN ($p)",
        "DELETE FROM admin_activity_logs WHERE admin_id IN ($in)",
        "DELETE FROM activity_logs WHERE user_id IN ($in)",
        "DELETE FROM login_activity WHERE user_id IN ($in)",
        "DELETE FROM user_sessions WHERE user_id IN ($in)",
        "DELETE FROM user_roles WHERE user_id IN ($in)",
        "DELETE FROM email_queue WHERE to_email LIKE 'p12-%@test.invalid'",
        "DELETE FROM users WHERE id IN ($in)",
        'DELETE FROM rate_limits',
    ] as $sql) {
        Database::run($sql);
    }
};
$cleanup();
$setting = static fn (string $k, string $v) => Database::run('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$v, $k]);
$saved = array_column(Database::all("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('email_notifications_enabled','rent_change_approval_mode','reapprove_on_owner_edit')"), 'setting_value', 'setting_key');
$setting('auth_otp_enabled', '0');
$setting('email_notifications_enabled', '0');
$setting('rent_change_approval_mode', 'increase_only');
$setting('reapprove_on_owner_edit', '0');

$pw = 'Secret123';
function registerUser(Browser $b, string $first, string $mobile, string $email): int
{
    global $pw;
    $b->get('/register');
    $b->post('/register', ['first_name' => $first, 'last_name' => 'Test', 'mobile' => $mobile, 'email' => $email,
        'password' => $pw, 'password_confirmation' => $pw, 'terms' => '1', 'website' => '']);
    return (int) Database::value('SELECT id FROM users WHERE email = ?', [$email]);
}
function mk(int $owner, string $title, int $rent): int
{
    $t = Database::one("SELECT id, category_id FROM property_types WHERE code = 'flat'");
    $row = [
        'owner_id' => $owner, 'created_by' => $owner, 'purpose' => 'rent', 'category_id' => $t['category_id'], 'type_id' => $t['id'],
        'status' => 'active', 'draft_step' => 8, 'title' => $title, 'slug' => slugify($title), 'description' => 'Test listing.',
        'unit_no' => 'A-1', 'building_name' => 'Test Heights', 'address_line' => 'Ring Road',
        'city_id' => 1, 'locality_id' => (int) Database::value("SELECT id FROM localities WHERE slug = 'vesu'"), 'pincode' => '395007',
        'rent' => $rent, 'security_deposit' => 40000, 'bedrooms' => 2, 'bathrooms' => 2, 'furnishing' => 'semi_furnished',
        'available_from' => gmdate('Y-m-d'), 'contact_name' => 'Owner Test', 'contact_mobile_1' => '919222201201',
        'published_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'approved_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 30),
    ];
    $cols = implode(', ', array_keys($row));
    $id = Database::insert("INSERT INTO properties ($cols) VALUES (" . rtrim(str_repeat('?, ', count($row)), ', ') . ')', array_values($row));
    Database::run("INSERT INTO property_locations (property_id, latitude, longitude, public_latitude, public_longitude, privacy)
                   VALUES (?, 21.1418, 72.7709, 21.1440, 72.7731, 'approximate')", [$id]);
    return $id;
}
function prop(int $id): array { return Database::one('SELECT * FROM properties WHERE id = ?', [$id]); }
/** India date N days from today, Y-m-d. */
function istDay(int $offset): string { return gmdate('Y-m-d', time() + 19800 + 86400 * $offset); }
/** UTC [from, to) for whole India days. */
function istRange(string $fromDay, string $toDay): array
{
    return [gmdate('Y-m-d H:i:s', strtotime($fromDay . ' 00:00:00 UTC') - 19800), gmdate('Y-m-d H:i:s', strtotime($toDay . ' 00:00:00 UTC') + 86400 - 19800)];
}
/** The number printed in a stat card after its label. */
/** Number of chart columns on a page. */
function bars(string $html): int { return preg_match_all('/class="col-chart-bar( is-zero)?"/', $html); }
function cardValue(string $html, string $label): ?int
{
    return preg_match('#' . preg_quote($label, '#') . '</div><div class="fs-4 fw-semibold">([\d,]+)</div>#', $html, $m) ? (int) str_replace(',', '', $m[1]) : null;
}

// ---------------------------------------------------------------------
echo "Setup\n";
// ---------------------------------------------------------------------
$o = new Browser($base);
$owner = registerUser($o, 'Rahul', '9222201201', 'p12-owner@test.invalid');
$o2 = new Browser($base);
$owner2 = registerUser($o2, 'Bina', '9222201202', 'p12-owner2@test.invalid');
$v = new Browser($base);
$viewer = registerUser($v, 'Vidya', '9222201203', 'p12-viewer@test.invalid');
$adminId = registerUser(new Browser($base), 'Asha', '9222201204', 'p12-admin@test.invalid');
Database::run("INSERT INTO user_roles (user_id, role) VALUES (?, 'admin')", [$adminId]);
Database::run("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'owner'), (?, 'owner')", [$owner, $owner2]);
$L1 = mk($owner, 'P12T Vesu <b>2BHK</b>', 20000);
$L2 = mk($owner2, 'P12T Adajan Shop', 30000);
$adm = new Browser($base);
$adm->get('/admin/login');
$adm->post('/admin/login', ['identifier' => 'p12-admin@test.invalid', 'password' => $pw]);
check('accounts, listings and admin session ready', $owner && $owner2 && $viewer && $L1 && $L2 && redirectsTo($adm, '/admin'));

// Real activity: 3 counted views, a shortlist, a contact request and a visit request on L1.
$v->userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126 Safari/537.36';
$url1 = property_url(prop($L1));
for ($i = 0; $i < 3; $i++) {
    $v->get($url1);
}
$v->post('/shortlist/' . $L1, ['state' => 'on', 'return' => $url1]);
$v->get('/property/' . $L1 . '/contact');
$v->post('/property/' . $L1 . '/contact', ['first_name' => 'Vidya', 'last_name' => 'Test', 'mobile_1' => '9222201203', 'message' => 'Is it available?']);
$v->get('/property/' . $L1 . '/visit');
$v->post('/property/' . $L1 . '/visit', ['preferred_date' => istDay(2), 'preferred_time' => '11:00', 'message' => 'Evening is fine']);
$v->post('/logout');
$v->get('/login');
$v->post('/login', ['identifier' => 'p12-viewer@test.invalid', 'password' => $pw]);
check('activity recorded: 3 views, shortlist, contact, visit, login',
    (int) Database::value('SELECT COALESCE(SUM(views), 0) FROM property_view_daily WHERE property_id = ?', [$L1]) === 3
    && (int) Database::value('SELECT COUNT(*) FROM shortlists WHERE property_id = ?', [$L1]) === 1
    && (int) Database::value('SELECT COUNT(*) FROM contact_history WHERE property_id = ?', [$L1]) === 1
    && (int) Database::value('SELECT COUNT(*) FROM enquiries WHERE property_id = ?', [$L1]) === 1
    && (int) Database::value('SELECT COUNT(*) FROM bookings WHERE property_id = ?', [$L1]) === 1
    && (int) Database::value("SELECT COUNT(*) FROM login_activity WHERE user_id = ? AND event = 'login_success'", [$viewer]) >= 1);
check('views are stored under the India date', Database::value('SELECT view_date FROM property_view_daily WHERE property_id = ?', [$L1]) === istDay(0));

// ---------------------------------------------------------------------
echo "\nAccess\n";
// ---------------------------------------------------------------------
$g = new Browser($base);
$g->get('/admin/analytics');
check('guest cannot open analytics', $g->status === 302 && str_contains($g->location, '/admin/login'));
$g->get('/admin/analytics/export');
check('guest cannot download the CSV', $g->status === 302 && str_contains($g->location, '/admin/login'));
$v->get('/admin/analytics');
check('site user cannot open analytics', $v->status === 302 && str_contains($v->location, '/admin/login'));
$adm->get('/admin');
check('admin menu has Analytics; dashboard links to charts', str_contains($adm->body, 'href="' . $base . '/admin/analytics"')
    && str_contains($adm->body, 'href="' . $base . '/admin/analytics?range=month">Charts and trends'));

// ---------------------------------------------------------------------
echo "\nTotals (spec 33) match the database\n";
// ---------------------------------------------------------------------
$adm->get('/admin/analytics?range=today');
$page = $adm->body;
check('analytics page opens', $adm->status === 200 && $adm->has('Analytics') && $adm->has('Today · India time'));
$db = [
    'Total properties posted' => (int) Database::value("SELECT COUNT(*) FROM properties WHERE status <> 'deleted' AND (submitted_at IS NOT NULL OR published_at IS NOT NULL)"),
    'Total users'             => (int) Database::value('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
    'Total logins'            => (int) Database::value("SELECT COUNT(*) FROM login_activity WHERE event = 'login_success' AND context = 'site'"),
    'Property views'          => (int) Database::value('SELECT COALESCE(SUM(views), 0) FROM property_view_daily'),
    'Total shortlists'        => (int) Database::value('SELECT COUNT(*) FROM shortlists'),
    'Total contact requests'  => (int) Database::value('SELECT COUNT(*) FROM contact_history'),
    'Total enquiries'         => (int) Database::value('SELECT COUNT(*) FROM enquiries'),
    'Total booking requests'  => (int) Database::value('SELECT COUNT(*) FROM bookings'),
];
$bad = [];
foreach ($db as $label => $n) {
    if (cardValue($page, $label) !== $n) {
        $bad[] = "$label: page " . var_export(cardValue($page, $label), true) . " db $n";
    }
}
check('all 8 totals equal live COUNT/SUM queries', $bad === [], implode('; ', $bad));
[$tf, $tt] = istRange(istDay(0), istDay(0));
$today = [
    'users' => (int) Database::value('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ?', [$tf, $tt]),
    'properties' => (int) Database::value("SELECT COUNT(*) FROM properties WHERE status <> 'deleted' AND (submitted_at IS NOT NULL OR published_at IS NOT NULL) AND created_at >= ? AND created_at < ?", [$tf, $tt]),
    'views' => (int) Database::value('SELECT COALESCE(SUM(views), 0) FROM property_view_daily WHERE view_date = ?', [istDay(0)]),
];
check('"posted / new users today" lines are right (includes our 4 users, 2 listings)', $today['users'] >= 4 && $today['properties'] >= 2
    && str_contains($page, '+' . number_format($today['users']) . ' · Today') && str_contains($page, '+' . number_format($today['properties']) . ' · Today'));
$inRange = array_map('array_sum', AnalyticsRepository::daily($tf, $tt));
check('repository range totals equal direct queries', $inRange['users'] === $today['users'] && $inRange['properties'] === $today['properties'] && $inRange['views'] === $today['views']);

// ---------------------------------------------------------------------
echo "\nCharts\n";
// ---------------------------------------------------------------------
check('8 trend charts drawn with plain HTML (no chart library)', substr_count($page, '<figure class="col-chart') === 8
    && !preg_match('/chart\.js|apexcharts|highcharts|d3\.v|google\.visualization/i', $page));
foreach (AnalyticsRepository::METRICS as $key => $name) {
    if (!str_contains($page, 'data-chart="' . $key . '"')) {
        $missing[] = $key;
    }
}
check('every metric has its chart', empty($missing));
$todayLabel = gmdate('D, d M Y', strtotime(istDay(0) . ' UTC'));
check('chart values listed under "Show numbers" (today\'s views)', str_contains($page, '<tr><td>' . $todayLabel . '</td><td class="text-end">' . number_format($today['views']) . '</td></tr>')
    && str_contains($page, 'title="' . $todayLabel . ': ' . number_format($today['views']) . '"'));
$adm->get('/admin/analytics?range=week');
$days = (int) ((strtotime(istDay(0)) - strtotime('monday this week', strtotime(istDay(0)))) / 86400) + 1;
check('This week: one column per day so far', $adm->has('One column per day') && bars($adm->body) === 8 * $days);
$adm->get('/admin/analytics?range=custom&from=' . istDay(-119) . '&to=' . istDay(0));
$months = count(array_unique(array_map(static fn ($i) => substr(istDay(-$i), 0, 7), range(0, 119))));
check('long ranges switch to one column per month', $adm->has('One column per month') && bars($adm->body) === 8 * $months);
$adm->get('/admin/analytics?range=custom&from=2020-01-01&to=' . istDay(0));
check('ranges over 3 years are refused politely (shows this month)', $adm->status === 200 && $adm->has('Please choose a range of up to 3 years') && $adm->has('This month · India time'));
$adm->get('/admin/analytics?range=custom&from=' . istDay(0) . '&to=' . istDay(-3));
check('end before start falls back to this month', $adm->status === 200 && $adm->has('This month · India time'));
$adm->get('/admin/analytics?range=custom&from=nonsense&to=%27%3B--');
check('garbage dates are ignored safely', $adm->status === 200 && $adm->has('This month · India time'));

// India-day boundary: 23:30 and 00:30 India time land on different days.
$d = istDay(-10);
$prev = istDay(-11);
[$bf, $bt] = istRange($prev, $d);
$before = AnalyticsRepository::daily($bf, $bt)['shortlists'];
Database::run('INSERT INTO shortlists (user_id, property_id, created_at) VALUES (?, ?, ?), (?, ?, ?)', [
    $viewer, $L2, gmdate('Y-m-d H:i:s', strtotime($d . ' 00:00:00 UTC') - 21600), // 23:30 India time the day before
    $owner, $L2, gmdate('Y-m-d H:i:s', strtotime($d . ' 00:00:00 UTC') - 18000),  // 00:30 India time on $d
]);
$after = AnalyticsRepository::daily($bf, $bt)['shortlists'];
check('events are counted on the India date (23:30 vs 00:30)', $after[$prev] === $before[$prev] + 1 && $after[$d] === $before[$d] + 1);

// ---------------------------------------------------------------------
echo "\nBreakdowns, funnel, most viewed\n";
// ---------------------------------------------------------------------
$adm->get('/admin/analytics?range=today');
$page = $adm->body;
check('funnel from views to visits with percentages', $adm->has('From views to visits') && str_contains($page, 'of views'));
check('listings by purpose link to the filtered list', str_contains($page, 'href="' . $base . '/admin/properties?purpose=pg"'));
check('city bars link to live listings in that city', str_contains($page, 'href="' . $base . '/admin/properties?status=active&amp;city=1"'));
check('enquiry status bars link with the same dates', str_contains($page, 'href="' . $base . '/admin/enquiries?status=new&amp;from=' . istDay(0) . '&amp;to=' . istDay(0) . '"'));
$adm->get('/admin/enquiries?status=new&from=' . istDay(0) . '&to=' . istDay(0));
check('that link opens the matching enquiries', $adm->status === 200 && $adm->has('P12T Vesu <b>2BHK</b>'));
check('most viewed lists our listing with its 3 views today', (bool) preg_match('#/admin/properties/' . $L1 . '">\#' . $L1 . ' P12T Vesu &lt;b&gt;2BHK&lt;/b&gt;</a>.*?<td class="text-end fw-semibold">3</td>#s', $page));
check('listing titles are escaped (no raw HTML)', !str_contains($page, '<b>2BHK</b>'));
check('page stays light (under 200 KB, no external scripts added)', strlen($page) < 200000 && substr_count($page, '<script') <= 3, (string) strlen($page));

// ---------------------------------------------------------------------
echo "\nCSV download\n";
// ---------------------------------------------------------------------
$adm->get('/admin/analytics/export?range=custom&from=' . istDay(-2) . '&to=' . istDay(0));
$lines = array_values(array_filter(explode("\n", $adm->body)));
check('CSV is a download with a dated file name', $adm->status === 200 && stripos($adm->headers, 'Content-Type: text/csv') !== false
    && str_contains($adm->headers, 'attachment; filename="analytics-' . istDay(-2) . '-to-' . istDay(0) . '.csv"'));
check('CSV has a header and one row per day', count($lines) === 4 && str_starts_with($lines[0], '"Date (India)","New users","Properties posted",Logins,"Property views"'));
$last = str_getcsv(end($lines), ',', '"', '');
check("CSV today's row matches the database", $last[0] === istDay(0) && (int) $last[1] === $today['users'] && (int) $last[4] === $today['views']);
check('CSV holds numbers only (no names, emails or mobiles)', !preg_match('/@|Vidya|9222201/', $adm->body));

// ---------------------------------------------------------------------
echo "\nDashboard figures use the same rules\n";
// ---------------------------------------------------------------------
$adm->get('/admin?range=today');
check('dashboard views today use the India date', str_contains($adm->body, 'Property views</div><div class="fs-4 fw-semibold">' . number_format($today['views']) . '</div>'));
check('dashboard "Posted · Today" counts admin-published listings too', str_contains($adm->body, 'Posted · Today</div><div class="fs-4 fw-semibold">' . number_format($today['properties']) . '</div>'));

// ---------------------------------------------------------------------
echo "\nOwner performance (spec 6)\n";
// ---------------------------------------------------------------------
$o->get('/owner/properties/' . $L1 . '/step/5');
$o->post('/owner/properties/' . $L1 . '/step/5', ['rent' => '18000', 'security_deposit' => '40000', 'action' => 'continue']);
Database::run("INSERT INTO property_status_history (property_id, old_status, new_status, changed_by, actor_role, reason) VALUES
    (?, 'active', 'paused', ?, 'admin', 'P12 internal admin note'), (?, 'paused', 'changes_requested', ?, 'admin', 'Please add a kitchen photo'), (?, 'changes_requested', 'active', ?, 'owner', NULL)",
    [$L1, $adminId, $L1, $adminId, $L1, $owner]);
$o->get('/owner/properties');
check('My Properties has a Performance button', str_contains($o->body, 'href="' . $base . '/owner/properties/' . $L1 . '/performance"'));
$o->get('/owner/properties/' . $L1 . '/performance');
$perf = $o->body;
$ownerCard = static fn (string $label) => preg_match('#' . preg_quote($label, '#') . ' · last 30 days</div>\s*<div class="fs-2 fw-semibold">([\d,]+)</div>#', $perf, $m) ? (int) $m[1] : null;
check('performance page opens for the owner', $o->status === 200 && $o->has('Performance') && $o->has('P12T Vesu <b>2BHK</b>') && !str_contains($perf, '<b>2BHK</b>'));
check('last 30 days: 3 views, 1 shortlist, 1 enquiry, 1 visit request', $ownerCard('Views') === 3 && $ownerCard('Shortlists') === 1
    && $ownerCard('Enquiries') === 1 && $ownerCard('Visit requests') === 1, json_encode([$ownerCard('Views'), $ownerCard('Shortlists'), $ownerCard('Enquiries'), $ownerCard('Visit requests')]));
check('since-posted totals shown', $o->has(number_format((int) prop($L1)['views_count']) . ' since posted'));
check('4 charts, one column per day', substr_count($perf, '<figure class="col-chart') === 4 && bars($perf) === 4 * 30);
check('rent history shows the change', str_contains($perf, e(money_inr(20000)) . ' → <strong>' . e(money_inr(18000)) . '</strong>'));
check('listing history shows who changed the status', $o->has('by Admin team') && $o->has('by You'));
check('change-request reason shown; internal pause note hidden', $o->has('Please add a kitchen photo') && !$o->has('P12 internal admin note'));
$o->get('/owner/properties/' . $L1 . '/performance?days=365');
check('12 months view switches to monthly columns', $o->status === 200 && $o->has('Last 12 months') && bars($o->body) <= 4 * 13);
$o->get('/owner/properties/' . $L1 . '/performance?days=7');
check('unknown period falls back to 30 days', $o->status === 200 && bars($o->body) === 4 * 30);
$o2->get('/owner/properties/' . $L1 . '/performance');
check("another owner cannot see this listing's performance (404)", $o2->status === 404 && !str_contains($o2->body, 'P12T Vesu'));
$v->get('/owner/properties/' . $L1 . '/performance');
check('a viewer cannot see it either (404)', $v->status === 404);
$g->get('/owner/properties/' . $L1 . '/performance');
check('guest is sent to log in', $g->status === 302 && str_contains($g->location, '/login'));
$o->get('/owner');
check('owner dashboard: views of your listings, last 30 days', $o->has('Views of your listings · last 30 days') && str_contains($o->body, '<span class="small text-muted">3 total</span>'));
$o2->get('/owner');
check("owner dashboard counts only the owner's own listings", $o2->has('Views of your listings · last 30 days') && str_contains($o2->body, '<span class="small text-muted">0 total</span>'));

// ---------------------------------------------------------------------
$cleanup();
foreach ($saved as $k => $val) {
    $setting($k, $val);
}
check('test data removed', !Database::value("SELECT 1 FROM properties WHERE title LIKE 'P12T%'") && !Database::value("SELECT 1 FROM users WHERE email LIKE 'p12-%@test.invalid'"));
echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
