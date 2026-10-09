<?php
declare(strict_types=1);

/**
 * Phase 9 end-to-end tests: Contact Owner (enquiry with property snapshot,
 * contact history, notifications), Request Visit (validation, one open
 * request per listing, owner flow round trip) and the "Post your
 * requirement" form with admin follow-up.
 * DEVELOPMENT ONLY (creates and removes @test.invalid accounts and listings
 * whose titles start with "P9T").
 *
 *   php -S 127.0.0.1:8088 -t public public/index.php &
 *   php tests/phase9_contact_test.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\BookingService;

if (Config::isProduction() || Config::env('MAIL_DRIVER') !== 'log') {
    fwrite(STDERR, "Run only in development with MAIL_DRIVER=log.\n");
    exit(1);
}
require __DIR__ . '/lib/http.php';

$cleanup = static function (): void {
    $ids = array_map('intval', array_column(Database::all("SELECT id FROM users WHERE email LIKE 'p9-%@test.invalid'"), 'id')) ?: [0];
    $in = implode(',', $ids);
    $p = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM properties WHERE owner_id IN ($in) OR created_by IN ($in) OR title LIKE 'P9T%'"), 'id')) ?: [0]);
    $b = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM bookings WHERE property_id IN ($p) OR user_id IN ($in)"), 'id')) ?: [0]);
    $q = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM enquiries WHERE property_id IN ($p) OR user_id IN ($in) OR mobile_1 LIKE '9192222009%'"), 'id')) ?: [0]);
    foreach ([
        "DELETE FROM notifications WHERE user_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in)) OR (entity_type = 'booking' AND entity_id IN ($b)) OR (entity_type = 'enquiry' AND entity_id IN ($q))",
        "DELETE FROM booking_status_history WHERE booking_id IN ($b)",
        "DELETE FROM bookings WHERE id IN ($b)",
        "DELETE FROM contact_history WHERE enquiry_id IN ($q) OR user_id IN ($in) OR property_id IN ($p)",
        "DELETE FROM enquiries WHERE id IN ($q)",
        "DELETE FROM shortlists WHERE property_id IN ($p) OR user_id IN ($in)",
        "DELETE FROM property_locations WHERE property_id IN ($p)",
        "DELETE FROM properties WHERE id IN ($p)",
        "DELETE FROM admin_activity_logs WHERE admin_id IN ($in)",
        "DELETE FROM activity_logs WHERE user_id IN ($in)",
        "DELETE FROM login_activity WHERE user_id IN ($in)",
        "DELETE FROM user_sessions WHERE user_id IN ($in)",
        "DELETE FROM user_roles WHERE user_id IN ($in)",
        "DELETE FROM email_queue WHERE to_email LIKE 'p9-%@test.invalid'",
        "DELETE FROM users WHERE id IN ($in)",
        'DELETE FROM rate_limits',
    ] as $sql) {
        Database::run($sql);
    }
};
$cleanup();
$setting = static fn (string $k, string $v) => Database::run('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$v, $k]);
$savedEmail = (string) Database::value("SELECT setting_value FROM settings WHERE setting_key = 'email_notifications_enabled'");
$setting('auth_otp_enabled', '0');
$setting('email_notifications_enabled', '1');

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
        'rent' => $rent, 'security_deposit' => $rent * 2, 'maintenance' => 1500, 'bedrooms' => 2, 'bathrooms' => 2, 'furnishing' => 'semi_furnished',
        'contact_name' => 'Owner Private', 'contact_mobile_1' => '919222200999', 'contact_email' => 'p9-listing-contact@test.invalid',
        'published_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 30),
    ], $extra);
    $cols = implode(', ', array_keys($row));
    $id = Database::insert("INSERT INTO properties ($cols) VALUES (" . rtrim(str_repeat('?, ', count($row)), ', ') . ')', array_values($row));
    Database::run("INSERT INTO property_locations (property_id, latitude, longitude, public_latitude, public_longitude, privacy)
                   VALUES (?, 21.1418, 72.7709, 21.1440, 72.7731, 'approximate')", [$id]);
    return $id;
}
function prop(int $id): array { return Database::one('SELECT * FROM properties WHERE id = ?', [$id]); }
function lastEnquiry(int $userId): ?array { return Database::one('SELECT * FROM enquiries WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]); }
function lastBooking(int $userId): ?array { return Database::one('SELECT * FROM bookings WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]); }
$istDay = static fn (int $days): string => gmdate('Y-m-d', time() + 19800 + $days * 86400);

// ---------------------------------------------------------------------
echo "Setup\n";
// ---------------------------------------------------------------------
$o = new Browser($base);
$owner = registerUser($o, 'Omkar', '9222200901', 'p9-owner@test.invalid');
$v = new Browser($base);
$viewer = registerUser($v, 'Vidya', '9222200902', 'p9-viewer@test.invalid');
$x = new Browser($base);
$other = registerUser($x, 'Xavier', '9222200903', 'p9-other@test.invalid');
$adminId = registerUser(new Browser($base), 'Asha', '9222200904', 'p9-admin@test.invalid');
Database::run("INSERT INTO user_roles (user_id, role) VALUES (?, 'admin')", [$adminId]);
$L1 = mk($owner, 'P9T Vesu Flat <b>x</b>', 21000);
$L2 = mk($owner, 'P9T Paused Flat', 15000, ['status' => 'paused']);
$L3 = mk($owner, 'P9T Second Flat', 18000);
check('test data ready', $owner && $viewer && $other && $adminId && $L1);
$url1 = property_url(prop($L1));

// ---------------------------------------------------------------------
echo "\nProperty page buttons\n";
// ---------------------------------------------------------------------
$g = new Browser($base);
$g->get($url1);
check('guest sees Contact Owner and Request Visit, linked to login and back',
    $g->has('Contact Owner') && $g->has('Request Visit')
    && str_contains($g->body, '/login?next=' . rawurlencode('/property/' . $L1 . '/contact'))
    && str_contains($g->body, '/login?next=' . rawurlencode('/property/' . $L1 . '/visit')));
check('owner details still not on the public page', !$g->has('919222200999') && !$g->has('92222 00999') && !$g->has('p9-listing-contact@test.invalid') && !$g->has('Owner Private'));
$g->get('/property/' . $L1 . '/contact');
check('guest contact page → login with next', $g->status === 302 && str_contains($g->location, '/login?next=' . rawurlencode('/property/' . $L1 . '/contact')));
$g->post('/property/' . $L1 . '/contact', ['first_name' => 'Guest', 'last_name' => 'X', 'mobile_1' => '9222200999'], false);
check('guest POST refused', in_array($g->status, [302, 419], true) && !Database::value("SELECT 1 FROM enquiries WHERE property_id = ?", [$L1]));
$o->get($url1);
check('owner sees no contact buttons on own listing', $o->has('This is your listing') && !str_contains($o->body, '/property/' . $L1 . '/contact'));
$o->get('/property/' . $L1 . '/contact');
check('owner opening own contact page goes back to listing', $o->status === 302 && str_contains($o->location, $url1));
$v->get($url1);
check('viewer gets direct links', str_contains($v->body, 'href="' . $base . '/property/' . $L1 . '/contact"') && str_contains($v->body, 'href="' . $base . '/property/' . $L1 . '/visit"'));

// ---------------------------------------------------------------------
echo "\nContact Owner\n";
// ---------------------------------------------------------------------
$v->get('/property/' . $L1 . '/contact');
check('contact page opens with the listing and prefilled details', $v->status === 200 && $v->has('P9T Vesu Flat <b>x</b>') && !str_contains($v->body, '<b>x</b>')
    && str_contains($v->body, 'value="Vidya"') && str_contains($v->body, 'value="9222200902"') && str_contains($v->body, 'value="p9-viewer@test.invalid"'));
check('contact page is noindex', $v->has('noindex'));
$v->post('/property/' . $L1 . '/contact', ['first_name' => '', 'last_name' => 'Test', 'mobile_1' => '12345', 'mobile_2' => '9222200902', 'email' => 'bad', 'message' => str_repeat('a', 1001)]);
check('invalid form → back to the form', $v->status === 302 && str_contains($v->location, '/property/' . $L1 . '/contact'));
$v->follow();
check('errors shown, nothing saved', $v->has('First name is required') && $v->has('valid 10-digit') && $v->has('valid email')
    && $v->has('at most 1000') && lastEnquiry($viewer) === null);
$v->post('/property/' . $L1 . '/contact', ['first_name' => 'Vidya', 'last_name' => 'Test', 'mobile_1' => '9222200902', 'mobile_2' => '9222200902']);
$v->follow();
check('alternate mobile equal to the first is refused', $v->has('same as the first') && lastEnquiry($viewer) === null);
$v->post('/property/' . $L1 . '/contact', [
    'first_name' => 'Vidya', 'last_name' => 'Test', 'mobile_1' => '+91 92222 00902', 'mobile_2' => '9222200912', 'email' => 'P9-Viewer@test.invalid',
    'message' => "Is parking included?\r\n<script>alert(1)</script>",
    // Tampering: these must be ignored, the server attaches the listing itself.
    'property_title' => 'Hacked', 'owner_id' => (string) $other, 'rent_at_enquiry' => '1', 'property_id' => (string) $L3, 'user_id' => (string) $other,
]);
check('sent → back to the property page', $v->status === 302 && str_contains($v->location, $url1));
$q = lastEnquiry($viewer);
check('enquiry saved for this viewer and listing', $q !== null && (int) $q['property_id'] === $L1 && $q['source'] === 'property_page' && $q['status'] === 'new');
check('form tampering ignored (owner, title, rent from the database)', $q && (int) $q['owner_id'] === $owner && $q['property_title'] === 'P9T Vesu Flat <b>x</b>'
    && (int) $q['rent_at_enquiry'] === 21000 && !Database::value('SELECT 1 FROM enquiries WHERE user_id = ?', [$other]));
check('mobiles normalised, email lower-cased', $q && $q['mobile_1'] === '919222200902' && $q['mobile_2'] === '919222200912' && $q['email'] === 'p9-viewer@test.invalid');
check('snapshot: URL, owner name, interest, city, details JSON', $q && $q['property_url'] === $base . $url1 && $q['owner_name'] === 'Omkar Test'
    && $q['interested_in'] === 'rent' && (int) $q['city_id'] === 1 && ($snap = json_decode((string) $q['property_snapshot'], true)) && (int) $snap['rent'] === 21000 && (int) $snap['maintenance'] === 1500);
check('message stored as text', $q && str_contains((string) $q['message'], '<script>alert(1)</script>'));
check('contact history row', (bool) Database::value("SELECT 1 FROM contact_history WHERE enquiry_id = ? AND user_id = ? AND property_id = ? AND action = 'enquiry' AND rent_at_contact = 21000", [$q['id'], $viewer, $L1]));
check('listing enquiry count +1', (int) prop($L1)['enquiries_count'] === 1);
check('owner notified (link to enquiries)', (bool) Database::value("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'new_enquiry' AND entity_id = ? AND link_url = '/owner/enquiries'", [$owner, $q['id']]));
check('admin notified', (bool) Database::value("SELECT 1 FROM notifications WHERE audience = 'admin' AND type = 'new_enquiry' AND entity_id = ?", [$q['id']]));
check('owner emailed at the listing contact email', (bool) Database::value("SELECT 1 FROM email_queue WHERE to_email = 'p9-listing-contact@test.invalid' AND subject = 'New enquiry for your property'"));
check('activity logged as contacted', (bool) Database::value("SELECT 1 FROM activity_logs WHERE user_id = ? AND event = 'contacted' AND entity_id = ?", [$viewer, $L1]));
$v->follow();
check('success message and "you contacted" note', $v->has('Your message has been sent to the owner') && $v->has('You contacted the owner on'));

$v->get('/property/' . $L1 . '/contact');
check('contact page shows last contact date', $v->has('You last contacted the owner'));
$v->post('/property/' . $L1 . '/contact', ['first_name' => 'Vidya', 'last_name' => 'Test', 'mobile_1' => '9222200902', 'message' => 'Again']);
check('second message within 24 hours refused', (int) Database::value('SELECT COUNT(*) FROM enquiries WHERE user_id = ? AND property_id = ?', [$viewer, $L1]) === 1);
$v->follow();
check('… with a clear message', $v->has('already sent the owner a message'));

$v->get('/property/' . $L2 . '/contact');
check('paused listing: contact page is 410', $v->status === 410);
$v->get($url1);
$v->post('/property/' . $L2 . '/contact', ['first_name' => 'Vidya', 'last_name' => 'Test', 'mobile_1' => '9222200902']);
check('paused listing: POST refused', !Database::value('SELECT 1 FROM enquiries WHERE property_id = ?', [$L2]));
$o->get($url1);
$o->post('/property/' . $L1 . '/contact', ['first_name' => 'Omkar', 'last_name' => 'Test', 'mobile_1' => '9222200901']);
check('owner cannot enquire on own listing', !Database::value('SELECT 1 FROM enquiries WHERE user_id = ?', [$owner]));
$x->get('/property/' . $L3 . '/contact');
$x->post('/property/' . $L3 . '/contact', ['first_name' => 'Xavier', 'last_name' => 'Test', 'mobile_1' => '9222200903'], false);
check('missing CSRF token refused', $x->status === 419 && !Database::value('SELECT 1 FROM enquiries WHERE user_id = ?', [$other]));

Database::run("UPDATE users SET status = 'suspended', status_reason = 'Test' WHERE id = ?", [$other]);
$x->get('/property/' . $L3 . '/contact');
check('suspended viewer sees why instead of the form', $x->status === 200 && $x->has("can't contact owners right now") && !str_contains($x->body, 'name="mobile_1"'));
$x->get($url1);
$x->post('/property/' . $L3 . '/contact', ['first_name' => 'Xavier', 'last_name' => 'Test', 'mobile_1' => '9222200903']);
check('suspended viewer POST refused', !Database::value('SELECT 1 FROM enquiries WHERE user_id = ?', [$other]));
Database::run("UPDATE users SET status = 'blocked' WHERE id = ?", [$other]);
$x->get('/property/' . $L3 . '/contact');
check('blocked viewer is signed out and sent to login', $x->status === 302 && str_contains($x->location, '/login'));
Database::run("UPDATE users SET status = 'active', status_reason = NULL WHERE id = ?", [$other]);

Database::run("UPDATE users SET status = 'suspended' WHERE id = ?", [$owner]);
$v->get('/property/' . $L3 . '/contact');
check('owner suspended → listing gone, cannot be contacted', $v->status === 410);
Database::run("UPDATE users SET status = 'active' WHERE id = ?", [$owner]);

$o->get('/owner/enquiries');
check('owner inbox shows the enquiry with both mobiles and email', $o->has('Vidya Test') && $o->has('Is parking included?') && str_contains($o->body, 'tel:+919222200912')
    && $o->has('p9-viewer@test.invalid') && !str_contains($o->body, '<script>alert(1)</script>'));
$v->get('/account/contacted');
check('viewer sees it in Contacted Properties', $v->has('P9T Vesu Flat <b>x</b>') && $v->has('Omkar Test') && $v->has('₹21,000'));
Database::run('UPDATE properties SET rent = 23000 WHERE id = ?', [$L1]);
$v->get('/account/contacted');
check('rent change later keeps the original rent', $v->has('₹21,000') && $v->has('now ₹23,000') && (int) lastEnquiry($viewer)['rent_at_enquiry'] === 21000);

// Rate limit: enquiry_user is 20 per hour.
Database::run('DELETE FROM rate_limits');
Database::run("INSERT INTO rate_limits (bucket, hits, reset_at) SELECT bucket, 20, UTC_TIMESTAMP() + INTERVAL 1 HOUR FROM (SELECT ? AS bucket) t",
    [(new ReflectionMethod(App\Core\RateLimiter::class, 'bucket'))->invoke(null, 'enquiry_user', (string) $viewer)]);
$v->get('/property/' . $L3 . '/contact');
$v->post('/property/' . $L3 . '/contact', ['first_name' => 'Vidya', 'last_name' => 'Test', 'mobile_1' => '9222200902']);
check('hourly limit stops message floods', !Database::value('SELECT 1 FROM enquiries WHERE user_id = ? AND property_id = ?', [$viewer, $L3]));
Database::run('DELETE FROM rate_limits');

// ---------------------------------------------------------------------
echo "\nRequest Visit\n";
// ---------------------------------------------------------------------
$v->get('/property/' . $L1 . '/visit');
check('visit page opens with date limits and time slots', $v->status === 200 && $v->has('Request a visit')
    && str_contains($v->body, 'min="' . $istDay(0) . '"') && str_contains($v->body, 'max="' . $istDay(60) . '"')
    && str_contains($v->body, '<option value="08:00">08:00 AM</option>') && str_contains($v->body, '<option value="20:00">08:00 PM</option>'));
$bad = [
    'missing date'        => ['preferred_date' => '', 'preferred_time' => '11:00'],
    'past date'           => ['preferred_date' => $istDay(-1), 'preferred_time' => '11:00'],
    'too far ahead'       => ['preferred_date' => $istDay(61), 'preferred_time' => '11:00'],
    'nonsense date'       => ['preferred_date' => '2026-02-31', 'preferred_time' => '11:00'],
    'time not in the list' => ['preferred_date' => $istDay(2), 'preferred_time' => '03:00'],
    'odd time format'     => ['preferred_date' => $istDay(2), 'preferred_time' => '11:00:00\' OR 1'],
];
foreach ($bad as $label => $data) {
    $v->get('/property/' . $L1 . '/visit');
    $v->post('/property/' . $L1 . '/visit', $data);
    check("visit refused: $label", $v->status === 302 && str_contains($v->location, '/property/' . $L1 . '/visit') && lastBooking($viewer) === null);
}
$v->follow();
check('visit errors shown', $v->has('Please choose a time'));
$v->post('/property/' . $L1 . '/visit', ['preferred_date' => $istDay(0), 'preferred_time' => gmdate('H', time() + 19800) . ':00']);
check('today needs at least an hour from now', lastBooking($viewer) === null);

$date = $istDay(3);
$v->get('/property/' . $L1 . '/visit');
$v->post('/property/' . $L1 . '/visit', ['preferred_date' => $date, 'preferred_time' => '10:30', 'message' => 'Coming with family',
    'status' => 'accepted', 'owner_id' => (string) $other, 'user_id' => (string) $other]);
$bk = lastBooking($viewer);
check('visit requested → open visits in My Account', $v->status === 302 && str_contains($v->location, '/account/bookings?status=open'));
check('booking saved as pending with snapshot (tampering ignored)', $bk !== null && $bk['status'] === 'pending' && (int) $bk['owner_id'] === $owner
    && $bk['preferred_date'] === $date && $bk['preferred_time'] === '10:30:00' && $bk['message'] === 'Coming with family'
    && $bk['property_title'] === 'P9T Vesu Flat <b>x</b>' && (int) $bk['rent_at_request'] === 23000);
check('history starts with "Visit requested"', (bool) Database::value("SELECT 1 FROM booking_status_history WHERE booking_id = ? AND old_status IS NULL AND new_status = 'pending' AND actor_role = 'viewer'", [$bk['id']]));
check('listing booking count +1', (int) prop($L1)['bookings_count'] === 1);
check('owner and admin notified, owner emailed', Database::value("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'new_booking' AND entity_id = ?", [$owner, $bk['id']])
    && Database::value("SELECT 1 FROM notifications WHERE audience = 'admin' AND type = 'new_booking' AND entity_id = ?", [$bk['id']])
    && Database::value("SELECT 1 FROM email_queue WHERE to_email = 'p9-listing-contact@test.invalid' AND subject = 'New visit request for your property'"));
check('activity logged as booking_requested', (bool) Database::value("SELECT 1 FROM activity_logs WHERE user_id = ? AND event = 'booking_requested'", [$viewer]));
$v->follow();
check('viewer sees the pending request', $v->has('Visit requested.') && $v->has('P9T Vesu Flat <b>x</b>') && $v->has('10:30 AM'));
$v->get($url1);
check('property page shows "Visit requested" instead of the button', $v->has('Visit requested for') && !str_contains($v->body, 'href="' . $base . '/property/' . $L1 . '/visit"'));
$v->get('/property/' . $L1 . '/visit');
check('visit page explains the open request', $v->has('You already asked to visit') && !str_contains($v->body, 'name="preferred_date"'));
$v->get($url1);
$v->post('/property/' . $L1 . '/visit', ['preferred_date' => $istDay(4), 'preferred_time' => '11:00']);
check('second open request for the same listing refused', (int) Database::value('SELECT COUNT(*) FROM bookings WHERE user_id = ? AND property_id = ?', [$viewer, $L1]) === 1);

// Owner reschedules → viewer accepts the new time (Phase 3 + 8 flows on a real request).
$o->get('/owner/bookings');
$o->post('/owner/bookings/' . $bk['id'], ['action' => 'reschedule', 'date' => $istDay(5), 'time' => '17:00', 'note' => 'Evening works better']);
$bk = lastBooking($viewer);
check('owner can reschedule the new request', $bk['status'] === 'rescheduled' && $bk['proposed_date'] === $istDay(5), $bk['status']);
check('viewer notified of the new time', (bool) Database::value("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'booking_rescheduled' AND entity_id = ?", [$viewer, $bk['id']]));
$v->get('/account/bookings');
$v->post('/account/bookings/' . $bk['id'], ['action' => 'accept_time']);
check('viewer accepts the new time', lastBooking($viewer)['status'] === 'accepted' && substr((string) lastBooking($viewer)['preferred_time'], 0, 5) === '17:00');
$v->get('/account/bookings');
$v->post('/account/bookings/' . $bk['id'], ['action' => 'cancel']);
$v->get($url1);
check('after cancelling, Request Visit is offered again', str_contains($v->body, 'href="' . $base . '/property/' . $L1 . '/visit"'));
$v->post('/property/' . $L2 . '/visit', ['preferred_date' => $istDay(2), 'preferred_time' => '11:00']);
check('paused listing: visit refused', !Database::value('SELECT 1 FROM bookings WHERE property_id = ?', [$L2]));

// ---------------------------------------------------------------------
echo "\nPost your requirement\n";
// ---------------------------------------------------------------------
$g = new Browser($base);
$g->get('/post-requirement');
check('requirement form opens for guests', $g->status === 200 && $g->has('Post your requirement') && $g->has('Interested in') && str_contains($g->body, 'data-locality-select'));
$g->get('/rent?city=1&locality=' . (int) Database::value("SELECT id FROM localities WHERE slug = 'hazira'") . '&bedrooms=4');
check('no-results page offers it', $g->has('Post your requirement'));
$g->get('/post-requirement');
$g->post('/post-requirement', ['first_name' => 'Gita', 'last_name' => 'Guest', 'mobile_1' => '9222200905', 'interested_in' => 'hotel', 'category_id' => '999',
    'budget' => 'lots', 'city_id' => '2', 'locality_id' => (string) Database::value("SELECT id FROM localities WHERE city_id = 1 LIMIT 1"), 'website' => '']);
$g->follow();
check('bad choices refused with messages', $g->has('Please choose Rent, Lease or PG') && $g->has('choose a property type') && $g->has('digits only') && $g->has('location in the selected city')
    && !Database::value("SELECT 1 FROM enquiries WHERE mobile_1 = '919222200905'"));
$g->post('/post-requirement', ['first_name' => 'Bot', 'last_name' => 'Spam', 'mobile_1' => '9222200906', 'interested_in' => 'rent',
    'category_id' => '1', 'website' => 'http://spam.example']);
check('honeypot: bot gets a thank-you but nothing is saved', $g->status === 302 && !Database::value("SELECT 1 FROM enquiries WHERE mobile_1 = '919222200906'"));
$g->get('/post-requirement');
$cat = (int) Database::value("SELECT id FROM property_categories WHERE code = 'residential'");
$vesu = (int) Database::value("SELECT id FROM localities WHERE slug = 'vesu'");
$g->post('/post-requirement', ['first_name' => 'Gita', 'last_name' => 'Guest', 'mobile_1' => '9222200905', 'email' => 'p9-gita@test.invalid',
    'interested_in' => 'pg', 'category_id' => (string) $cat, 'budget' => '₹ 9,000', 'city_id' => '1', 'locality_id' => (string) $vesu,
    'message' => 'Girls PG near VNSGU', 'website' => '']);
$r = Database::one("SELECT * FROM enquiries WHERE mobile_1 = '919222200905'");
check('guest requirement saved', $r !== null && $r['source'] === 'general_form' && $r['user_id'] === null && $r['owner_id'] === null && $r['property_id'] === null
    && $r['interested_in'] === 'pg' && (int) $r['category_id'] === $cat && (int) $r['budget'] === 9000 && (int) $r['locality_id'] === $vesu);
check('admin notified of the requirement', (bool) Database::value("SELECT 1 FROM notifications WHERE audience = 'admin' AND type = 'new_requirement' AND entity_id = ?", [$r['id'] ?? 0]));
$g->follow();
check('thank-you message', $g->has('We have your requirement'));
for ($i = 0; $i < 5; $i++) {
    $g->get('/post-requirement');
    $g->post('/post-requirement', ['first_name' => 'Gita', 'last_name' => 'Guest', 'mobile_1' => '9222200905', 'interested_in' => 'rent', 'category_id' => (string) $cat, 'website' => '']);
}
check('guests limited to 5 an hour per IP', (int) Database::value("SELECT COUNT(*) FROM enquiries WHERE mobile_1 = '919222200905'") === 5);
Database::run('DELETE FROM rate_limits');

$v->get('/post-requirement');
check('logged-in user gets their details filled in', str_contains($v->body, 'value="9222200902"') && str_contains($v->body, 'value="p9-viewer@test.invalid"'));
$v->post('/post-requirement', ['first_name' => 'Vidya', 'last_name' => 'Test', 'mobile_1' => '9222200902', 'interested_in' => 'lease', 'category_id' => (string) $cat, 'budget' => '50000', 'website' => '']);
check('logged-in requirement linked to the account', (bool) Database::value("SELECT 1 FROM enquiries WHERE user_id = ? AND source = 'general_form' AND interested_in = 'lease'", [$viewer]));
$v->get('/account/contacted');
check('requirement listed in Contacted Properties', $v->has('General enquiry') && $v->has('Looking for') && $v->has('up to ₹50,000'));

// Admin follow-up.
$adm = new Browser($base);
$adm->get('/admin/login');
$adm->post('/admin/login', ['identifier' => 'p9-admin@test.invalid', 'password' => $pw]);
$adm->get('/admin/enquiries?source=general_form');
check('admin Requirements tab lists the requirement with details', $adm->status === 200 && $adm->has('Requirement') && $adm->has('PG · Residential · up to ₹9,000')
    && $adm->has('Vesu, Surat') && !$adm->has('P9T Vesu Flat'));
$adm->post('/admin/enquiries/' . $r['id'], ['status' => 'contacted', 'back' => '/admin/enquiries?source=general_form']);
check('admin sets the status', Database::value('SELECT status FROM enquiries WHERE id = ?', [$r['id']]) === 'contacted'
    && $adm->status === 302 && str_contains($adm->location, 'source=general_form'));
check('admin action logged', (bool) Database::value("SELECT 1 FROM admin_activity_logs WHERE admin_id = ? AND action = 'enquiry.status' AND entity_id = ?", [$adminId, $r['id']]));
$adm->get('/admin/enquiries');
$adm->post('/admin/enquiries/' . $r['id'], ['status' => 'hacked']);
check('invalid status refused', Database::value('SELECT status FROM enquiries WHERE id = ?', [$r['id']]) === 'contacted');
$v->post('/admin/enquiries/' . $r['id'], ['status' => 'closed']);
check('non-admin cannot change enquiry status', Database::value('SELECT status FROM enquiries WHERE id = ?', [$r['id']]) === 'contacted');

// ---------------------------------------------------------------------
echo "\nSEO\n";
// ---------------------------------------------------------------------
$g->get('/robots.txt');
check('robots.txt (non-production blocks all; production list has /property/)', $g->status === 200
    && (str_contains($g->body, 'Disallow: /property/') || str_contains($g->body, "Disallow: /\n")));

$setting('email_notifications_enabled', $savedEmail);
$cleanup();
check('test data removed', !Database::value("SELECT 1 FROM properties WHERE title LIKE 'P9T%'") && !Database::value("SELECT 1 FROM users WHERE email LIKE 'p9-%@test.invalid'"));
echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
