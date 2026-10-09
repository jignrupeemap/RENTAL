<?php
declare(strict_types=1);

/**
 * Phase 10 end-to-end tests: rental change detection, the RENTAL CHANGE
 * ALERT (dashboard card, notification, admin email), the three approval
 * modes, approve / reject with owner notice, and ownership rules.
 * The posting form, the alert page and approvals themselves were built in
 * Phases 4 and 6; this suite walks the whole spec 29-31 flow end to end.
 * DEVELOPMENT ONLY (creates and removes @test.invalid accounts and listings
 * whose titles start with "P10T").
 *
 *   php -S 127.0.0.1:8088 -t public public/index.php &
 *   php tests/phase10_rent_alert_test.php
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
    $ids = array_map('intval', array_column(Database::all("SELECT id FROM users WHERE email LIKE 'p10-%@test.invalid'"), 'id')) ?: [0];
    $in = implode(',', $ids);
    $p = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM properties WHERE owner_id IN ($in) OR created_by IN ($in) OR title LIKE 'P10T%'"), 'id')) ?: [0]);
    $r = implode(',', array_map('intval', array_column(Database::all("SELECT id FROM rental_change_history WHERE property_id IN ($p)"), 'id')) ?: [0]);
    foreach ([
        "DELETE FROM notifications WHERE user_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in)) OR (entity_type = 'rent_change' AND entity_id IN ($r)) OR (entity_type = 'property' AND entity_id IN ($p))",
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
        "DELETE FROM email_queue WHERE to_email LIKE 'p10-%@test.invalid'",
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
$setting('email_notifications_enabled', '1');
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
        'available_from' => gmdate('Y-m-d'), 'contact_name' => 'Owner Test', 'contact_mobile_1' => '919222201001',
        'published_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'approved_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 30),
    ];
    $cols = implode(', ', array_keys($row));
    $id = Database::insert("INSERT INTO properties ($cols) VALUES (" . rtrim(str_repeat('?, ', count($row)), ', ') . ')', array_values($row));
    Database::run("INSERT INTO property_locations (property_id, latitude, longitude, public_latitude, public_longitude, privacy)
                   VALUES (?, 21.1418, 72.7709, 21.1440, 72.7731, 'approximate')", [$id]);
    return $id;
}
/** Save step 5 (rent) of the edit form as the given browser. */
function setRent(Browser $b, int $id, int $rent, array $extra = []): Browser
{
    $b->get("/owner/properties/{$id}/step/5");
    return $b->post("/owner/properties/{$id}/step/5", $extra + ['rent' => (string) $rent, 'security_deposit' => '40000', 'action' => 'continue']);
}
function prop(int $id): array { return Database::one('SELECT * FROM properties WHERE id = ?', [$id]); }
function lastChange(int $pid): ?array { return Database::one('SELECT * FROM rental_change_history WHERE property_id = ? ORDER BY id DESC LIMIT 1', [$pid]); }
function changes(int $pid): int { return (int) Database::value('SELECT COUNT(*) FROM rental_change_history WHERE property_id = ?', [$pid]); }
function adminAlerts(int $changeId): int { return (int) Database::value("SELECT COUNT(*) FROM notifications WHERE audience = 'admin' AND entity_type = 'rent_change' AND entity_id = ?", [$changeId]); }
function alertMails(): int { return (int) Database::value("SELECT COUNT(*) FROM email_queue WHERE to_email = 'p10-admin@test.invalid' AND subject LIKE 'Rental change alert:%'"); }

// ---------------------------------------------------------------------
echo "Setup\n";
// ---------------------------------------------------------------------
$o = new Browser($base);
$owner = registerUser($o, 'Rahul', '9222201001', 'p10-owner@test.invalid');
$o2 = new Browser($base);
$owner2 = registerUser($o2, 'Bina', '9222201002', 'p10-owner2@test.invalid');
$adminId = registerUser(new Browser($base), 'Asha', '9222201003', 'p10-admin@test.invalid');
Database::run("INSERT INTO user_roles (user_id, role) VALUES (?, 'admin')", [$adminId]);
Database::run("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'owner'), (?, 'owner')", [$owner, $owner2]);
$L = mk($owner, 'P10T Vesu 2BHK', 20000);
$adm = new Browser($base);
$adm->get('/admin/login');
$adm->post('/admin/login', ['identifier' => 'p10-admin@test.invalid', 'password' => $pw]);
check('accounts, listing and admin session ready', $owner && $owner2 && $L && redirectsTo($adm, '/admin'));
$publicUrl = property_url(prop($L));

