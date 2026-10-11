<?php
declare(strict_types=1);

/**
 * Phase 13 security hardening tests. Each check covers one finding of the
 * Phase 13 audit (access control, injection/output, login/session/config)
 * plus the baseline protections that must stay in place.
 * DEVELOPMENT ONLY (creates and removes @test.invalid accounts and listings
 * whose titles start with "P13T").
 *
 *   php -S 127.0.0.1:8088 -t public public/index.php &
 *   php tests/phase13_security_test.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\AuthService;
use App\Services\Mailer;

if (Config::isProduction() || Config::env('MAIL_DRIVER') !== 'log') {
    fwrite(STDERR, "Run only in development with MAIL_DRIVER=log.\n");
    exit(1);
}
require __DIR__ . '/lib/http.php';

$cleanup = static function (): void {
    $ids = array_map('intval', array_column(Database::all("SELECT id FROM users WHERE email LIKE 'p13-%@test.invalid'"), 'id')) ?: [0];
    $in = implode(',', $ids);
    $p = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM properties WHERE owner_id IN ($in) OR created_by IN ($in) OR title LIKE 'P13T%'"), 'id')) ?: [0]);
    $b = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM bookings WHERE property_id IN ($p) OR user_id IN ($in)"), 'id')) ?: [0]);
    $q = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM enquiries WHERE property_id IN ($p) OR user_id IN ($in)"), 'id')) ?: [0]);
    $plr = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM property_limit_requests WHERE user_id IN ($in)"), 'id')) ?: [0]);
    foreach ([
        "DELETE FROM notifications WHERE user_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in)) OR (entity_type = 'booking' AND entity_id IN ($b))
            OR (entity_type = 'enquiry' AND entity_id IN ($q)) OR (entity_type = 'property' AND entity_id IN ($p)) OR (entity_type = 'limit_request' AND entity_id IN ($plr))",
        "DELETE FROM booking_status_history WHERE booking_id IN ($b)",
        "DELETE FROM bookings WHERE id IN ($b)",
        "DELETE FROM contact_history WHERE enquiry_id IN ($q) OR user_id IN ($in) OR property_id IN ($p)",
        "DELETE FROM enquiries WHERE id IN ($q)",
        "DELETE FROM shortlists WHERE property_id IN ($p) OR user_id IN ($in)",
        "DELETE FROM property_status_history WHERE property_id IN ($p)",
        "DELETE FROM property_locations WHERE property_id IN ($p)",
        "DELETE FROM properties WHERE id IN ($p)",
        "DELETE FROM property_limit_requests WHERE id IN ($plr)",
        "DELETE FROM admin_activity_logs WHERE admin_id IN ($in)",
        "DELETE FROM activity_logs WHERE user_id IN ($in)",
        "DELETE FROM login_activity WHERE user_id IN ($in) OR identifier LIKE '%1301%'",
        "DELETE FROM user_sessions WHERE user_id IN ($in)",
        "DELETE FROM user_roles WHERE user_id IN ($in)",
        "DELETE FROM email_queue WHERE to_email LIKE 'p13-%@test.invalid'",
        "DELETE FROM users WHERE id IN ($in)",
        'DELETE FROM rate_limits',
    ] as $sql) {
        Database::run($sql);
    }
};
$cleanup();
Database::run("UPDATE settings SET setting_value = '0' WHERE setting_key IN ('auth_otp_enabled', 'email_notifications_enabled')");

$pw = 'Secret123';
function registerUser(Browser $b, string $first, string $mobile, string $email, ?string $password = null): int
{
    global $pw;
    $b->get('/register');
    $b->post('/register', ['first_name' => $first, 'last_name' => 'Test', 'mobile' => $mobile, 'email' => $email,
        'password' => $password ?? $pw, 'password_confirmation' => $password ?? $pw, 'terms' => '1', 'website' => '']);
    return (int) Database::value('SELECT id FROM users WHERE email = ?', [$email]);
}
function mk(int $owner, string $title): int
{
    $t = Database::one("SELECT id, category_id FROM property_types WHERE code = 'flat'");
    $row = [
        'owner_id' => $owner, 'created_by' => $owner, 'purpose' => 'rent', 'category_id' => $t['category_id'], 'type_id' => $t['id'],
        'status' => 'active', 'draft_step' => 8, 'title' => $title, 'slug' => slugify($title), 'description' => 'Test listing.',
        'unit_no' => 'A-1', 'building_name' => 'Test Heights', 'address_line' => 'Ring Road',
        'city_id' => 1, 'locality_id' => (int) Database::value("SELECT id FROM localities WHERE slug = 'vesu'"), 'pincode' => '395007',
        'rent' => 20000, 'security_deposit' => 40000, 'bedrooms' => 2, 'bathrooms' => 2, 'furnishing' => 'semi_furnished',
        'available_from' => gmdate('Y-m-d'), 'contact_name' => 'Owner Test', 'contact_mobile_1' => '919222201310',
        'published_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'approved_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 30),
    ];
    $cols = implode(', ', array_keys($row));
    $id = Database::insert("INSERT INTO properties ($cols) VALUES (" . rtrim(str_repeat('?, ', count($row)), ', ') . ')', array_values($row));
    Database::run("INSERT INTO property_locations (property_id, latitude, longitude, public_latitude, public_longitude, privacy)
                   VALUES (?, 21.1418, 72.7709, 21.1440, 72.7731, 'approximate')", [$id]);
    return $id;
}
function adminLogin(string $email): Browser
{
    global $base, $pw;
    $a = new Browser($base);
    $a->get('/admin/login');
    $a->post('/admin/login', ['identifier' => $email, 'password' => $pw]);
    return $a;
}
function user(int $id): array { return Database::one('SELECT * FROM users WHERE id = ?', [$id]); }

// ---------------------------------------------------------------------
echo "Login lockout cannot be dodged by writing the mobile differently\n";
// ---------------------------------------------------------------------
$victim = registerUser(new Browser($base), 'Vikram', '9222201301', 'p13-victim@test.invalid');
check('one key for every way of writing the same mobile', count(array_unique(array_map([AuthService::class, 'identifierKey'],
    ['9222201301', '+91 92222 01301', '092222-01301', '91-9222201301', ' 9222 201 301 ']))) === 1);
check('one key for any capitalisation of an email', AuthService::identifierKey('P13-Victim@Test.Invalid') === AuthService::identifierKey('p13-victim@test.invalid'));
$x = new Browser($base);
foreach (['9222201301', '+91 92222 01301', '092222-01301', '91-9222201301', '9222 201 301'] as $variant) {
    $x->get('/login');
    $x->post('/login', ['identifier' => $variant, 'password' => 'WrongPass1']);
}
$x->get('/login');
$x->post('/login', ['identifier' => '+919222201301', 'password' => $pw]);
$x->follow();
check('5 wrong tries in different formats lock the account (even with the right password)', $x->has('Too many login attempts'));
Database::run('DELETE FROM rate_limits');

// ---------------------------------------------------------------------
echo "\nPasswords\n";
// ---------------------------------------------------------------------
foreach (['Password@123', 'qwerty123', 'Welcome1'] as $weak) {
    $b = new Browser($base);
    $id = registerUser($b, 'Weak', '9222201399', 'p13-weak@test.invalid', $weak);
    $b->follow();
    check("common password refused: $weak", $id === 0 && $b->has('too common'));
    Database::run('DELETE FROM rate_limits');
}
$id = registerUser(new Browser($base), 'Strong', '9222201398', 'p13-strong@test.invalid', 'Tulsi-Garden-48');
check('a normal password is accepted', $id > 0 && password_verify('Tulsi-Garden-48', user($id)['password_hash']));

// ---------------------------------------------------------------------
echo "\nAdmin account rules for posting limits\n";
// ---------------------------------------------------------------------
$adminId = registerUser(new Browser($base), 'Asha', '9222201303', 'p13-admin@test.invalid');
$admin2 = registerUser(new Browser($base), 'Bela', '9222201304', 'p13-admin2@test.invalid');
$owner = registerUser($o = new Browser($base), 'Omkar', '9222201305', 'p13-owner@test.invalid');
$owner2 = registerUser($o2 = new Browser($base), 'Nisha', '9222201306', 'p13-owner2@test.invalid');
$viewer = registerUser($v = new Browser($base), 'Vidya', '9222201307', 'p13-viewer@test.invalid');
Database::run("INSERT INTO user_roles (user_id, role) VALUES (?, 'admin'), (?, 'admin'), (?, 'owner'), (?, 'owner')", [$adminId, $admin2, $owner, $owner2]);
$adm = adminLogin('p13-admin@test.invalid');
check('admin signed in', redirectsTo($adm, '/admin'));
$before = (int) user($adminId)['property_limit'];
$adm->get('/admin/users/' . $adminId);
$adm->post('/admin/users/' . $adminId . '/limit', ['property_limit' => '50']);
check('an admin cannot raise their own posting limit', (int) user($adminId)['property_limit'] === $before && (int) user($adminId)['property_limit_unlimited'] === 0);
$adm->get('/admin/users/' . $admin2);
$adm->post('/admin/users/' . $admin2 . '/limit', ['unlimited' => '1', 'property_limit' => '3']);
check("a plain admin cannot change another admin's limit", (int) user($admin2)['property_limit_unlimited'] === 0);
$adm->get('/admin/users/' . $owner);
$adm->post('/admin/users/' . $owner . '/limit', ['property_limit' => '7']);
check("an admin can still change an owner's limit", (int) user($owner)['property_limit'] === 7);
$lr = Database::insert("INSERT INTO property_limit_requests (user_id, current_limit, current_count, requested_limit, reason) VALUES (?, 3, 3, 20, 'More flats')", [$adminId]);
$adm->get('/admin/limit-requests/' . $lr);
$adm->post('/admin/limit-requests/' . $lr, ['decision' => 'approve', 'approved_limit' => '20']);
check('an admin cannot approve their own limit request', Database::value('SELECT status FROM property_limit_requests WHERE id = ?', [$lr]) === 'pending'
    && (int) user($adminId)['property_limit'] === $before);

// ---------------------------------------------------------------------
echo "\nChanging a listing's owner moves its enquiries and visit requests\n";
// ---------------------------------------------------------------------
$L = mk($owner, 'P13T Vesu 2BHK');
$v->get('/property/' . $L . '/contact');
$v->post('/property/' . $L . '/contact', ['first_name' => 'Vidya', 'last_name' => 'Test', 'mobile_1' => '9222201307', 'message' => 'Hello']);
$v->get('/property/' . $L . '/visit');
$v->post('/property/' . $L . '/visit', ['preferred_date' => gmdate('Y-m-d', time() + 19800 + 86400 * 2), 'preferred_time' => '11:00']);
$bookingId = (int) Database::value('SELECT id FROM bookings WHERE property_id = ?', [$L]);
$o->get('/owner/enquiries');
check('before: the first owner sees the enquiry', $bookingId > 0 && $o->has('Vidya Test'));
$adm->get('/admin/properties/' . $L);
$adm->post('/admin/properties/' . $L . '/owner', ['owner' => 'p13-owner2@test.invalid']);
check('listing moved to the new owner', (int) prop($L)['owner_id'] === $owner2);
check('enquiry and visit request moved with it', (int) Database::value('SELECT owner_id FROM enquiries WHERE property_id = ?', [$L]) === $owner2
    && (int) Database::value('SELECT owner_id FROM bookings WHERE id = ?', [$bookingId]) === $owner2);
$o->get('/owner/enquiries');
$hidden = !$o->has('Vidya Test');
$o->get('/owner/bookings');
check("the previous owner no longer sees the visitor's details", $hidden && !$o->has('Vidya Test'));
$o->get('/owner/bookings');
$o->post('/owner/bookings/' . $bookingId, ['action' => 'accept']);
check('the previous owner cannot answer the visit request', $o->status === 404 && Database::value('SELECT status FROM bookings WHERE id = ?', [$bookingId]) === 'pending');
$o2->get('/owner/enquiries');
check('the new owner sees it instead', $o2->has('Vidya Test'));
function prop(int $id): array { return Database::one('SELECT * FROM properties WHERE id = ?', [$id]); }

// ---------------------------------------------------------------------
echo "\nSuspended accounts cannot edit their profile\n";
// ---------------------------------------------------------------------
Database::run("UPDATE users SET status = 'suspended', status_reason = 'P13 test' WHERE id = ?", [$viewer]);
$v->get('/account/profile');
$v->post('/account/profile', ['first_name' => 'Hacked', 'last_name' => 'Test', 'email' => 'p13-viewer@test.invalid']);
check('viewer profile change refused while suspended', user($viewer)['first_name'] === 'Vidya' && $v->status === 302);
Database::run("UPDATE users SET status = 'suspended', status_reason = 'P13 test' WHERE id = ?", [$owner2]);
$o2->get('/owner/profile');
$o2->post('/owner/profile', ['first_name' => 'Hacked', 'last_name' => 'Test', 'email' => 'p13-owner2@test.invalid']);
check('owner profile change refused while suspended', user($owner2)['first_name'] === 'Nisha');
Database::run("UPDATE users SET status = 'active', status_reason = NULL WHERE id IN (?, ?)", [$viewer, $owner2]);

// ---------------------------------------------------------------------
echo "\nPage content is cleaned before it reaches visitors\n";
// ---------------------------------------------------------------------
$about = Database::one("SELECT id, body FROM cms_pages WHERE slug = 'about'");
Database::run('UPDATE cms_pages SET body = ? WHERE id = ?', ['<h2>About us</h2><p onclick="steal()">We help <a href="javascript:alert(1)">tenants</a> and <a href="/rent">owners</a>.</p>'
    . '<script>alert("x")</script><img src="x" onerror="alert(2)"><iframe src="https://evil.example"></iframe>', $about['id']]);
$g = new Browser($base);
$g->get('/page/about');
$body = $g->body;
Database::run('UPDATE cms_pages SET body = ? WHERE id = ?', [$about['body'], $about['id']]);
check('headings, text and safe links kept', str_contains($body, '<h2>About us</h2>') && str_contains($body, '<a href="/rent">owners</a>'));
check('scripts, event handlers, javascript: links and iframes removed', !str_contains($body, 'alert("x")') && !str_contains($body, 'onerror')
    && !str_contains($body, 'onclick') && !str_contains($body, 'javascript:') && !str_contains($body, 'evil.example'));

// ---------------------------------------------------------------------
echo "\nBrowser migrations: token only in the form body\n";
// ---------------------------------------------------------------------
$token = (string) Config::env('SETUP_TOKEN', '');
$s = new Browser($base);
$s->get('/setup/migrate?token=' . rawurlencode($token));
check('a token in the URL no longer runs anything (shows the form)', $s->status === 200 && $s->has('Run database migrations') && !$s->has('Done.'));
check('the form is not indexed or cached', str_contains($s->body, 'noindex') && stripos($s->headers, 'Cache-Control: no-store') !== false);
$s->post('/setup/migrate', ['token' => $token]);
check('the right token posted runs migrations', $s->status === 200 && $s->has('Done. Now clear SETUP_TOKEN'));
for ($i = 0; $i < 5; $i++) {
    $s->get('/setup/migrate');
    $s->post('/setup/migrate', ['token' => 'wrong-token-' . $i]);
}
check('wrong tokens are refused', $s->status === 403 || $s->status === 429);
$s->get('/setup/migrate');
$s->post('/setup/migrate', ['token' => $token]);
check('after 5 wrong tokens even the right one waits an hour', $s->status === 429 && !$s->has('Done.'));
$s->get('/setup/migrate');
$s->post('/setup/migrate', ['token' => $token], false);
check('the form needs a CSRF token', $s->status === 419);
Database::run('DELETE FROM rate_limits');

// ---------------------------------------------------------------------
echo "\nAdmin sessions end 12 hours after login\n";
// ---------------------------------------------------------------------
$a2 = adminLogin('p13-admin2@test.invalid');
$a2->get('/admin');
check('fresh admin session works', $a2->status === 200);
$jar = (new ReflectionProperty(Browser::class, 'jar'))->getValue($a2);
preg_match('/\t' . preg_quote((string) Config::get('session.admin_name'), '/') . '\t(\S+)/', (string) file_get_contents($jar), $m);
$file = APP_ROOT . '/storage/sessions/sess_' . ($m[1] ?? 'missing');
$data = (string) @file_get_contents($file);
$aged = preg_replace('/login_at\|i:(\d+);/', 'login_at|i:' . (time() - 13 * 3600) . ';', $data, 1, $n);
file_put_contents($file, $aged);
$a2->get('/admin');
check('a 13-hour-old admin session is signed out even while active', $n === 1 && $a2->status === 302 && str_contains($a2->location, '/admin/login'));
check('and its server-side session row is revoked', (int) Database::value('SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND context = \'admin\' AND revoked_at IS NULL', [$admin2]) === 0);

// ---------------------------------------------------------------------
echo "\nProduction guards\n";
// ---------------------------------------------------------------------
$envProp = new ReflectionProperty(Config::class, 'env');
$savedEnv = $envProp->getValue();
$envProp->setValue(null, ['APP_ENV' => 'production', 'MAIL_DRIVER' => 'log'] + $savedEnv);
$logFile = APP_ROOT . '/storage/logs/mail-' . gmdate('Y-m-d') . '.log';
clearstatcache();
$size = is_file($logFile) ? filesize($logFile) : 0;
Mailer::queue('p13-mail@test.invalid', 'Test', 'P13 reset link', '<p>secret link</p>', 'secret link', true);
clearstatcache();
$row = Database::one("SELECT status, last_error FROM email_queue WHERE to_email = 'p13-mail@test.invalid'");
$envProp->setValue(null, $savedEnv);
check('in production, mail is never written to a log file (needs SMTP)', $row['status'] !== 'sent' && str_contains((string) $row['last_error'], 'smtp')
    && (is_file($logFile) ? filesize($logFile) : 0) === $size);
check('raw errors can never be switched on in production', str_contains((string) file_get_contents(APP_ROOT . '/app/bootstrap.php'), "Config::env('APP_DEBUG', false) && !Config::isProduction()"));
check('error log never records query strings (tokens)', str_contains((string) file_get_contents(APP_ROOT . '/app/Core/ErrorHandler.php'), "strtok((string) (\$_SERVER['REQUEST_URI']"));

// ---------------------------------------------------------------------
echo "\nBaseline protections still in place\n";
// ---------------------------------------------------------------------
$g->get('/');
$h = $g->headers;
check('security headers: CSP with nonce, nosniff, frame, referrer, opener', (bool) preg_match("/Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-/", $h)
    && str_contains($h, 'X-Content-Type-Options: nosniff') && str_contains($h, 'X-Frame-Options: SAMEORIGIN')
    && str_contains($h, 'Referrer-Policy: strict-origin-when-cross-origin') && str_contains($h, 'Cross-Origin-Opener-Policy: same-origin')
    && str_contains($h, "object-src 'none'") && str_contains($h, "frame-ancestors 'self'"));
$x = new Browser($base);
$x->get('/login');
check('session cookie is HttpOnly and SameSite=Lax', (bool) preg_match('/Set-Cookie: rw_sess=[^\r\n]*HttpOnly[^\r\n]*SameSite=Lax/i', $x->headers));
$x->post('/login', ['identifier' => 'p13-victim@test.invalid', 'password' => $pw], false);
check('POST without CSRF token refused', $x->status === 419);
$x->get('/no-such-page-' . bin2hex(random_bytes(3)));
check('unknown pages show a friendly 404 without technical detail', $x->status === 404 && !preg_match('/Stack trace|\.php:\d+|PDOException/', $x->body));
$x->get('/login?next=' . rawurlencode('https://evil.example/'));
$x->post('/login?next=' . rawurlencode('https://evil.example/'), ['identifier' => 'p13-victim@test.invalid', 'password' => $pw]);
check('login never redirects to another website', $x->status === 302 && str_starts_with($x->location, $base . '/') && !str_contains($x->location, 'evil.example'));
$root = (string) file_get_contents(APP_ROOT . '/.htaccess');
$uploads = (string) file_get_contents(APP_ROOT . '/public/uploads/.htaccess');
check('code, config, storage and database folders are blocked from the web', str_contains($root, 'RewriteRule ^(app|bin|config|database|docs|routes|storage|tests|views)(/|$) - [F,L]')
    && is_file(APP_ROOT . '/storage/.htaccess'));
check('uploads folder never runs PHP and serves only generated media names', str_contains($uploads, 'php_flag engine off') && str_contains($uploads, '^[a-f0-9]{32}'));
check('no secrets in public files', !preg_match('/DB_PASS|MAIL_PASSWORD|SETUP_TOKEN|OTP_SECRET/', implode('', array_map('file_get_contents', glob(APP_ROOT . '/public/assets/js/*.js') ?: [])) . (string) file_get_contents(APP_ROOT . '/public/index.php')));

$cleanup();
check('test data removed', !Database::value("SELECT 1 FROM users WHERE email LIKE 'p13-%@test.invalid'") && !Database::value("SELECT 1 FROM properties WHERE title LIKE 'P13T%'"));
echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
