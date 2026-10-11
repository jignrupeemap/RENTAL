<?php
declare(strict_types=1);

/**
 * Phase 8 end-to-end tests: the viewer dashboard (My Dashboard, Shortlisted,
 * Contacted, Booking / Visit Requests, Profile, password, notifications).
 * DEVELOPMENT ONLY (creates and removes @test.invalid accounts and listings
 * whose titles start with "P8T").
 *
 *   php -S 127.0.0.1:8088 -t public public/index.php &
 *   php tests/phase8_viewer_test.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

if (Config::isProduction() || Config::env('MAIL_DRIVER') !== 'log') {
    fwrite(STDERR, "Run only in development with MAIL_DRIVER=log.\n");
    exit(1);
}
require __DIR__ . '/lib/http.php';

$cleanup = static function (): void {
    $ids = array_map('intval', array_column(Database::all("SELECT id FROM users WHERE email LIKE 'p8-%@test.invalid'"), 'id')) ?: [0];
    $in = implode(',', $ids);
    $p = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM properties WHERE owner_id IN ($in) OR created_by IN ($in) OR title LIKE 'P8T%'"), 'id')) ?: [0]);
    $b = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM bookings WHERE property_id IN ($p) OR user_id IN ($in)"), 'id')) ?: [0]);
    foreach ([
        "DELETE FROM notifications WHERE user_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in)) OR (entity_type = 'property' AND entity_id IN ($p)) OR (entity_type = 'booking' AND entity_id IN ($b))",
        "DELETE FROM booking_status_history WHERE booking_id IN ($b)",
        "DELETE FROM bookings WHERE id IN ($b)",
        "DELETE FROM enquiries WHERE property_id IN ($p) OR user_id IN ($in)",
        "DELETE FROM shortlists WHERE property_id IN ($p) OR user_id IN ($in)",
        "DELETE FROM property_locations WHERE property_id IN ($p)",
        "DELETE FROM properties WHERE id IN ($p)",
        "DELETE FROM activity_logs WHERE user_id IN ($in)",
        "DELETE FROM login_activity WHERE user_id IN ($in)",
        "DELETE FROM user_sessions WHERE user_id IN ($in)",
        "DELETE FROM users WHERE id IN ($in)",
        'DELETE FROM rate_limits',
    ] as $sql) {
        try {
            Database::run($sql);
        } catch (Throwable) {
            // Table may not exist on an older schema (user_sessions); nothing to clean.
        }
    }
};
$cleanup();
Database::run("UPDATE settings SET setting_value = '0' WHERE setting_key = 'auth_otp_enabled'");

$pw = 'Secret123';
function registerUser(Browser $b, string $first, string $mobile, string $email): int
{
    global $pw;
    $b->get('/register');
    $b->post('/register', ['first_name' => $first, 'last_name' => 'Test', 'mobile' => $mobile, 'email' => $email,
        'password' => $pw, 'password_confirmation' => $pw, 'terms' => '1', 'website' => '']);
    return (int) Database::value('SELECT id FROM users WHERE email = ?', [$email]);
}
function mk(int $owner, string $title, int $rent, array $extra = []): int
{
    $t = Database::one("SELECT id, category_id FROM property_types WHERE code = 'flat'");
    $row = array_merge([
        'owner_id' => $owner, 'created_by' => $owner, 'purpose' => 'rent', 'category_id' => $t['category_id'], 'type_id' => $t['id'],
        'status' => 'active', 'draft_step' => 8, 'title' => $title, 'slug' => slugify($title), 'description' => 'Test listing.',
        'city_id' => 1, 'locality_id' => (int) Database::value("SELECT id FROM localities WHERE slug = 'vesu'"), 'pincode' => '395007',
        'rent' => $rent, 'security_deposit' => $rent * 2, 'bedrooms' => 2, 'bathrooms' => 2, 'furnishing' => 'semi_furnished',
        'contact_name' => 'Owner Private', 'contact_mobile_1' => '919222200899',
        'published_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 30),
    ], $extra);
    $cols = implode(', ', array_keys($row));
    $id = Database::insert("INSERT INTO properties ($cols) VALUES (" . rtrim(str_repeat('?, ', count($row)), ', ') . ')', array_values($row));
    Database::run("INSERT INTO property_locations (property_id, latitude, longitude, public_latitude, public_longitude, privacy)
                   VALUES (?, 21.1418, 72.7709, 21.1440, 72.7731, 'approximate')", [$id]);
    return $id;
}
function booking(int $property, int $owner, int $user, string $status, array $extra = []): int
{
    $row = array_merge([
        'property_id' => $property, 'owner_id' => $owner, 'user_id' => $user, 'preferred_date' => gmdate('Y-m-d', time() + 86400 * 3),
        'preferred_time' => '11:00:00', 'message' => 'Can I see it on Saturday?', 'status' => $status,
        'property_title' => (string) Database::value('SELECT title FROM properties WHERE id = ?', [$property]),
        'rent_at_request' => (int) Database::value('SELECT rent FROM properties WHERE id = ?', [$property]),
    ], $extra);
    $cols = implode(', ', array_keys($row));
    return Database::insert("INSERT INTO bookings ($cols) VALUES (" . rtrim(str_repeat('?, ', count($row)), ', ') . ')', array_values($row));
}
function bk(int $id): array { return Database::one('SELECT * FROM bookings WHERE id = ?', [$id]); }

// ---------------------------------------------------------------------
echo "Setup\n";
// ---------------------------------------------------------------------
$o = new Browser($base);
$owner = registerUser($o, 'Omkar', '9222200801', 'p8-owner@test.invalid');
$v = new Browser($base);
$viewer = registerUser($v, 'Vidya', '9222200802', 'p8-viewer@test.invalid');
$x = new Browser($base);
$other = registerUser($x, 'Xavier', '9222200803', 'p8-other@test.invalid');
check('test accounts ready', $owner && $viewer && $other);

$L1 = mk($owner, 'P8T Live Flat <i>x</i>', 21000);
$L2 = mk($owner, 'P8T Paused Flat', 15000, ['status' => 'paused']);
$L3 = mk($owner, 'P8T Deleted Flat', 16000, ['status' => 'deleted', 'deleted_at' => gmdate('Y-m-d H:i:s')]);
foreach ([$L1, $L2, $L3] as $i => $p) {
    Database::run('INSERT INTO shortlists (user_id, property_id, created_at) VALUES (?, ?, ?)', [$viewer, $p, gmdate('Y-m-d H:i:s', time() - 300 + $i)]);
    Database::run('UPDATE properties SET shortlists_count = 1 WHERE id = ?', [$p]);
}
Database::run("INSERT INTO enquiries (source, property_id, owner_id, user_id, first_name, last_name, mobile_1, property_title, owner_name, rent_at_enquiry, message)
               VALUES ('property_page', ?, ?, ?, 'Vidya', 'Test', '919222200802', 'P8T Live Flat (old title)', 'Omkar T.', 19000, 'Is parking included?')",
    [$L1, $owner, $viewer]);
Database::run("INSERT INTO enquiries (source, property_id, owner_id, user_id, first_name, last_name, mobile_1, property_title, owner_name, rent_at_enquiry, message)
               VALUES ('property_page', ?, ?, ?, 'Vidya', 'Test', '919222200802', 'P8T Paused Flat', 'Omkar T.', 15000, 'Still free?')",
    [$L2, $owner, $viewer]);
$Bpending = booking($L1, $owner, $viewer, 'pending');
$Bresched = booking($L1, $owner, $viewer, 'rescheduled', ['proposed_date' => gmdate('Y-m-d', time() + 86400 * 5), 'proposed_time' => '16:30:00',
    'owner_response' => 'Saturday is busy, how about this?']);
$Baccepted = booking($L1, $owner, $viewer, 'accepted');
$Brejected = booking($L2, $owner, $viewer, 'rejected', ['owner_response' => 'Already let out.']);
$Bother = booking($L1, $owner, $other, 'pending');
Database::run("INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by, actor_role, note) VALUES (?, 'pending', 'rescheduled', ?, 'owner', 'Proposed a new time')",
    [$Bresched, $owner]);
Database::run("INSERT INTO notifications (audience, user_id, type, title, body, link_url) VALUES ('user', ?, 'test', 'P8T Notice one', 'Body', '/account/bookings')", [$viewer]);
Database::run("INSERT INTO notifications (audience, user_id, type, title, body, link_url) VALUES ('user', ?, 'test', 'P8T Notice evil', 'Body', 'https://evil.example/phish')", [$viewer]);
Database::run("INSERT INTO notifications (audience, user_id, type, title, body, link_url) VALUES ('user', ?, 'test', 'P8T Other user notice', 'Body', NULL)", [$other]);
check('test data created', $L1 && $Bother);

// ---------------------------------------------------------------------
echo "\nLogin required\n";
// ---------------------------------------------------------------------
$g = new Browser($base);
foreach (['/account', '/account/shortlist', '/account/contacted', '/account/bookings', '/account/profile'] as $path) {
    $g->get($path);
    check("guest $path → login", $g->status === 302 && str_contains($g->location, '/login'));
}

// ---------------------------------------------------------------------
echo "\nMy Dashboard\n";
// ---------------------------------------------------------------------
$v->get('/account');
check('dashboard opens', $v->status === 200 && $v->has('My Dashboard'));
check('menu has every section', $v->has('Shortlisted Properties') && $v->has('Contacted Properties') && $v->has('Booking / Visit Requests') && $v->has('Profile') && $v->has('Logout'));
$c = App\Repositories\ViewerRepository::counts($viewer);
check('counts are real (3 shortlisted, 2 contacted, 4 visits, 2 waiting, 1 upcoming)',
    (int) $c['shortlisted'] === 3 && (int) $c['contacted'] === 2 && (int) $c['visits'] === 4 && (int) $c['visits_waiting'] === 2 && (int) $c['visits_upcoming'] === 1,
    json_encode($c));
check('own notifications shown, other user\'s not', $v->has('P8T Notice one') && !$v->has('P8T Other user notice'));
check('off-site notification link is not linked', !str_contains($v->body, 'evil.example'));
check('owner call-to-action shown to a viewer', $v->has('Own a property?'));
check('page is escaped', !str_contains($v->body, '<i>x</i>'));
check('account pages are noindex', $v->has('noindex'));

// ---------------------------------------------------------------------
echo "\nShortlisted Properties\n";
// ---------------------------------------------------------------------
$v->get('/account/shortlist');
check('shortlist page lists all three', $v->status === 200 && $v->has('3 properties saved') && $v->has('P8T Live Flat <i>x</i>') && $v->has('P8T Paused Flat') && $v->has('P8T Deleted Flat'));
check('title escaped', !str_contains($v->body, '<i>x</i>'));
check('live listing has View Property link', $v->has('View Property') && str_contains($v->body, '-p' . $L1 . '"'));
check('paused listing labelled, not linked', $v->has('Paused by the owner') && !str_contains($v->body, '-p' . $L2 . '"'));
check('deleted listing labelled Removed', $v->has('Removed') && !str_contains($v->body, '-p' . $L3 . '"'));
check('newest first', strpos($v->body, 'P8T Deleted Flat') < strpos($v->body, 'P8T Paused Flat'));
$v->post('/shortlist/' . $L3, ['state' => 'off', 'return' => '/account/shortlist']);
check('Remove (deleted listing) goes back to the shortlist', $v->status === 302 && str_contains($v->location, '/account/shortlist'));
check('removed from the database, count kept in step', !Database::value('SELECT 1 FROM shortlists WHERE user_id = ? AND property_id = ?', [$viewer, $L3])
    && (int) Database::value('SELECT shortlists_count FROM properties WHERE id = ?', [$L3]) === 0);
$v->follow();
check('page shows 2 left and a message', $v->has('2 properties saved') && $v->has('Removed from your shortlist.') && !$v->has('P8T Deleted Flat'));
$v->post('/shortlist/' . $L1, ['state' => 'off', 'return' => 'https://evil.example/']);
check('off-site return refused', $v->status === 302 && !str_contains($v->location, 'evil.example'));
$x->get('/account/shortlist');
check('another user sees only their own (empty) shortlist', $x->has('Nothing shortlisted yet') && !$x->has('P8T Paused Flat'));

// ---------------------------------------------------------------------
echo "\nContacted Properties\n";
// ---------------------------------------------------------------------
$v->get('/account/contacted');
check('contacted page lists both enquiries', $v->status === 200 && $v->has('P8T Live Flat (old title)') && $v->has('P8T Paused Flat'));
check('shows what was seen at the time (title, owner, rent)', $v->has('Omkar T.') && $v->has('₹19,000') && $v->has('Is parking included?'));
check('shows the rent now when it changed', $v->has('₹21,000'));
check('paused listing still in history, labelled', $v->has('Paused by the owner'));
$x->get('/account/contacted');
check('another user does not see these enquiries', !$x->has('Is parking included?'));

// ---------------------------------------------------------------------
echo "\nBooking / Visit Requests\n";
// ---------------------------------------------------------------------
$v->get('/account/bookings');
check('bookings page with tabs and counts', $v->status === 200 && $v->has('All') && $v->has('Open') && $v->has('Past'));
check('shows owner proposal and note', $v->has('Owner proposed') && $v->has('04:30 PM') && $v->has('Saturday is busy, how about this?'));
check('history trail shown', $v->has('Proposed a new time'));
check('other user\'s booking not shown', substr_count($v->body, 'action="' . $base . '/account/bookings/' . $Bother . '"') === 0);
$v->get('/account/bookings?status=open');
check('Open tab: no rejected request', !$v->has('Already let out.') && $v->has('Owner proposed'));
$v->get('/account/bookings?status=past');
check('Past tab: rejected request only', $v->has('Already let out.') && !$v->has('Owner proposed'));
$v->get('/account/bookings?status=evil');
check('unknown tab falls back to All', $v->status === 200 && $v->has('Already let out.') && $v->has('Owner proposed'));
check('rejected request has no buttons', !str_contains($v->body, 'action="' . $base . '/account/bookings/' . $Brejected . '"'));

$v->get('/account/bookings');
$v->post('/account/bookings/' . $Bresched, ['action' => 'accept_time', 'tab' => 'open']);
$b = bk($Bresched);
check('Accept new time → accepted at the proposed time', $v->status === 302 && str_contains($v->location, '/account/bookings?status=open')
    && $b['status'] === 'accepted' && $b['preferred_time'] === '16:30:00' && $b['preferred_date'] === $b['proposed_date']);
check('history row written as viewer', (bool) Database::value("SELECT 1 FROM booking_status_history WHERE booking_id = ? AND new_status = 'accepted' AND actor_role = 'viewer' AND changed_by = ?", [$Bresched, $viewer]));
check('owner notified', (bool) Database::value("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'booking_time_accepted' AND entity_id = ?", [$owner, $Bresched]));
$v->follow();
check('success message', $v->has('New time accepted'));

$v->post('/account/bookings/' . $Bresched, ['action' => 'accept_time']);
check('accepting twice is refused', bk($Bresched)['status'] === 'accepted'
    && (int) Database::value("SELECT COUNT(*) FROM booking_status_history WHERE booking_id = ? AND new_status = 'accepted'", [$Bresched]) === 1);
$v->get('/account/bookings');
$v->post('/account/bookings/' . $Bpending, ['action' => 'accept_time']);
check('accept_time on a pending request refused', bk($Bpending)['status'] === 'pending');
$v->get('/account/bookings');
$v->post('/account/bookings/' . $Bpending, ['action' => 'cancel']);
$b = bk($Bpending);
check('Cancel request → cancelled by viewer', $b['status'] === 'cancelled' && $b['cancelled_by'] === 'viewer');
check('owner notified of cancellation', (bool) Database::value("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'booking_cancelled_by_viewer' AND entity_id = ?", [$owner, $Bpending]));
$v->get('/account/bookings');
$v->post('/account/bookings/' . $Brejected, ['action' => 'cancel']);
check('cannot cancel a rejected request', bk($Brejected)['status'] === 'rejected');

$v->get('/account/bookings');
$v->post('/account/bookings/' . $Bother, ['action' => 'cancel']);
check('another user\'s booking id: not found, unchanged', bk($Bother)['status'] === 'pending');
$v->follow();
check('… with a clear message', $v->has('Visit request not found.'));
$v->post('/account/bookings/' . $Baccepted, ['action' => 'cancel'], false);
check('no CSRF token → refused', $v->status === 419 && bk($Baccepted)['status'] === 'accepted');

Database::run("UPDATE users SET status = 'suspended', status_reason = 'Test' WHERE id = ?", [$viewer]);
$v->get('/account/bookings');
check('suspended viewer can still read bookings', $v->status === 200 && $v->has('Booking / Visit Requests'));
$v->post('/account/bookings/' . $Baccepted, ['action' => 'cancel']);
check('suspended viewer cannot cancel', $v->status === 302 && str_contains($v->location, '/account') && bk($Baccepted)['status'] === 'accepted');
Database::run("UPDATE users SET status = 'active', status_reason = NULL WHERE id = ?", [$viewer]);

// ---------------------------------------------------------------------
echo "\nNotifications\n";
// ---------------------------------------------------------------------
$v->get('/account');
$v->post('/account/notifications/read');
check('Mark all read → own notifications read', $v->status === 302
    && (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$viewer]) === 0);
check('other user\'s notifications untouched', (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$other]) === 1);

// ---------------------------------------------------------------------
echo "\nProfile\n";
// ---------------------------------------------------------------------
$v->get('/account/profile');
check('profile page opens with password form', $v->status === 200 && $v->has('Change password') && $v->has('Your mobile number is your login'));
$v->post('/account/profile', ['first_name' => '', 'last_name' => 'Test', 'email' => 'not-an-email']);
check('invalid profile → back with errors', $v->status === 302 && str_contains($v->location, '/account/profile'));
$v->follow();
check('errors shown', $v->has('is-invalid'));
$v->post('/account/profile', ['first_name' => 'Vidya', 'last_name' => 'Test', 'email' => 'p8-other@test.invalid']);
check('email of another account refused', (string) Database::value('SELECT email FROM users WHERE id = ?', [$viewer]) === 'p8-viewer@test.invalid');
$v->get('/account/profile');
$v->post('/account/profile', ['first_name' => 'Vidyaa', 'last_name' => 'Tester', 'email' => 'p8-viewer2@test.invalid', 'mobile' => '9999999999', 'id' => (string) $other]);
$u = Database::one('SELECT * FROM users WHERE id = ?', [$viewer]);
check('profile saved', $u['first_name'] === 'Vidyaa' && $u['last_name'] === 'Tester' && $u['email'] === 'p8-viewer2@test.invalid');
check('mobile cannot be changed from the form', $u['mobile'] === '919222200802');
check('posted id ignored (other user untouched)', Database::value('SELECT first_name FROM users WHERE id = ?', [$other]) === 'Xavier');
check('changed email needs verifying again', $u['email_verified_at'] === null && lastMailLink('p8-viewer2@test.invalid', '/verify-email') !== null);

$v->get('/account/profile');
$v->post('/account/password', ['current_password' => 'Wrong123', 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456']);
check('wrong current password → back to #password', $v->status === 302 && str_contains($v->location, '/account/profile#password'));
check('password unchanged', password_verify($pw, (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$viewer])));
$v->get('/account/profile');
$v->post('/account/password', ['current_password' => $pw, 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456']);
$hash = (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$viewer]);
check('password changed and hashed', $v->status === 302 && str_contains($v->location, '/account/profile') && password_verify('NewSecret456', $hash) && !str_contains($hash, 'NewSecret456'));
$v->get('/account');
check('still logged in on this device', $v->status === 200);

// ---------------------------------------------------------------------
echo "\nOwner sees the dashboard too\n";
// ---------------------------------------------------------------------
$o->get('/account');
check('owner can open My Dashboard with Owner Dashboard link', $o->status === 200 && str_contains($o->body, url('/owner')));

$cleanup();
echo "\n", $fail === 0 ? "ALL {$pass} CHECKS PASSED" : "{$fail} FAILED, {$pass} passed", PHP_EOL;
exit($fail === 0 ? 0 : 1);
