<?php
declare(strict_types=1);

/**
 * Phase 2 end-to-end tests: real HTTP requests against a running copy of the
 * site, with assertions on the database. DEVELOPMENT ONLY: it creates and
 * deletes test accounts, flips the OTP setting, and reads OTP codes and
 * emails from storage/logs (MAIL_DRIVER=log, SMS_DRIVER=log).
 *
 *   php -S 127.0.0.1:8088 -t public public/index.php &
 *   php tests/phase2_auth_test.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

if (Config::isProduction() || Config::env('MAIL_DRIVER') !== 'log' || Config::env('SMS_DRIVER') !== 'log') {
    fwrite(STDERR, "Run only in development with MAIL_DRIVER=log and SMS_DRIVER=log.\n");
    exit(1);
}

require __DIR__ . '/lib/http.php';

// ---------------------------------------------------------------------
// Clean slate for test accounts (mobiles 9111100001-9, emails @test.invalid)
// ---------------------------------------------------------------------
$cleanup = static function (): void {
    $ids = array_column(Database::all("SELECT id FROM users WHERE email LIKE '%@test.invalid'"), 'id');
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        Database::run("DELETE FROM notifications WHERE entity_type = 'user' AND entity_id IN ($in)");
        Database::run("DELETE FROM activity_logs WHERE user_id IN ($in)");
        Database::run("DELETE FROM login_activity WHERE user_id IN ($in)");
        Database::run("DELETE FROM users WHERE id IN ($in)");
    }
    Database::run('DELETE FROM rate_limits');
    Database::run("DELETE FROM otp_codes WHERE target LIKE '9191111000%'");
    Database::run("UPDATE settings SET setting_value = '0' WHERE setting_key = 'auth_otp_enabled'");
};
$cleanup();

$pw = 'Secret123';
$reg = static fn (array $over = []): array => $over + [
    'first_name' => 'Asha', 'last_name' => 'Mehta', 'mobile' => '91111 00001', 'email' => 'asha@test.invalid',
    'password' => $pw, 'password_confirmation' => $pw, 'terms' => '1', 'website' => '',
];

echo "\nRegistration\n";
$a = new Browser($base);
$a->get('/register');
check('register page loads with CSRF token', $a->status === 200 && strlen($a->token()) === 64);
$a->post('/register', $reg());
check('new registration redirects to account', redirectsTo($a, '/account'), $a->status . ' ' . $a->location);
$user = Database::one("SELECT * FROM users WHERE email = 'asha@test.invalid'");
check('user stored with normalised mobile 919111100001', ($user['mobile'] ?? '') === '919111100001');
check('password stored as hash, not plain text', isset($user['password_hash']) && $user['password_hash'] !== $pw && password_verify($pw, $user['password_hash']));
check('default property limit 3 copied from settings', (int) ($user['property_limit'] ?? 0) === 3);
check('viewer role assigned', (bool) Database::value("SELECT 1 FROM user_roles WHERE user_id = ? AND role = 'viewer'", [$user['id']]));
check('admin notified of new registration', (bool) Database::value("SELECT 1 FROM notifications WHERE type = 'user_registered' AND entity_id = ?", [$user['id']]));
check('verification email queued and sent (log driver)', lastMailLink('asha@test.invalid', '/verify-email') !== null);
$a->follow();
check('account page shows welcome + name', $a->status === 200 && $a->has('Welcome, Asha Mehta'));

echo "\nRegistration validation\n";
$b = new Browser($base);
$b->get('/register');
$b->post('/register', $reg(['email' => 'other@test.invalid', 'mobile' => '+91 9111100001']))->follow();
check('duplicate mobile (different format) rejected', $b->has('This mobile number is already registered'));
$b->post('/register', $reg(['mobile' => '9111100002', 'email' => 'ASHA@test.invalid']))->follow();
check('duplicate email (different case) rejected', $b->has('This email is already registered'));
$b->post('/register', $reg(['mobile' => '12345', 'email' => 'x1@test.invalid']))->follow();
check('invalid mobile rejected', $b->has('valid 10-digit Indian mobile'));
$b->post('/register', $reg(['mobile' => '9111100002', 'email' => 'x1@test.invalid', 'password' => 'short', 'password_confirmation' => 'short']))->follow();
check('weak password rejected', $b->has('at least 8 characters'));
$b->post('/register', $reg(['mobile' => '9111100002', 'email' => 'x1@test.invalid', 'password_confirmation' => 'Different1']))->follow();
check('password confirmation mismatch rejected', $b->has('Passwords do not match'));
$b->post('/register', array_diff_key($reg(['mobile' => '9111100002', 'email' => 'x1@test.invalid']), ['terms' => 1]))->follow();
check('terms must be accepted', $b->has('Please accept the Terms'));
check('old input kept after error (no password)', $b->has('value="x1@test.invalid"') && !$b->has('value="' . $pw . '"'));
check('no account created by failed attempts', (int) Database::value("SELECT COUNT(*) FROM users WHERE email = 'x1@test.invalid'") === 0);

echo "\nCSRF\n";
$c = new Browser($base);
$c->get('/login');
$c->post('/login', ['identifier' => 'asha@test.invalid', 'password' => $pw], false);
check('POST without CSRF token returns 419', $c->status === 419);
$c->post('/login', ['identifier' => 'asha@test.invalid', 'password' => $pw, '_token' => str_repeat('a', 64)]);
check('POST with wrong CSRF token returns 419', $c->status === 419);

echo "\nLogin / logout\n";
$a->get('/account');
$a->post('/logout');
check('logout redirects home', redirectsTo($a, '/'));
$a->get('/account');
check('after logout /account requires login', redirectsTo($a, '/login?next=%2Faccount'));
$a->get('/login');
$a->post('/login', ['identifier' => 'asha@test.invalid', 'password' => 'WrongPass1']);
$a->follow();
check('wrong password shows generic error', $a->has('Incorrect mobile/email or password'));
$a->post('/login', ['identifier' => 'nobody@test.invalid', 'password' => 'WrongPass1'])->follow();
check('unknown account shows the same generic error', $a->has('Incorrect mobile/email or password'));
$a->post('/login', ['identifier' => '9111100001', 'password' => $pw]);
check('login with 10-digit mobile works', redirectsTo($a, '/account'));
$a->get('/account');
$a->post('/logout');
$a->get('/login');
$a->post('/login', ['identifier' => 'ASHA@test.invalid', 'password' => $pw, 'next' => '//evil.example/x']);
check('external "next" redirect is ignored (open redirect blocked)', redirectsTo($a, '/account'));
check('login recorded in login_activity', (int) Database::value("SELECT COUNT(*) FROM login_activity WHERE user_id = ? AND event = 'login_success'", [$user['id']]) >= 2);
check('last_login_at and login_count updated', (int) Database::value('SELECT login_count FROM users WHERE id = ?', [$user['id']]) >= 3);
check('failed-login identifiers are masked in logs', !Database::value("SELECT 1 FROM login_activity WHERE identifier = 'asha@test.invalid'"));
$a->get('/login');
check('logged-in user visiting /login goes to account', redirectsTo($a, '/account'));

echo "\nBrute-force protection\n";
$d = new Browser($base);
$d->get('/login');
for ($i = 0; $i < 5; $i++) {
    $d->post('/login', ['identifier' => 'asha@test.invalid', 'password' => 'Nope' . $i . 'xx1'])->follow();
}
$d->post('/login', ['identifier' => 'asha@test.invalid', 'password' => $pw])->follow();
check('6th attempt locked out even with the right password', $d->has('Too many login attempts'));
check('lockout logged', (bool) Database::value("SELECT 1 FROM login_activity WHERE event = 'locked_out' AND user_id IS NULL"));
Database::run('DELETE FROM rate_limits');

echo "\nAccount status\n";
$e = new Browser($base);
$e->get('/register');
$e->post('/register', $reg(['first_name' => 'Ravi', 'mobile' => '9111100003', 'email' => 'ravi@test.invalid']));
$ravi = (int) Database::value("SELECT id FROM users WHERE email = 'ravi@test.invalid'");
$e->get('/account');
check('user logged in before being blocked', $e->status === 200);
Database::run("UPDATE users SET status = 'blocked' WHERE id = ?", [$ravi]);
$e->get('/account');
check('blocked user is logged out on the very next request', redirectsTo($e, '/login?next=%2Faccount'));
$e->get('/login');
$e->post('/login', ['identifier' => 'ravi@test.invalid', 'password' => $pw])->follow();
check('blocked user cannot log in', $e->has('This account has been blocked'));
Database::run("UPDATE users SET status = 'inactive' WHERE id = ?", [$ravi]);
$e->post('/login', ['identifier' => 'ravi@test.invalid', 'password' => $pw]);
check('inactive user can log in but lands on account', redirectsTo($e, '/account'));
$e->follow();
check('inactive user sees restriction notice', $e->has('Your account is inactive'));
Database::run("UPDATE users SET status = 'active' WHERE id = ?", [$ravi]);

echo "\nEmail verification\n";
$token = lastMailLink('asha@test.invalid', '/verify-email');
$a->get('/verify-email?token=' . $token);
check('verification link marks email verified', (bool) Database::value('SELECT email_verified_at IS NOT NULL FROM users WHERE id = ?', [$user['id']]));
$a->get('/verify-email?token=' . $token)->follow();
check('verification link cannot be reused', $a->has('invalid or has expired'));

echo "\nForgot / reset password (email link)\n";
$f = new Browser($base);
$f->get('/forgot-password');
$f->post('/forgot-password', ['identifier' => 'unknown@test.invalid'])->follow();
check('unknown email gets the same neutral message', $f->has('If an account uses that email'));
$f->post('/forgot-password', ['identifier' => 'asha@test.invalid'])->follow();
$reset = lastMailLink('asha@test.invalid', '/reset-password');
check('reset email sent with a 64-char token', $reset !== null);
check('only sha256 of token stored', (bool) Database::value('SELECT 1 FROM password_resets WHERE token_hash = ?', [hash('sha256', (string) $reset)]));
$f->get('/reset-password?token=' . str_repeat('0', 64));
check('bad token shows invalid link page', $f->has('invalid or has expired'));
$f->get('/reset-password?token=' . $reset);
$f->post('/reset-password', ['token' => $reset, 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456']);
check('reset succeeds and redirects to login', redirectsTo($f, '/login'));
$a->get('/account');
check('existing sessions are signed out after reset', redirectsTo($a, '/login?next=%2Faccount'));
$f->get('/reset-password?token=' . $reset);
check('reset token cannot be reused', $f->has('invalid or has expired'));
$f->get('/login');
$f->post('/login', ['identifier' => 'asha@test.invalid', 'password' => $pw])->follow();
check('old password no longer works', $f->has('Incorrect mobile/email or password'));
$f->post('/login', ['identifier' => 'asha@test.invalid', 'password' => 'NewSecret456']);
check('new password works', redirectsTo($f, '/account'));

echo "\nChange password\n";
$f->get('/account');
$f->post('/account/password', ['current_password' => 'wrong', 'password' => 'Another789', 'password_confirmation' => 'Another789'])->follow();
check('wrong current password rejected', $f->has('current password is incorrect'));
$f->post('/account/password', ['current_password' => 'NewSecret456', 'password' => 'Another789', 'password_confirmation' => 'Another789'])->follow();
check('password changed, user stays logged in', $f->has('Password changed') && $f->status === 200);

echo "\nMobile OTP (setting switched on)\n";
Database::run("UPDATE settings SET setting_value = '1' WHERE setting_key = 'auth_otp_enabled'");
$g = new Browser($base);
$g->get('/register');
$g->post('/register', $reg(['first_name' => 'Neel', 'mobile' => '9111100004', 'email' => 'neel@test.invalid']));
check('registration with OTP on goes to /verify-mobile', redirectsTo($g, '/verify-mobile'));
$code = lastSmsCode('919111100004');
check('OTP sent by SMS (log driver)', $code !== null);
check('OTP stored hashed, not plain', !Database::value('SELECT 1 FROM otp_codes WHERE code_hash = ?', [(string) $code]));
$g->follow();
$g->post('/verify-mobile', ['code' => $code === '000000' ? '111111' : '000000'])->follow();
check('wrong OTP rejected', $g->has('incorrect or has expired'));
$g->post('/verify-mobile', ['code' => (string) $code]);
check('correct OTP verifies mobile', redirectsTo($g, '/account') && (bool) Database::value("SELECT mobile_verified_at IS NOT NULL FROM users WHERE email = 'neel@test.invalid'"));
check('used OTP cannot be reused', !App\Services\OtpService::verify('919111100004', 'verify_mobile', (string) $code));

$h = new Browser($base);
$h->get('/login?otp=1');
check('login page offers OTP login when enabled', $h->has('Send code'));
$h->post('/login/otp/send', ['mobile' => '9111100004']);
check('OTP login code sent', redirectsTo($h, '/login/otp'));
$h->follow();
$h->post('/login/otp', ['code' => (string) lastSmsCode('919111100004')]);
check('OTP login signs in', redirectsTo($h, '/account'));
$h->post('/logout');

$i = new Browser($base);
$i->get('/forgot-password');
$i->post('/forgot-password', ['identifier' => '9111100004']);
check('forgot password by mobile sends SMS code', redirectsTo($i, '/reset-password/otp'));
$i->follow();
$i->post('/reset-password/otp', ['code' => (string) lastSmsCode('919111100004'), 'password' => 'ViaOtp1234', 'password_confirmation' => 'ViaOtp1234']);
check('password reset via OTP works', redirectsTo($i, '/login'));
$i->get('/login');
$i->post('/login', ['identifier' => 'neel@test.invalid', 'password' => 'ViaOtp1234']);
check('login with OTP-reset password works', redirectsTo($i, '/account'));

$j = new Browser($base);
for ($k = 0; $k < 3; $k++) {
    $j->get('/login?otp=1');
    $j->post('/login/otp/send', ['mobile' => '9111100005']);
}
$j->get('/login?otp=1');
$j->post('/login/otp/send', ['mobile' => '9111100005'])->follow();
check('unregistered mobile gets no SMS', lastSmsCode('919111100005') === null);
Database::run("UPDATE settings SET setting_value = '0' WHERE setting_key = 'auth_otp_enabled'");

echo "\nAdmin area\n";
$f->get('/admin');
check('normal site login does not open /admin', redirectsTo($f, '/admin/login'));
$adm = new Browser($base);
$adm->get('/admin/login');
$adm->post('/admin/login', ['identifier' => 'asha@test.invalid', 'password' => 'Another789'])->follow();
check('non-admin cannot sign in to admin', $adm->has('Incorrect mobile/email or password'));
Database::run("INSERT INTO user_roles (user_id, role) VALUES (?, 'admin')", [$user['id']]);
$adm->post('/admin/login', ['identifier' => 'asha@test.invalid', 'password' => 'Another789']);
check('admin can sign in at /admin/login', redirectsTo($adm, '/admin'));
$adm->follow();
check('admin dashboard shows live user counts', $adm->status === 200 && $adm->has('Total users') && $adm->has('Recent login activity'));
$adm->get('/account');
check('admin cookie does not log into the public site', redirectsTo($adm, '/login?next=%2Faccount'));
Database::run("DELETE FROM user_roles WHERE user_id = ? AND role = 'admin'", [$user['id']]);
$adm->get('/admin');
check('removing admin role locks the admin out immediately', redirectsTo($adm, '/admin/login'));

echo "\nHeaders & privacy\n";
$ch = curl_init($base . '/login');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false]);
$headers = (string) curl_exec($ch);
curl_close($ch);
check('session cookie HttpOnly and SameSite=Lax', (bool) preg_match('/Set-Cookie: rw_sess=[^\n]*HttpOnly; SameSite=Lax/i', $headers));
check('Content-Security-Policy header sent', stripos($headers, 'Content-Security-Policy:') !== false);
$ch = curl_init($base . '/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
$headers = (string) curl_exec($ch);
curl_close($ch);
check('anonymous homepage visit creates no session', stripos($headers, 'Set-Cookie') === false);
$logs = implode('', array_map('file_get_contents', glob(APP_ROOT . '/storage/logs/app-*.log') ?: []));
check('no passwords written to app logs', !str_contains($logs, $pw) && !str_contains($logs, 'Another789'));

$cleanup();
echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