// ---------------------------------------------------------------------
echo "\nDetection\n";
// ---------------------------------------------------------------------
setRent($o, $L, 20000, ['security_deposit' => '45000']);
check('editing other fields with the same rent: no rent change, no alert', $o->status === 302 && changes($L) === 0
    && (int) prop($L)['security_deposit'] === 45000 && alertMails() === 0, $o->status . ' ' . changes($L) . ' ' . prop($L)['security_deposit'] . ' ' . alertMails());

setRent($o, $L, 18000);
$c = lastChange($L);
check('decrease goes live at once (increase_only mode)', (int) prop($L)['rent'] === 18000 && $c && $c['status'] === 'auto_applied' && $c['change_type'] === 'decrease');
check('decrease still recorded and alerted', adminAlerts((int) $c['id']) === 1 && alertMails() === 1);

setRent($o, $L, 22000);
$c = lastChange($L);
$row = prop($L);
check('increase waits for approval; old rent stays live', $c['status'] === 'pending' && (int) $row['rent'] === 18000 && (int) $row['pending_rent_change_id'] === (int) $c['id']);
check('record has every spec 29 field', $c['property_url'] === $publicUrl && (int) $c['owner_id'] === $owner && $c['owner_name'] === 'Rahul Test'
    && $c['owner_mobile'] === '919222201001' && $c['owner_email'] === 'p10-owner@test.invalid' && (int) $c['old_rent'] === 18000
    && (int) $c['new_rent'] === 22000 && (int) $c['difference'] === 4000 && (int) $c['changed_by'] === $owner && $c['ip_address'] !== null && $c['created_at'] !== null);
$n = Database::one("SELECT * FROM notifications WHERE audience = 'admin' AND entity_type = 'rent_change' AND entity_id = ?", [$c['id']]);
check('admin notification: RENTAL CHANGE ALERT with owner, mobile, amounts, link', $n && str_starts_with($n['title'], 'RENTAL CHANGE ALERT')
    && str_contains((string) $n['body'], 'Rahul Test') && str_contains((string) $n['body'], '₹18,000 → ₹22,000') && str_contains((string) $n['body'], 'Waiting for your approval')
    && $n['link_url'] === '/admin/rent-changes/' . $c['id']);
$mail = Database::one("SELECT * FROM email_queue WHERE to_email = 'p10-admin@test.invalid' ORDER BY id DESC LIMIT 1");
check('admin email with the alert and clickable links', $mail && str_contains($mail['subject'], '+₹4,000') && str_contains($mail['body_html'], 'RENTAL CHANGE ALERT')
    && str_contains($mail['body_html'], $base . '/admin/properties/' . $L) && str_contains($mail['body_html'], $base . '/admin/users/' . $owner)
    && str_contains($mail['body_html'], $base . '/admin/rent-changes?property=' . $L) && str_contains($mail['body_html'], '₹22,000'));

$g = new Browser($base);
$g->get($publicUrl);
check('public page still shows the old rent', $g->status === 200 && str_contains($g->body, '<span class="fs-4 fw-bold">₹18,000</span>'));
$g->get('/rent?min=21000&max=23000');
check('search uses the live rent, not the waiting one', !$g->has('P10T Vesu 2BHK'));
$o->get('/owner/properties');
check('owner sees the waiting amount', $o->has('₹22,000 waiting for approval'));
$o->get('/owner/rent-changes');
check('owner rental change history lists both changes', $o->has('₹18,000') && $o->has('₹22,000') && $o->has('₹20,000'));

// ---------------------------------------------------------------------
echo "\nAdmin dashboard alert (spec 30)\n";
// ---------------------------------------------------------------------
$adm->get('/admin');
check('dashboard shows the Rental change alerts section', $adm->status === 200 && $adm->has('Rental change alerts') && $adm->has('Waiting for approval'));
$card = substr(html_entity_decode($adm->body, ENT_QUOTES), (int) strpos(html_entity_decode($adm->body, ENT_QUOTES), 'Rental change alerts'), 12000);
check('card: owner, mobile, property, old, new, increase, changed', str_contains($card, 'Rahul Test') && str_contains($card, '+91 92222 01001')
    && str_contains($card, '#' . $L . ' P10T Vesu 2BHK') && str_contains($card, '₹18,000') && str_contains($card, '₹22,000')
    && str_contains($card, 'Increase') && str_contains($card, '₹4,000') && str_contains($card, format_ist($c['created_at'])), format_mobile('919222201001'));
