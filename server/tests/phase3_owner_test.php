<?php
declare(strict_types=1);

/**
 * Phase 3 end-to-end tests: owner dashboard, listings, enquiries, visit
 * requests, rent history, limit requests, profile, and ownership checks.
 * DEVELOPMENT ONLY (creates and removes @test.invalid accounts).
 *
 *   php -S 127.0.0.1:8088 -t public public/index.php &
 *   php tests/phase3_owner_test.php
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

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------
$cleanup = static function (): void {
    $ids = array_map('intval', array_column(Database::all("SELECT id FROM users WHERE email LIKE '%@test.invalid'"), 'id'));
    if (!$ids) {
        return;
    }
    $in = implode(',', $ids);
    $props = array_map('intval', array_column(Database::all("SELECT id FROM properties WHERE owner_id IN ($in)"), 'id')) ?: [0];
    $p = implode(',', $props);
    foreach ([
        "DELETE FROM contact_history WHERE property_id IN ($p) OR user_id IN ($in)",
        "DELETE FROM shortlists WHERE property_id IN ($p) OR user_id IN ($in)",
        "DELETE FROM enquiries WHERE property_id IN ($p) OR owner_id IN ($in) OR user_id IN ($in)",
        "DELETE FROM bookings WHERE property_id IN ($p) OR user_id IN ($in)",
        "DELETE FROM rental_change_history WHERE property_id IN ($p)",
        "DELETE FROM properties WHERE id IN ($p)",
        "DELETE FROM notifications WHERE user_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in)) OR (entity_type = 'user' AND entity_id IN ($in)) OR type LIKE 'limit_%' OR type LIKE 'property_%_by_owner'",
        "DELETE FROM activity_logs WHERE user_id IN ($in)",
        "DELETE FROM login_activity WHERE user_id IN ($in)",
        "DELETE FROM users WHERE id IN ($in)",
        'DELETE FROM rate_limits',
    ] as $sql) {
        Database::run($sql);
    }
};
$cleanup();
Database::run("UPDATE settings SET setting_value = '0' WHERE setting_key = 'auth_otp_enabled'");
Database::run("UPDATE settings SET setting_value = '1' WHERE setting_key = 'allow_owner_pause'");

$pw = 'Secret123';
function registerUser(Browser $b, string $first, string $mobile, string $email): int
{
    global $pw;
    $b->get('/register');
    $b->post('/register', ['first_name' => $first, 'last_name' => 'Test', 'mobile' => $mobile, 'email' => $email,
        'password' => $pw, 'password_confirmation' => $pw, 'terms' => '1', 'website' => '']);
    return (int) Database::value('SELECT id FROM users WHERE email = ?', [$email]);
}
function addProperty(int $owner, string $status, string $title, int $rent = 20000, string $purpose = 'rent'): int
{
    $id = Database::insert(
        "INSERT INTO properties (owner_id, created_by, purpose, category_id, type_id, status, title, slug, city_id, locality_id, rent, published_at)
         VALUES (?, ?, ?, 1, (SELECT id FROM property_types WHERE code = 'flat'), ?, ?, ?, 1, (SELECT id FROM localities WHERE slug = 'vesu'), ?, UTC_TIMESTAMP())",
        [$owner, $owner, $purpose, $status, $title, slugify($title), $rent]
    );
    Database::run("INSERT INTO property_status_history (property_id, old_status, new_status, actor_role) VALUES (?, NULL, ?, 'system')", [$id, $status]);
    return $id;
}

$a = new Browser($base);
$ownerA = registerUser($a, 'Rahul', '9222200001', 'rahul@test.invalid');
$b = new Browser($base);
$ownerB = registerUser($b, 'Bina', '9222200002', 'bina@test.invalid');
$v = new Browser($base);
$viewer = registerUser($v, 'Priya', '9222200003', 'priya@test.invalid');

$pActive = addProperty($ownerA, 'active', '2 BHK Premium Apartment', 22000);
$pPending = addProperty($ownerA, 'pending', '3 BHK Garden Flat', 30000);
$pDraft = addProperty($ownerA, 'draft', 'Draft Shop Listing', 15000, 'lease');
$pDeleted = addProperty($ownerA, 'deleted', 'Old Deleted Listing');
$pOther = addProperty($ownerB, 'active', 'Bina Secret Bungalow', 50000);

$enqA = Database::insert(
    "INSERT INTO enquiries (source, property_id, owner_id, user_id, first_name, last_name, mobile_1, email, interested_in, message, property_title, property_url, owner_name, rent_at_enquiry)
     VALUES ('property_page', ?, ?, ?, 'Priya', 'Test', '919222200003', 'priya@test.invalid', 'rent', 'Is parking included?', '2 BHK Premium Apartment', '/rent/x', 'Rahul Test', 22000)",
    [$pActive, $ownerA, $viewer]
);
$enqB = Database::insert(
    "INSERT INTO enquiries (source, property_id, owner_id, user_id, first_name, last_name, mobile_1, message, property_title, rent_at_enquiry)
     VALUES ('property_page', ?, ?, ?, 'Priya', 'Test', '919222200003', 'Hello Bina', 'Bina Secret Bungalow', 50000)",
    [$pOther, $ownerB, $viewer]
);
$bookA = Database::insert(
    "INSERT INTO bookings (property_id, owner_id, user_id, preferred_date, preferred_time, message, property_title, rent_at_request)
     VALUES (?, ?, ?, DATE_ADD(UTC_DATE(), INTERVAL 3 DAY), '11:00:00', 'Weekend please', '2 BHK Premium Apartment', 22000)",
    [$pActive, $ownerA, $viewer]
);
$bookB = Database::insert(
    "INSERT INTO bookings (property_id, owner_id, user_id, preferred_date, preferred_time, property_title)
     VALUES (?, ?, ?, DATE_ADD(UTC_DATE(), INTERVAL 2 DAY), '10:00:00', 'Bina Secret Bungalow')",
    [$pOther, $ownerB, $viewer]
);
Database::run(
    "INSERT INTO rental_change_history (property_id, owner_id, changed_by, old_rent, new_rent, difference, change_type, status, property_title, property_url)
     VALUES (?, ?, ?, 20000, 22000, 2000, 'increase', 'pending', '2 BHK Premium Apartment', '/rent/x'),
            (?, ?, ?, 50000, 45000, -5000, 'decrease', 'auto_applied', 'Bina Secret Bungalow', '/rent/y')",
    [$pActive, $ownerA, $ownerA, $pOther, $ownerB, $ownerB]
);
Database::run('UPDATE properties SET pending_rent_change_id = (SELECT MAX(id) FROM rental_change_history WHERE property_id = ? AND status = "pending") WHERE id = ?', [$pActive, $pActive]);

// ---------------------------------------------------------------------
echo "\nAccess\n";
$anon = new Browser($base);
$anon->get('/owner');
check('logged-out visitor is sent to login', redirectsTo($anon, '/login?next=%2Fowner'));

echo "\nDashboard\n";
$a->get('/owner');
check('dashboard loads with welcome', $a->status === 200 && $a->has('Welcome, Rahul Test'));
check('properties counter shows 3 / 3 (deleted listing not counted)', $a->has('3 / 3'));
check('limit reached shown', $a->has('Limit reached'));
check('pending rent change alert shown', $a->has('waiting for admin approval'));
check('latest enquiry listed', $a->has('Is parking included?') || $a->has('2 BHK Premium Apartment'));
check('other owner data not shown', !$a->has('Bina Secret Bungalow'));

echo "\nMy Properties\n";
$a->get('/owner/properties');
check('own listings shown', $a->has('2 BHK Premium Apartment') && $a->has('3 BHK Garden Flat') && $a->has('Draft Shop Listing'));
check('deleted and other owners\' listings hidden', !$a->has('Old Deleted Listing') && !$a->has('Bina Secret Bungalow'));
check('pending rent shown on listing', $a->has('₹22,000 waiting for approval') || $a->has('waiting for approval'));
$a->get('/owner/properties?status=pending');
check('status tab filters', $a->has('3 BHK Garden Flat') && !$a->has('2 BHK Premium Apartment'));

echo "\nPause / resume\n";
$a->get('/owner/properties');
$a->post('/owner/properties/' . $pActive . '/pause');
check('owner pauses own active listing', Database::value('SELECT status FROM properties WHERE id = ?', [$pActive]) === 'paused');
check('pause recorded in status history as owner', Database::value('SELECT actor_role FROM property_status_history WHERE property_id = ? ORDER BY id DESC LIMIT 1', [$pActive]) === 'owner');
check('admin notified of owner pause', (bool) Database::value("SELECT 1 FROM notifications WHERE type = 'property_paused_by_owner' AND entity_id = ?", [$pActive]));
$a->get('/owner/properties');
$a->post('/owner/properties/' . $pActive . '/pause');
check('owner resumes listing they paused', Database::value('SELECT status FROM properties WHERE id = ?', [$pActive]) === 'active');
$a->get('/owner/properties');
$a->post('/owner/properties/' . $pOther . '/pause');
check("cannot pause another owner's listing (404)", $a->status === 404 && Database::value('SELECT status FROM properties WHERE id = ?', [$pOther]) === 'active');
Database::run("UPDATE properties SET status = 'paused' WHERE id = ?", [$pActive]);
Database::run("INSERT INTO property_status_history (property_id, old_status, new_status, actor_role, reason) VALUES (?, 'active', 'paused', 'admin', 'Policy check')", [$pActive]);
$a->get('/owner/properties');
$a->post('/owner/properties/' . $pActive . '/pause')->follow();
check('owner cannot resume a listing the admin paused', $a->has('paused by the admin team') && Database::value('SELECT status FROM properties WHERE id = ?', [$pActive]) === 'paused');
Database::run("UPDATE properties SET status = 'active' WHERE id = ?", [$pActive]);
$a->get('/owner/properties');
$a->post('/owner/properties/' . $pPending . '/pause')->follow();
check('pending listing cannot be paused', $a->has('Only active listings can be paused'));

echo "\nProperty limit\n";
$a->get('/owner/properties/new');
check('4th property blocked at 3 / 3 (403)', $a->status === 403 && $a->has('You have reached your current property posting limit of 3 properties.'));
check('REQUEST MORE PROPERTY LIMIT button shown', $a->has('REQUEST MORE PROPERTY LIMIT'));
$a->get('/owner/limit');
$a->post('/owner/limit', ['requested' => 'number', 'requested_limit' => '3', 'reason' => 'Need more space for flats'])->follow();
check('requested limit must be above current', $a->has('higher than your current limit'));
$a->post('/owner/limit', ['requested' => 'number', 'requested_limit' => '10', 'reason' => 'short'])->follow();
check('reason is required', $a->has('short reason'));
$a->post('/owner/limit', ['requested' => 'number', 'requested_limit' => '10', 'reason' => 'I manage 8 flats in the same society.']);
$req = Database::one("SELECT * FROM property_limit_requests WHERE user_id = ? AND status = 'pending'", [$ownerA]);
check('request stored with current 3, count 3, requested 10', $req && (int) $req['current_limit'] === 3 && (int) $req['current_count'] === 3 && (int) $req['requested_limit'] === 10);
check('admin notified of limit request', (bool) Database::value("SELECT 1 FROM notifications WHERE type = 'limit_requested' AND entity_id = ?", [$req['id'] ?? 0]));
$a->follow();
check('page shows request waiting for review', $a->has('waiting for admin review'));
$a->post('/owner/limit', ['requested' => 'unlimited', 'reason' => 'Another request while one is pending'])->follow();
check('second request blocked while one is pending', (int) Database::value('SELECT COUNT(*) FROM property_limit_requests WHERE user_id = ?', [$ownerA]) === 1);
Database::run("UPDATE users SET property_limit = 5 WHERE id = ?", [$ownerA]); // admin approval (Phase 6 screen)
Database::run("UPDATE property_limit_requests SET status = 'approved', approved_limit = 5 WHERE id = ?", [$req['id']]);
$a->get('/owner/properties/new');
check('after limit raised to 5, posting is allowed (2 left)', $a->status === 200 && $a->has('You can post 2 more properties (3 / 5 used)'));
$a->get('/owner');
check('dashboard reflects new limit 3 / 5', $a->has('3 / 5'));

echo "\nEnquiries\n";
$a->get('/owner/enquiries');
check('own enquiry listed with contact details', $a->has('Is parking included?') && $a->has('+91 92222 00003'));
check("other owner's enquiry hidden", !$a->has('Hello Bina'));
$a->post('/owner/enquiries/' . $enqA, ['status' => 'contacted', 'owner_note' => 'Called, visiting Sunday', 'back' => '/owner/enquiries']);
$row = Database::one('SELECT status, owner_note FROM enquiries WHERE id = ?', [$enqA]);
check('owner moves enquiry to Contacted with a note', $row['status'] === 'contacted' && $row['owner_note'] === 'Called, visiting Sunday');
$a->get('/owner/enquiries');
$a->post('/owner/enquiries/' . $enqB, ['status' => 'closed']);
check("cannot change another owner's enquiry (404)", $a->status === 404 && Database::value('SELECT status FROM enquiries WHERE id = ?', [$enqB]) === 'new');
$a->get('/owner/enquiries');
$a->post('/owner/enquiries/' . $enqA, ['status' => 'hacked']);
check('invalid status rejected', Database::value('SELECT status FROM enquiries WHERE id = ?', [$enqA]) === 'contacted');
$a->get('/owner/enquiries?status=new');
check('status tab filters enquiries', !$a->has('Is parking included?'));
$a->get('/owner/enquiries?q=zzzz');
check('search with no match shows empty state', $a->has('No enquiries match'));
$a->get('/owner/enquiries?q=Priya');
check('search by enquirer name works', $a->has('Is parking included?'));
$a->get('/owner/enquiries?q=92222%2000003');
check('search by mobile works', $a->has('Is parking included?'));

echo "\nVisit requests\n";
$a->get('/owner/bookings');
check('own visit request listed', $a->has('Weekend please'));
check("other owner's request hidden", !$a->has('Bina Secret Bungalow'));
$a->post('/owner/bookings/' . $bookA, ['action' => 'reschedule', 'date' => '2020-01-01', 'time' => '10:00'])->follow();
check('reschedule to a past date rejected', $a->has('valid date from today'));
$future = (new DateTimeImmutable('+5 days', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$a->post('/owner/bookings/' . $bookA, ['action' => 'reschedule', 'date' => $future, 'time' => '16:30', 'note' => 'Evening works better']);
$bk = Database::one('SELECT * FROM bookings WHERE id = ?', [$bookA]);
check('reschedule stores proposed date/time', $bk['status'] === 'rescheduled' && $bk['proposed_date'] === $future && $bk['proposed_time'] === '16:30:00');
check('viewer notified of new time', (bool) Database::value("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'booking_rescheduled'", [$viewer]));
$a->get('/owner/bookings');
$a->post('/owner/bookings/' . $bookA, ['action' => 'accept'])->follow();
check('accept not allowed from rescheduled (viewer must confirm)', $a->has('not available') && Database::value('SELECT status FROM bookings WHERE id = ?', [$bookA]) === 'rescheduled');
Database::run("UPDATE bookings SET status = 'pending' WHERE id = ?", [$bookA]);
$a->get('/owner/bookings');
$a->post('/owner/bookings/' . $bookA, ['action' => 'accept']);
check('owner accepts visit', Database::value('SELECT status FROM bookings WHERE id = ?', [$bookA]) === 'accepted');
check('history row written', (int) Database::value('SELECT COUNT(*) FROM booking_status_history WHERE booking_id = ?', [$bookA]) === 2);
$a->get('/owner/bookings');
check('history visible to owner', $a->has('History'));
$a->post('/owner/bookings/' . $bookA, ['action' => 'complete']);
check('owner marks visit completed', Database::value('SELECT status FROM bookings WHERE id = ?', [$bookA]) === 'completed');
$a->get('/owner/bookings');
$a->post('/owner/bookings/' . $bookB, ['action' => 'accept']);
check("cannot act on another owner's request (404)", $a->status === 404 && Database::value('SELECT status FROM bookings WHERE id = ?', [$bookB]) === 'pending');

echo "\nRental Change History\n";
$a->get('/owner/rent-changes');
check('own rent change listed with old/new/difference', $a->has('₹20,000') && $a->has('₹22,000') && $a->has('+₹2,000'));
check("other owner's rent change hidden", !$a->has('Bina Secret Bungalow'));

echo "\nProfile\n";
$a->get('/owner/profile');
$a->post('/owner/profile', ['first_name' => 'Rahul', 'last_name' => 'Patel', 'email' => 'bina@test.invalid'])->follow();
check('email used by another account rejected', $a->has('already used by another account'));
Database::run('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?', [$ownerA]);
$a->post('/owner/profile', ['first_name' => 'Rahul', 'last_name' => 'Patel', 'email' => 'rahul.new@test.invalid']);
$u = Database::one('SELECT * FROM users WHERE id = ?', [$ownerA]);
check('name and email saved', $u['last_name'] === 'Patel' && $u['email'] === 'rahul.new@test.invalid');
check('changing email resets verification and sends a link', $u['email_verified_at'] === null && lastMailLink('rahul.new@test.invalid', '/verify-email') !== null);
check('mobile unchanged by profile form', $u['mobile'] === '919222200001');

echo "\nNotifications\n";
Database::run("INSERT INTO notifications (audience, user_id, type, title) VALUES ('user', ?, 'property_approved', 'Your listing is live')", [$ownerA]);
$a->get('/owner');
check('owner notification shown', $a->has('Your listing is live'));
$a->post('/owner/notifications/read');
check('mark all read', (int) Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL", [$ownerA]) === 0);

echo "\nInactive / blocked owner\n";
Database::run("UPDATE users SET status = 'inactive' WHERE id = ?", [$ownerB]);
$b->get('/owner');
check('inactive owner can still view dashboard', $b->status === 200);
$b->get('/owner/enquiries');
$b->post('/owner/enquiries/' . $enqB, ['status' => 'closed']);
check('inactive owner cannot update enquiries', redirectsTo($b, '/account') && Database::value('SELECT status FROM enquiries WHERE id = ?', [$enqB]) === 'new');
$b->get('/owner/properties/new');
check('inactive owner cannot start a new listing', redirectsTo($b, '/account'));
Database::run("UPDATE users SET status = 'blocked' WHERE id = ?", [$ownerB]);
$b->get('/owner');
check('blocked owner is logged out of the dashboard', redirectsTo($b, '/login?next=%2Fowner'));

$cleanup();
echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