check('card links: Review, VIEW PROPERTY, VIEW OWNER, VIEW CHANGE HISTORY', str_contains($card, $base . '/admin/rent-changes/' . $c['id'] . '">Review')
    && str_contains($card, $base . '/admin/properties/' . $L . '">VIEW PROPERTY') && str_contains($card, $base . '/admin/users/' . $owner . '">VIEW OWNER')
    && str_contains($card, $base . '/admin/rent-changes?property=' . $L . '">VIEW CHANGE HISTORY'));
check('waiting alert listed before the applied decrease', strpos($card, 'Waiting for approval') < strpos($card, 'Decrease'));
foreach (['/admin/properties/' . $L => 'P10T Vesu 2BHK', '/admin/users/' . $owner => 'Rahul', '/admin/rent-changes?property=' . $L => 'P10T Vesu 2BHK'] as $link => $text) {
    $adm->get($link);
    check("link opens: $link", $adm->status === 200 && $adm->has($text));
}

// ---------------------------------------------------------------------
echo "\nOwner changes their mind\n";
// ---------------------------------------------------------------------
setRent($o, $L, 21000);
$first = Database::one('SELECT status FROM rental_change_history WHERE id = ?', [$c['id']]);
$c2 = lastChange($L);
check('new amount replaces the waiting one', $first['status'] === 'cancelled' && $c2['status'] === 'pending' && (int) $c2['new_rent'] === 21000
    && (int) prop($L)['pending_rent_change_id'] === (int) $c2['id']);
setRent($o, $L, 21000);
check('saving the same waiting amount again changes nothing', changes($L) === 3);
setRent($o, $L, 18000);
check('setting the live rent back withdraws the request', Database::value('SELECT status FROM rental_change_history WHERE id = ?', [$c2['id']]) === 'cancelled'
    && prop($L)['pending_rent_change_id'] === null && (bool) Database::value("SELECT 1 FROM notifications WHERE audience = 'admin' AND type = 'rent_change_withdrawn' AND entity_id = ?", [$c2['id']]));

// ---------------------------------------------------------------------
echo "\nReject and approve (spec 31)\n";
// ---------------------------------------------------------------------
setRent($o, $L, 25000);
$c3 = lastChange($L);
$o->post('/admin/rent-changes/' . $c3['id'], ['decision' => 'approve']);
check('owner cannot approve their own change', lastChange($L)['status'] === 'pending' && (int) prop($L)['rent'] === 18000);
$adm->get('/admin/rent-changes/' . $c3['id']);
$adm->post('/admin/rent-changes/' . $c3['id'], ['decision' => 'reject', 'note' => '']);
check('reject needs a reason', lastChange($L)['status'] === 'pending');
$adm->get('/admin/rent-changes/' . $c3['id']);
$adm->post('/admin/rent-changes/' . $c3['id'], ['decision' => 'reject', 'note' => 'Too high for the area']);
check('rejected: old rent stays live', lastChange($L)['status'] === 'rejected' && (int) prop($L)['rent'] === 18000 && prop($L)['pending_rent_change_id'] === null);
check('owner notified and emailed of the rejection', (bool) Database::value("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'rent_change_rejected'", [$owner])
    && (bool) Database::value("SELECT 1 FROM email_queue WHERE to_email = 'p10-owner@test.invalid' AND subject = 'Rent change not approved' AND body_html LIKE '%Too high for the area%'"));
check('rejection in the admin activity log', (bool) Database::value("SELECT 1 FROM admin_activity_logs WHERE admin_id = ? AND action = 'rent.rejected' AND entity_id = ?", [$adminId, $c3['id']]));

setRent($o, $L, 22000);
$c4 = lastChange($L);
$adm->get('/admin/rent-changes/' . $c4['id']);
$adm->post('/admin/rent-changes/' . $c4['id'], ['decision' => 'approve', 'note' => '']);
check('approved: new rent goes live', lastChange($L)['status'] === 'approved' && (int) prop($L)['rent'] === 22000 && (int) lastChange($L)['reviewed_by'] === $adminId);
check('owner notified and emailed of the approval', (bool) Database::value("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'rent_change_approved'", [$owner])
    && (bool) Database::value("SELECT 1 FROM email_queue WHERE to_email = 'p10-owner@test.invalid' AND subject = 'Rent change approved'"));
$adm->get('/admin/rent-changes/' . $c4['id']);
$adm->post('/admin/rent-changes/' . $c4['id'], ['decision' => 'reject', 'note' => 'Changed my mind']);
check('a decided change cannot be decided again', lastChange($L)['status'] === 'approved' && (int) prop($L)['rent'] === 22000);
$g->get($publicUrl);
check('public page shows the approved rent; URL unchanged', $g->status === 200 && str_contains($g->body, '<span class="fs-4 fw-bold">₹22,000</span>') && property_url(prop($L)) === $publicUrl);
$adm->get('/admin');
check('dashboard no longer shows a waiting card for it', !str_contains($adm->body, $base . '/admin/rent-changes/' . $c4['id'] . '">Review'));

// ---------------------------------------------------------------------
echo "\nApproval modes (admin setting)\n";
// ---------------------------------------------------------------------
$setting('rent_change_approval_mode', 'all');
setRent($o, $L, 21000);
check('mode "all": a decrease also waits', lastChange($L)['status'] === 'pending' && (int) prop($L)['rent'] === 22000);
setRent($o, $L, 22000);
$setting('rent_change_approval_mode', 'none');
setRent($o, $L, 24000);
$c5 = lastChange($L);
check('mode "none": an increase goes live at once', $c5['status'] === 'auto_applied' && (int) prop($L)['rent'] === 24000);
check('… and the admin is still alerted', adminAlerts((int) $c5['id']) === 1);
$adm->get('/admin');
check('applied increase shows on the dashboard for 7 days', str_contains($adm->body, $base . '/admin/properties/' . $L . '">VIEW PROPERTY') && $adm->has('₹24,000'));
Database::run('UPDATE rental_change_history SET created_at = UTC_TIMESTAMP() - INTERVAL 8 DAY WHERE property_id = ?', [$L]);
$adm->get('/admin');
check('older alerts drop off the dashboard', !str_contains($adm->body, '#' . $L . ' P10T Vesu 2BHK'));
$setting('rent_change_approval_mode', 'increase_only');

$setting('email_notifications_enabled', '0');
$before = alertMails();
setRent($o, $L, 23000);
check('email notifications off: alert stays in the dashboard only', alertMails() === $before && adminAlerts((int) lastChange($L)['id']) === 1);
$setting('email_notifications_enabled', '1');

// ---------------------------------------------------------------------
echo "\nRules\n";
// ---------------------------------------------------------------------
$before = changes($L);
$o2->get('/owner');
$o2->post("/owner/properties/{$L}/step/5", ['rent' => '1000', 'security_deposit' => '40000', 'action' => 'continue']);
check('another owner cannot change this rent', in_array($o2->status, [403, 404], true) && changes($L) === $before && (int) prop($L)['rent'] === 23000, (string) $o2->status);
setRent($o, $L, 30000, ['pending_rent_change_id' => '0', 'status' => 'active', 'rent_status' => 'approved', 'owner_id' => (string) $owner2]);
check('owner cannot bypass approval with extra form fields', lastChange($L)['status'] === 'pending' && (int) prop($L)['rent'] === 23000 && (int) prop($L)['owner_id'] === $owner);
Database::run("UPDATE users SET status = 'suspended', status_reason = 'Test' WHERE id = ?", [$owner]);
$before = changes($L);
setRent($o, $L, 35000);
check('suspended owner cannot change rent', changes($L) === $before);
Database::run("UPDATE users SET status = 'active', status_reason = NULL WHERE id = ?", [$owner]);

foreach ($saved as $k => $v) {
    $setting($k, $v);
}
$cleanup();
check('test data removed', !Database::value("SELECT 1 FROM properties WHERE title LIKE 'P10T%'") && !Database::value("SELECT 1 FROM users WHERE email LIKE 'p10-%@test.invalid'"));
echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
