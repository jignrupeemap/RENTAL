<?php
declare(strict_types=1);

/**
 * Phase 6 end-to-end tests: admin panel. Access control, approvals
 * (approve / reject / request changes / pause / activate / delete /
 * restore), admin Add & Edit property, rent change approval, limit
 * requests, user management, display order, featured, settings,
 * notifications and the admin activity log.
 * DEVELOPMENT ONLY (creates and removes @test.invalid accounts).
 *
 *   php -S 127.0.0.1:8088 -t public public/index.php &
 *   php tests/phase6_admin_test.php
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
$settingKeys = ['auth_otp_enabled', 'rent_change_approval_mode', 'reapprove_on_owner_edit', 'default_property_limit',
    'listing_expiry_days', 'listings_per_page', 'allow_owner_pause', 'default_location_privacy', 'budget_ranges', 'site_tagline'];
$savedSettings = [];
foreach (Database::all('SELECT setting_key, setting_value FROM settings') as $r) {
    if (in_array($r['setting_key'], $settingKeys, true)) {
        $savedSettings[$r['setting_key']] = $r['setting_value'];
    }
}
$cleanup = static function (): void {
    $ids = array_map('intval', array_column(Database::all("SELECT id FROM users WHERE email LIKE '%@test.invalid'"), 'id'));
    if (!$ids) {
        return;
    }
    $in = implode(',', $ids);
    $props = array_map('intval', array_column(Database::all("SELECT id FROM properties WHERE owner_id IN ($in) OR created_by IN ($in)"), 'id')) ?: [0];
    $p = implode(',', $props);
    $rch = array_map('intval', array_column(Database::all("SELECT id FROM rental_change_history WHERE property_id IN ($p)"), 'id')) ?: [0];
    $r = implode(',', $rch);
    $plr = array_map('intval', array_column(Database::all("SELECT id FROM property_limit_requests WHERE user_id IN ($in)"), 'id')) ?: [0];
    $l = implode(',', $plr);
    foreach (Database::all("SELECT storage_dir, file_key FROM property_media WHERE property_id IN ($p)") as $m) {
        array_map('unlink', glob(PUBLIC_ROOT . '/uploads/' . $m['storage_dir'] . '/' . $m['file_key'] . '*') ?: []);
    }
    foreach ([
        "DELETE FROM notifications WHERE user_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in)) OR (entity_type = 'property' AND entity_id IN ($p)) OR (entity_type = 'rent_change' AND entity_id IN ($r))
            OR (entity_type = 'user' AND entity_id IN ($in)) OR (entity_type = 'limit_request' AND entity_id IN ($l))",
        "UPDATE properties SET pending_rent_change_id = NULL, cover_media_id = NULL WHERE id IN ($p)",
        "DELETE FROM rental_change_history WHERE property_id IN ($p)",
        "DELETE FROM enquiries WHERE property_id IN ($p) OR user_id IN ($in)",
        "DELETE FROM shortlists WHERE property_id IN ($p)",
        "DELETE FROM properties WHERE id IN ($p)",
        "DELETE FROM admin_activity_logs WHERE admin_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in))",
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
$setting('auth_otp_enabled', '0');
$setting('rent_change_approval_mode', 'increase_only');
$setting('reapprove_on_owner_edit', '0');
$setting('default_property_limit', '3');
$setting('listing_expiry_days', '90');

$pw = 'Secret123';
function registerUser(Browser $b, string $first, string $mobile, string $email): int
{
    global $pw;
    $b->get('/register');
    $b->post('/register', ['first_name' => $first, 'last_name' => 'Test', 'mobile' => $mobile, 'email' => $email,
        'password' => $pw, 'password_confirmation' => $pw, 'terms' => '1', 'website' => '']);
    return (int) Database::value('SELECT id FROM users WHERE email = ?', [$email]);
}
function adminLogin(Browser $b, string $email): bool
{
    global $pw;
    $b->get('/admin/login');
    $b->post('/admin/login', ['identifier' => $email, 'password' => $pw]);
    return redirectsTo($b, '/admin');
}
function typeId(string $code): int { return (int) Database::value('SELECT id FROM property_types WHERE code = ?', [$code]); }
function catId(string $code): int { return (int) Database::value('SELECT id FROM property_categories WHERE code = ?', [$code]); }
function localityId(string $slug): int { return (int) Database::value('SELECT id FROM localities WHERE slug = ?', [$slug]); }
function prop(int $id): array { return Database::one('SELECT * FROM properties WHERE id = ?', [$id]); }
function userRow(int $id): array { return Database::one('SELECT * FROM users WHERE id = ?', [$id]); }
function followHas(Browser $b, string $text): bool
{
    $b->follow();
    return $b->has($text);
}
function lastAdminLog(string $action, string $entityType, ?int $entityId): ?array
{
    return Database::one('SELECT * FROM admin_activity_logs WHERE action = ? AND entity_type = ? AND entity_id <=> ? ORDER BY id DESC LIMIT 1', [$action, $entityType, $entityId]);
}
function userNote(int $uid, string $type): bool
{
    return (bool) Database::value('SELECT 1 FROM notifications WHERE user_id = ? AND type = ?', [$uid, $type]);
}
/** Open a step (for the CSRF token) and post it. $base = /owner/properties or /admin/properties */
function step(Browser $b, string $base, int $id, int $n, array $data, string $action = 'continue'): Browser
{
    $b->get("{$base}/{$id}/step/{$n}");
    return $b->post("{$base}/{$id}/step/{$n}", $data + ['action' => $action]);
}
/** Fill steps 2-7 of a Rent › Flat listing and add one photo. */
function fillFlat(Browser $b, string $base, int $id, string $title, int $rent, string $mobile, ?callable $atStep7 = null): bool
{
    global $photo;
    $ok = true;
    step($b, $base, $id, 2, ['title' => $title, 'bedrooms' => '2', 'bathrooms' => '2', 'furnishing' => 'semi_furnished',
        'available_from' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d')]);
    $ok = $ok && redirectsTo($b, "{$base}/{$id}/step/3");
    step($b, $base, $id, 3, ['unit_no' => 'B-12', 'building_name' => 'Test Heights', 'address_line' => 'Ring Road', 'city_id' => '1',
        'locality_id' => (string) localityId('vesu'), 'pincode' => '395007', 'coordinates' => '21.1418, 72.7709', 'location_privacy' => 'approximate']);
    $ok = $ok && redirectsTo($b, "{$base}/{$id}/step/4");
    step($b, $base, $id, 4, []);
    step($b, $base, $id, 5, ['rent' => (string) $rent, 'security_deposit' => '40000']);
    $ok = $ok && redirectsTo($b, "{$base}/{$id}/step/6");
    $b->get("{$base}/{$id}/step/6");
    $b->upload("{$base}/{$id}/photos", ['photos[]' => [$photo]]);
    step($b, $base, $id, 6, []);
    $ok = $ok && redirectsTo($b, "{$base}/{$id}/step/7");
    if ($atStep7) {
        $atStep7($b);
    }
    step($b, $base, $id, 7, ['contact_name' => 'Test Contact', 'contact_mobile_1' => $mobile]);
    return $ok && redirectsTo($b, "{$base}/{$id}/step/8");
}
function ownerDraft(Browser $b, int $owner): int
{
    $b->get('/owner/properties/new');
    $b->post('/owner/properties/new', ['purpose' => 'rent', 'category_id' => catId('residential'), 'type_id' => typeId('flat')]);
    return (int) Database::value('SELECT MAX(id) FROM properties WHERE owner_id = ?', [$owner]);
}
function act(Browser $adm, int $id, string $action, string $reason = ''): Browser
{
    $adm->get("/admin/properties/{$id}");
    return $adm->post("/admin/properties/{$id}/action", ['action' => $action, 'reason' => $reason]);
}

$fx = sys_get_temp_dir() . '/rw-admin-' . getmypid();
@mkdir($fx);
$photo = testImage("$fx/room.jpg", 1200, 900);

// Accounts: two admins (one plain, one super admin), two owners, a viewer.
$adm = new Browser($base);
$adminId = registerUser($adm, 'Asha', '9222200301', 'asha6@test.invalid');
$adm2b = new Browser($base);
$admin2 = registerUser($adm2b, 'Dev', '9222200302', 'dev6@test.invalid');
$a = new Browser($base);
$ownerA = registerUser($a, 'Rahul', '9222200303', 'rahul6@test.invalid');
$b = new Browser($base);
$ownerB = registerUser($b, 'Bina', '9222200304', 'bina6@test.invalid');
$v = new Browser($base);
$viewer = registerUser($v, 'Vikram', '9222200305', 'vikram6@test.invalid');
Database::run("INSERT INTO user_roles (user_id, role) VALUES (?, 'admin'), (?, 'admin')", [$adminId, $admin2]);
check('test accounts ready', $adminId && $admin2 && $ownerA && $ownerB && $viewer);

// ---------------------------------------------------------------------
echo "\nAccess control\n";
// ---------------------------------------------------------------------
$a->get('/admin/properties');
check('site login does not open admin pages', redirectsTo($a, '/admin/login'));
$a->get('/owner');
$before = Database::value('SELECT COUNT(*) FROM admin_activity_logs');
$a->post('/admin/properties/10001/action', ['action' => 'approve']);
check('site session cannot post admin actions', (redirectsTo($a, '/admin/login') || $a->status === 419) && Database::value('SELECT COUNT(*) FROM admin_activity_logs') === $before);
check('owner account refused at the admin login', !adminLogin(new Browser($base), 'rahul6@test.invalid'));
check('admin signs in', adminLogin($adm, 'asha6@test.invalid'));
$pages = ['/admin' => 'Pending approvals', '/admin/properties' => 'Add Property', '/admin/properties?status=pending' => 'Approvals',
    '/admin/order' => 'Display order', '/admin/rent-changes' => 'Rental changes', '/admin/limit-requests' => 'Limit requests',
    '/admin/users' => 'Users', '/admin/enquiries' => 'Enquiries', '/admin/bookings' => 'Booking / visit requests',
    '/admin/notifications' => 'Notifications', '/admin/settings' => 'Save settings', '/admin/activity' => 'Activity log',
    '/admin/properties/new' => 'Listing belongs to'];
$bad = [];
foreach ($pages as $path => $text) {
    $adm->get($path);
    if ($adm->status !== 200 || !$adm->has($text)) {
        $bad[] = $path . ' (' . $adm->status . ')';
    }
}
check('every admin page opens', !$bad, implode(', ', $bad));
check('admin pages are noindex and show the menu', $adm->has('noindex') && $adm->has('Display Order') && $adm->has('Activity Log'));
$adm->get('/admin');
check('dashboard shows live sections', $adm->has('Total users') && $adm->has('Engagement') && $adm->has('Recent login activity') && $adm->has('Rejected / changes'));
$adm->get('/admin?range=custom&from=2026-01-01&to=2026-01-31');
check('custom date range accepted', $adm->status === 200 && $adm->has('01 Jan 2026 – 31 Jan 2026'));
$adm->get('/admin?range=custom&from=2026-02-10&to=2026-01-01');
check('reversed custom range falls back to this month', $adm->has('This month'));
$adm->get('/admin/properties');
$adm->post('/admin/properties/10001/action', ['action' => 'approve', '_token' => str_repeat('0', 64)]);
check('admin action without a valid CSRF token is refused', $adm->status === 419);

// ---------------------------------------------------------------------
echo "\nApproval: approve, request changes, reject\n";
// ---------------------------------------------------------------------
$pa = ownerDraft($a, $ownerA);
check('owner A listing filled in', fillFlat($a, '/owner/properties', $pa, 'Admin test 2 BHK <b>bold</b>', 20000, '9222200303'));
$a->get("/owner/properties/$pa/step/8");
$a->post("/owner/properties/$pa/submit");
check('owner submits; listing pending', prop($pa)['status'] === 'pending');
$adm->get('/admin/properties?status=pending');
check('pending list shows it with an Approve button', $adm->has('#' . $pa) && $adm->has('Approve') && $adm->has('Admin test 2 BHK <b>bold</b>') && !str_contains($adm->body, '<b>bold</b>'));
$adm->get('/admin/properties?q=' . $pa);
check('search by property ID', $adm->has('#' . $pa) && $adm->has('Admin test 2 BHK') && substr_count($adm->body, 'href="' . $base . '/admin/properties/' . $pa . '"') >= 1 && (int) Database::value("SELECT COUNT(*) FROM properties WHERE id = ?", [$pa]) === 1);
$adm->get('/admin/properties?q=9222200303');
check('search by owner mobile', $adm->has('#' . $pa));
$adm->get('/admin/properties?q=Rahul');
check('search by owner name', $adm->has('#' . $pa));
$adm->get('/admin/properties?purpose=pg&q=' . $pa);
check('purpose filter excludes it', !$adm->has('Admin test 2 BHK'));
$adm->get('/admin/properties?city=1&locality=' . localityId('vesu') . '&category=' . catId('residential') . '&owner=' . $ownerA);
check('city, locality, category and owner filters', $adm->has('#' . $pa) && $adm->has('Owner: Rahul Test'));
$adm->get("/admin/properties/$pa");
check('detail page shows listing, owner and history', $adm->status === 200 && $adm->has('Owner & contact') && $adm->has('Status history') && $adm->has('Request changes') && $adm->has('Reject'));

act($adm, $pa, 'reject', '');
check('reject without a reason is refused', prop($pa)['status'] === 'pending' && followHas($adm, 'Please write a short note'));
act($adm, $pa, 'changes', 'Please add a clearer living room photo.');
$row = prop($pa);
check('request changes: status, note for owner', $row['status'] === 'changes_requested' && $row['rejection_reason'] === 'Please add a clearer living room photo.');
check('owner notified of requested changes', userNote($ownerA, 'property_changes_requested'));
$a->get("/owner/properties/$pa/step/8");
check('owner sees the admin note', $a->has('Please add a clearer living room photo.'));
$a->post("/owner/properties/$pa/submit");
check('owner resubmits', prop($pa)['status'] === 'pending');
act($adm, $pa, 'approve');
$row = prop($pa);
$days = $row['expires_at'] ? (int) round((strtotime($row['expires_at']) - strtotime($row['published_at'])) / 86400) : 0;
check('approve: active, published, approved_by admin', $row['status'] === 'active' && $row['published_at'] !== null && (int) $row['approved_by'] === $adminId && $row['rejection_reason'] === null);
check('expiry set from setting (90 days)', $days === 90, (string) $days);
check('owner notified of approval', userNote($ownerA, 'property_approved'));
$h = Database::one('SELECT * FROM property_status_history WHERE property_id = ? ORDER BY id DESC LIMIT 1', [$pa]);
check('status history: admin, pending → active', $h['actor_role'] === 'admin' && $h['old_status'] === 'pending' && $h['new_status'] === 'active' && (int) $h['changed_by'] === $adminId);
$log = lastAdminLog('property.approve', 'property', $pa);
check('admin log has old and new status', $log && json_decode($log['old_values'], true)['status'] === 'pending' && json_decode($log['new_values'], true)['status'] === 'active' && $log['ip_address'] !== null);
act($adm, $pa, 'approve');
check('approving an active listing is refused', followHas($adm, 'cannot be approved'));

$pb = ownerDraft($b, $ownerB);
fillFlat($b, '/owner/properties', $pb, 'Bina shop flat', 18000, '9222200304');
$b->get("/owner/properties/$pb/step/8");
$b->post("/owner/properties/$pb/submit");
act($adm, $pb, 'reject', 'Duplicate of another listing.');
check('reject with reason: rejected + owner notified', prop($pb)['status'] === 'rejected' && prop($pb)['rejection_reason'] === 'Duplicate of another listing.' && userNote($ownerB, 'property_rejected'));

// ---------------------------------------------------------------------
echo "\nPause, activate, delete, restore\n";
// ---------------------------------------------------------------------
act($adm, $pa, 'pause');
check('admin pauses an active listing', prop($pa)['status'] === 'paused' && userNote($ownerA, 'property_paused'));
$a->get('/owner/properties');
$a->post("/owner/properties/$pa/pause");
check('owner cannot resume a listing the admin paused', prop($pa)['status'] === 'paused' && followHas($a, 'paused by the admin team'));
act($adm, $pa, 'activate');
check('admin activates it again', prop($pa)['status'] === 'active');
act($adm, $pb, 'delete');
$row = prop($pb);
check('delete is soft: status deleted, row kept', $row['status'] === 'deleted' && $row['deleted_at'] !== null);
$adm->get('/admin/properties');
check('deleted listing hidden from the main list', !$adm->has('Bina shop flat'));
$adm->get('/admin/properties?status=deleted');
check('…and shown under Deleted', $adm->has('Bina shop flat'));
act($adm, $pb, 'restore');
check('restore puts it back as a draft (never published)', prop($pb)['status'] === 'draft' && prop($pb)['deleted_at'] === null);
act($adm, $pb, 'publish');
check("publish is refused for an owner's own draft", prop($pb)['status'] === 'draft' && followHas($adm, "owner's own draft"));
act($adm, $pa, 'bogus');
check('unknown action refused', prop($pa)['status'] === 'active');

// ---------------------------------------------------------------------
echo "\nAdmin Add Property\n";
// ---------------------------------------------------------------------
$adm->get('/admin/properties/new');
$adm->post('/admin/properties/new', ['assign' => 'user', 'owner' => '9999999999', 'purpose' => 'rent', 'category_id' => catId('residential'), 'type_id' => typeId('flat')]);
check('unknown owner mobile refused', redirectsTo($adm, '/admin/properties/new') && followHas($adm, 'No account found'));
// Owner B is at their limit: the admin can still add for them.
Database::run('UPDATE users SET property_limit = 1 WHERE id = ?', [$ownerB]);
$adm->get('/admin/properties/new');
$adm->post('/admin/properties/new', ['assign' => 'user', 'owner' => '9222200304', 'purpose' => 'rent', 'category_id' => catId('residential'), 'type_id' => typeId('flat')]);
$pc = (int) Database::value('SELECT MAX(id) FROM properties WHERE created_by = ?', [$adminId]);
$row = prop($pc);
check('admin creates a draft for owner B beyond their limit', redirectsTo($adm, "/admin/properties/$pc/step/2") && (int) $row['owner_id'] === $ownerB && (int) $row['created_by'] === $adminId);
check('creation logged as admin action', lastAdminLog('property.created', 'property', $pc) !== null);
$adm->get("/admin/properties/$pc");
check('incomplete: detail page lists what is missing, with step links', $adm->has('cannot go live until these are filled in') && $adm->has("/admin/properties/$pc/step/3"));
act($adm, $pc, 'publish');
check('publishing incomplete listing refused', prop($pc)['status'] === 'draft' && followHas($adm, 'cannot go live yet'));
$prefilled = false;
check('admin fills the form (admin URLs, photo upload)', fillFlat($adm, '/admin/properties', $pc, 'Admin added 2 BHK', 25000, '9222200304',
    function (Browser $b) use ($pc, &$prefilled): void { $b->get("/admin/properties/$pc/step/7"); $prefilled = $b->has('value="9222200304"'); }));
check('step 7 pre-filled with the owner details', $prefilled);
check('photo saved through the admin upload route', (bool) Database::value("SELECT 1 FROM property_media WHERE property_id = ? AND status = 'active'", [$pc]));
$adm->get("/admin/properties/$pc/step/8");
$adm->post("/admin/properties/$pc/action", ['action' => 'publish']);
$row = prop($pc);
check('Publish now: active immediately, no approval queue', $row['status'] === 'active' && $row['published_at'] !== null);
check('owner B notified', userNote($ownerB, 'property_approved'));
$b->get('/owner/properties');
check('listing appears in owner B\'s dashboard', $b->has('Admin added 2 BHK'));

$adm->get('/admin/properties/new');
$adm->post('/admin/properties/new', ['assign' => 'system', 'purpose' => 'pg', 'category_id' => catId('residential'), 'type_id' => typeId('flat')]);
$ps = (int) Database::value('SELECT MAX(id) FROM properties WHERE created_by = ?', [$adminId]);
check('admin/system listing has no owner', $ps !== $pc && prop($ps)['owner_id'] === null && prop($ps)['purpose'] === 'pg');
$adm->get("/admin/properties/$ps");
$adm->post("/admin/properties/$ps/owner", ['assign' => 'user', 'owner' => 'rahul6@test.invalid']);
check('change owner: assigned to owner A by email', (int) prop($ps)['owner_id'] === $ownerA && userNote($ownerA, 'property_assigned'));
$adm->get("/admin/properties/$ps");
$adm->post("/admin/properties/$ps/owner", ['assign' => 'system']);
check('…and back to admin/system', prop($ps)['owner_id'] === null && lastAdminLog('property.owner_changed', 'property', $ps) !== null);

// ---------------------------------------------------------------------
echo "\nAdmin edit and rent\n";
// ---------------------------------------------------------------------
$setting('reapprove_on_owner_edit', '1');
step($adm, '/admin/properties', $pa, 2, ['title' => 'Admin edited 2 BHK', 'bedrooms' => '2', 'bathrooms' => '2', 'furnishing' => 'furnished',
    'available_from' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d')]);
$row = prop($pa);
check('admin edit saves and listing stays live (no re-approval for admins)', $row['title'] === 'Admin edited 2 BHK' && $row['status'] === 'active');
check('admin edit logged', lastAdminLog('property.edited', 'property', $pa) !== null);
$adm->get("/admin/properties/$pa/step/5");
check('rent step tells the admin changes go live at once', $adm->has('goes live at once'));
step($adm, '/admin/properties', $pa, 5, ['rent' => '21000', 'security_deposit' => '40000']);
$row = prop($pa);
$rc = Database::one('SELECT * FROM rental_change_history WHERE property_id = ? ORDER BY id DESC LIMIT 1', [$pa]);
check('admin rent increase goes live at once', (int) $row['rent'] === 21000 && $row['pending_rent_change_id'] === null);
check('…recorded as approved by the admin, owner told', $rc && $rc['status'] === 'approved' && (int) $rc['reviewed_by'] === $adminId && userNote($ownerA, 'rent_changed_by_admin'));
$setting('reapprove_on_owner_edit', '0');

// ---------------------------------------------------------------------
echo "\nRent change approval\n";
// ---------------------------------------------------------------------
step($a, '/owner/properties', $pa, 5, ['rent' => '23000', 'security_deposit' => '40000']);
$row = prop($pa);
$rid = (int) $row['pending_rent_change_id'];
check('owner increase waits; old rent stays live', $rid > 0 && (int) $row['rent'] === 21000);
$adm->get('/admin');
check('dashboard alert counts the rent change', (bool) preg_match('/Rental changes<\/div><div class="fs-4 fw-semibold">[1-9]/', $adm->body));
$adm->get('/admin/rent-changes');
check('waiting list shows it', $adm->has('#' . $pa) && $adm->has('Review'));
$adm->get("/admin/rent-changes/$rid");
check('alert card: owner, mobile, old/new, increase', $adm->has('RENTAL CHANGE ALERT') || $adm->has('Rental change alert'));
check('…with old ₹21,000, new ₹23,000, increase ₹2,000', $adm->has('₹21,000') && $adm->has('₹23,000') && $adm->has('₹2,000') && $adm->has('Rahul Test') && $adm->has('+91 92222 00303'));
check('…and clickable VIEW PROPERTY / VIEW OWNER / VIEW CHANGE HISTORY',
    $adm->has('href="' . $base . '/admin/properties/' . $pa . '">VIEW PROPERTY') && $adm->has('href="' . $base . '/admin/users/' . $ownerA . '">VIEW OWNER') && $adm->has('href="' . $base . '/admin/rent-changes?property=' . $pa . '">VIEW CHANGE HISTORY'),
    'links');
// An admin saving step 5 with the live rent keeps the owner's request.
step($adm, '/admin/properties', $pa, 5, ['rent' => '21000', 'security_deposit' => '45000']);
$row = prop($pa);
check('admin saving other charges does not cancel the owner\'s request', (int) $row['pending_rent_change_id'] === $rid && (int) $row['security_deposit'] === 45000);
$adm->get("/admin/rent-changes/$rid");
$adm->post("/admin/rent-changes/$rid", ['decision' => 'approve', 'note' => '']);
$row = prop($pa);
check('approve: ₹23,000 live, nothing pending', (int) $row['rent'] === 23000 && $row['pending_rent_change_id'] === null);
check('owner notified of approval', userNote($ownerA, 'rent_change_approved'));
check('logged as rent.approved', lastAdminLog('rent.approved', 'rent_change', $rid) !== null);
$adm->get("/admin/rent-changes/$rid");
$adm->post("/admin/rent-changes/$rid", ['decision' => 'approve']);
check('a decided change cannot be decided again', followHas($adm, 'no longer waiting'));
step($a, '/owner/properties', $pa, 5, ['rent' => '26000', 'security_deposit' => '45000']);
$rid2 = (int) prop($pa)['pending_rent_change_id'];
$adm->get("/admin/rent-changes/$rid2");
$adm->post("/admin/rent-changes/$rid2", ['decision' => 'reject', 'note' => '']);
check('reject without a note refused', (int) prop($pa)['pending_rent_change_id'] === $rid2);
$adm->post("/admin/rent-changes/$rid2", ['decision' => 'reject', 'note' => 'Too high for this area.']);
$row = prop($pa);
check('reject: old rent stays, request closed, owner told', (int) $row['rent'] === 23000 && $row['pending_rent_change_id'] === null
    && Database::value('SELECT status FROM rental_change_history WHERE id = ?', [$rid2]) === 'rejected' && userNote($ownerA, 'rent_change_rejected'));
$adm->get('/admin/rent-changes?property=' . $pa);
check('change history for the listing', $adm->has('₹26,000') && $adm->has('₹23,000') && $adm->has('Rejected'));

// ---------------------------------------------------------------------
echo "\nProperty limit requests\n";
// ---------------------------------------------------------------------
$usedB = (int) Database::value("SELECT COUNT(*) FROM properties WHERE owner_id = ? AND status <> 'deleted'", [$ownerB]);
Database::run('UPDATE users SET property_limit = ? WHERE id = ?', [$usedB, $ownerB]);
$b->get('/owner/properties/new');
check("owner B at {$usedB}/{$usedB} cannot start another listing", $b->status === 403 && $b->has('REQUEST MORE PROPERTY LIMIT'));
$b->get('/owner/limit');
$b->post('/owner/limit', ['requested' => 'number', 'requested_limit' => (string) ($usedB + 5), 'reason' => 'I manage more flats in Vesu.']);
$lr = (int) Database::value("SELECT id FROM property_limit_requests WHERE user_id = ? AND status = 'pending'", [$ownerB]);
check('owner request reaches admin', $lr > 0);
$adm->get('/admin/limit-requests');
check('admin sees user, current, requested and reason', $adm->has('Bina Test') && $adm->has('I manage more flats in Vesu.') && $adm->has('Requested limit'));
$adm->get("/admin/limit-requests/$lr");
check('notification link opens the request', $adm->status === 200 && $adm->has('Bina Test'));
$adm->post("/admin/limit-requests/$lr", ['decision' => 'approve', 'approved_limit' => (string) $usedB]);
check('approving with a limit not above the current one is refused', (int) userRow($ownerB)['property_limit'] === $usedB && followHas($adm, 'must be higher'));
$adm->post("/admin/limit-requests/$lr", ['decision' => 'approve', 'approved_limit' => (string) ($usedB + 1), 'note' => 'One more for now.']);
check('approve a different number than asked', (int) userRow($ownerB)['property_limit'] === $usedB + 1
    && Database::value('SELECT status FROM property_limit_requests WHERE id = ?', [$lr]) === 'approved' && userNote($ownerB, 'limit_approved'));
$b->get('/owner/properties/new');
check('owner can post the next property after approval', $b->status === 200 && $b->has('Step 1 of 8'));
$b->get('/owner/limit');
$b->post('/owner/limit', ['requested' => 'unlimited', 'reason' => 'Agency with many listings.']);
$lr2 = (int) Database::value("SELECT id FROM property_limit_requests WHERE user_id = ? AND status = 'pending'", [$ownerB]);
$adm->get('/admin/limit-requests');
$adm->post("/admin/limit-requests/$lr2", ['decision' => 'reject', 'note' => '']);
check('reject without a note refused', Database::value('SELECT status FROM property_limit_requests WHERE id = ?', [$lr2]) === 'pending');
$adm->post("/admin/limit-requests/$lr2", ['decision' => 'reject', 'note' => 'Please contact us for agency plans.']);
check('reject with note: owner told', Database::value('SELECT status FROM property_limit_requests WHERE id = ?', [$lr2]) === 'rejected' && userNote($ownerB, 'limit_rejected'));
$adm->get("/admin/users/$ownerB");
$adm->post("/admin/users/$ownerB/limit", ['property_limit' => '5000']);
check('limit above 1000 refused', followHas($adm, 'between 0 and 1000'));
$adm->post("/admin/users/$ownerB/limit", ['property_limit' => '10', 'unlimited' => '1']);
check('admin sets Unlimited from the user page', (int) userRow($ownerB)['property_limit_unlimited'] === 1 && lastAdminLog('user.limit_changed', 'user', $ownerB) !== null);

// ---------------------------------------------------------------------
echo "\nUsers\n";
// ---------------------------------------------------------------------
foreach (['Vikram' => 'name', '92222 00305' => 'mobile', 'vikram6@test' => 'email'] as $q => $what) {
    $adm->get('/admin/users?q=' . rawurlencode($q));
    check("user search by {$what}", $adm->has('Vikram Test') && !$adm->has('Rahul Test'));
}
$adm->get('/admin/users?role=owner&q=test.invalid');
check('role filter: owners only', $adm->has('Rahul Test') && $adm->has('Bina Test') && !$adm->has('Vikram Test'));
$adm->get("/admin/users/$ownerA");
check('profile page: listings, limit, activity, logins', $adm->has('Admin edited 2 BHK') && $adm->has('Property posting limit') && $adm->has('Login history') && $adm->has('Enquiries received'));
$adm->post("/admin/users/$viewer", ['first_name' => 'Vikram', 'last_name' => 'Test', 'mobile' => '9222200303', 'email' => 'vikram6@test.invalid']);
check("duplicate mobile refused", followHas($adm, 'used by another account'));
$adm->get("/admin/users/$viewer");
$adm->post("/admin/users/$viewer", ['first_name' => 'Vikrant', 'last_name' => 'Test', 'mobile' => '9222200315', 'email' => 'vikram6@test.invalid']);
$u = userRow($viewer);
check('profile edit saved; changed mobile no longer verified', $u['first_name'] === 'Vikrant' && $u['mobile'] === '919222200315' && $u['mobile_verified_at'] === null);
check('profile edit logged with old and new', ($l = lastAdminLog('user.profile_updated', 'user', $viewer)) && str_contains($l['old_values'], 'Vikram') && str_contains($l['new_values'], 'Vikrant'));

$adm->get("/admin/users/$ownerA");
$adm->post("/admin/users/$ownerA/status", ['status' => 'suspended', 'reason' => '']);
check('suspend needs a reason', userRow($ownerA)['status'] === 'active');
$adm->post("/admin/users/$ownerA/status", ['status' => 'suspended', 'reason' => 'Reported by tenants.']);
check('suspend with reason', userRow($ownerA)['status'] === 'suspended' && userRow($ownerA)['status_reason'] === 'Reported by tenants.');
$a->get("/owner/properties/$pa/step/2");
check('suspended owner cannot edit listings', redirectsTo($a, '/account'));
$a->get('/account');
check('…and sees the reason', $a->has('Your account is suspended') && $a->has('Reported by tenants.'));
$adm->post("/admin/users/$ownerA/status", ['status' => 'active']);
$a->get("/owner/properties/$pa/step/2");
check('activate restores access', userRow($ownerA)['status'] === 'active' && $a->status === 200);
$adm->post("/admin/users/$ownerA/status", ['status' => 'blocked', 'reason' => 'Fake listings.']);
$a->get('/owner');
check('block signs the owner out at once', userRow($ownerA)['status'] === 'blocked' && $a->status === 302 && str_contains($a->location, '/login'));
$adm->get("/admin/users/$ownerA");
check('profile shows Unblock', $adm->has('Unblock'));
$adm->post("/admin/users/$ownerA/status", ['status' => 'active']);
check('unblock', userRow($ownerA)['status'] === 'active' && lastAdminLog('user.status_changed', 'user', $ownerA) !== null);

$adm->get("/admin/users/$adminId");
$adm->post("/admin/users/$adminId/status", ['status' => 'blocked', 'reason' => 'Testing self block']);
check('admin cannot block their own account', userRow($adminId)['status'] === 'active' && followHas($adm, 'your own account'));
$adm->post("/admin/users/$admin2/status", ['status' => 'blocked', 'reason' => 'Testing admin block']);
check('a plain admin cannot block another admin', userRow($admin2)['status'] === 'active' && followHas($adm, 'Only a super admin'));

$v->get('/login');
$v->post('/login', ['identifier' => 'vikram6@test.invalid', 'password' => $pw]);
$sessions = (int) Database::value('SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL', [$viewer]);
$adm->get("/admin/users/$viewer");
$adm->post("/admin/users/$viewer/signout");
check('sign out everywhere ends every session', $sessions > 0 && (int) Database::value('SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL', [$viewer]) === 0);
$v->get('/account');
check('…the viewer is logged out', $v->status === 302);
$adm->get("/admin/users/$viewer");
$adm->post("/admin/users/$viewer/delete", ['reason' => '']);
$u = userRow($viewer);
check('delete is soft: row kept, deleted_at set, blocked', $u !== null && $u['deleted_at'] !== null && $u['status'] === 'blocked');
$v->get('/login');
$v->post('/login', ['identifier' => 'vikram6@test.invalid', 'password' => $pw]);
check('deleted account cannot sign in', !redirectsTo($v, '/account'));
$adm->get('/admin/users?status=deleted');
check('deleted users listed under Deleted', $adm->has('Vikrant Test'));
$adm->get("/admin/users/$viewer");
$adm->post("/admin/users/$viewer/restore");
check('restore brings it back as Inactive', userRow($viewer)['deleted_at'] === null && userRow($viewer)['status'] === 'inactive');

// ---------------------------------------------------------------------
echo "\nDisplay order and featured\n";
// ---------------------------------------------------------------------
// Three active Rent listings: pa, pc, and one more by owner B.
$pd = ownerDraft($b, $ownerB);
fillFlat($b, '/owner/properties', $pd, 'Bina third listing', 15000, '9222200304');
$b->get("/owner/properties/$pd/step/8");
$b->post("/owner/properties/$pd/submit");
act($adm, $pd, 'approve');
Database::run("UPDATE properties SET display_rank = 1000000 WHERE purpose = 'rent' AND status = 'active'");
Database::run('UPDATE properties SET published_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE id = ?', [3, $pa]);
Database::run('UPDATE properties SET published_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE id = ?', [2, $pc]);
Database::run('UPDATE properties SET published_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE id = ?', [1, $pd]);
$ids = static fn (): array => array_map('intval', array_column(Database::all("SELECT id FROM properties WHERE purpose = 'rent' AND status = 'active' AND id IN (?, ?, ?) ORDER BY display_rank, published_at DESC, id DESC", [$pa, $pc, $pd]), 'id'));
check('unranked listings show newest first', $ids() === [$pd, $pc, $pa], implode(',', $ids()));
$adm->get('/admin/order?purpose=rent');
check('order page lists Rent listings with arrows and Save Order', $adm->has('Bina third listing') && $adm->has('Save Order') && $adm->has('Move #' . $pa . ' up'));
$adm->post('/admin/order', ['purpose' => 'rent', 'move' => $pa . ':up']);
$order = $ids();
check('↑ moves a listing up one place', array_search($pa, $order, true) < array_search($pc, $order, true), implode(',', $order));
$adm->get('/admin/order?purpose=rent');
$adm->post('/admin/order', ['purpose' => 'rent', 'save' => '1', 'rank' => [$pa => '3', $pc => '1', $pd => '2', $ps => '1']]);
check('Save Order with priority numbers', array_slice(array_values(array_filter($ids(), static fn ($i) => in_array($i, [$pa, $pc, $pd], true))), 0, 3) === [$pc, $pd, $pa], implode(',', $ids()));
check('a PG listing id posted to the Rent list is ignored', (int) prop($ps)['display_rank'] === 1000000);
check('order change logged', Database::value("SELECT 1 FROM admin_activity_logs WHERE action = 'property.order_saved' AND admin_id = ?", [$adminId]) !== null);
$adm->get('/admin/order?purpose=rent');
$adm->post('/admin/order', ['purpose' => 'rent', 'save' => '1', 'rank' => [$pa => '', $pc => '1', $pd => '2']]);
check('clearing a number removes the priority', (int) prop($pa)['display_rank'] === 1000000 && (int) prop($pc)['display_rank'] === 1);
$adm->get("/admin/properties/$pd");
$adm->post("/admin/properties/$pd/featured", ['featured' => '1', 'featured_until' => '2020-01-01']);
check('featured end date in the past refused', (int) prop($pd)['is_featured'] === 0 && followHas($adm, 'from today onwards'));
$until = (new DateTimeImmutable('+10 days', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$adm->get("/admin/properties/$pd");
$adm->post("/admin/properties/$pd/featured", ['featured' => '1', 'featured_until' => $until]);
$row = prop($pd);
check('featured with an end date (end of that India day, in UTC)', (int) $row['is_featured'] === 1 && $row['featured_until'] === $until . ' 18:29:59', (string) $row['featured_until']);
$adm->get('/admin/properties?featured=1');
check('featured filter', $adm->has('Bina third listing') && !$adm->has('Admin added 2 BHK'));
$adm->post("/admin/properties/$pd/featured", ['featured' => '0', '_token' => $adm->token()]);
check('featured off', (int) prop($pd)['is_featured'] === 0 && prop($pd)['featured_until'] === null);

// ---------------------------------------------------------------------
echo "\nSettings\n";
// ---------------------------------------------------------------------
$adm->get('/admin/settings');
check('settings page lists groups, no secrets', $adm->has('Listings') && $adm->has('Rent change approval') && !$adm->has('DB_PASSWORD') && $adm->has('config/env.php'));
$form = static function (array $over) {
    $s = [];
    foreach (Database::all('SELECT setting_key, setting_value, value_type FROM settings') as $r) {
        $s[$r['setting_key']] = $r['value_type'] === 'bool' ? ($r['setting_value'] === '1' ? '1' : '0') : $r['setting_value'];
    }
    return ['s' => array_merge($s, $over)];
};
$adm->post('/admin/settings', $form(['listings_per_page' => '999']));
check('out-of-range number refused', followHas($adm, 'whole number from 6 to 60') && (int) Database::value("SELECT setting_value FROM settings WHERE setting_key = 'listings_per_page'") !== 999);
$adm->post('/admin/settings', $form(['rent_change_approval_mode' => 'sometimes', '_token' => $adm->token()]));
check('unknown choice refused', followHas($adm, 'choose one of the options'));
$adm->post('/admin/settings', $form(['budget_ranges' => '[{"label":"Cheap","min":0,"max":-5}]', '_token' => $adm->token()]));
check('invalid budget ranges refused', followHas($adm, 'Each range needs a label'));
$adm->post('/admin/settings', $form(['listing_expiry_days' => '30', 'rent_change_approval_mode' => 'all', 'reapprove_on_owner_edit' => '1',
    'site_tagline' => 'Homes & shops <script>', '_token' => $adm->token()]));
check('valid settings saved', Database::value("SELECT setting_value FROM settings WHERE setting_key = 'listing_expiry_days'") === '30'
    && Database::value("SELECT setting_value FROM settings WHERE setting_key = 'rent_change_approval_mode'") === 'all'
    && Database::value("SELECT setting_value FROM settings WHERE setting_key = 'reapprove_on_owner_edit'") === '1' && followHas($adm, 'settings saved'));
check('each changed setting logged with old and new value', (int) Database::value("SELECT COUNT(*) FROM admin_activity_logs WHERE action = 'setting.changed' AND admin_id = ?", [$adminId]) >= 4);
$adm->get('/admin/settings');
check('saved text is escaped on the page', $adm->has('value="Homes &amp; shops &lt;script&gt;"') || str_contains($adm->body, 'Homes &amp; shops &lt;script&gt;'));
$adm->post('/admin/settings', $form(['reapprove_on_owner_edit' => '0', '_token' => $adm->token()]));
check('switch can be turned off again', Database::value("SELECT setting_value FROM settings WHERE setting_key = 'reapprove_on_owner_edit'") === '0');
act($adm, $pd, 'pause');
act($adm, $pd, 'activate');
$row = prop($pd);
check('new expiry setting used on activation (30 days)', (int) round((strtotime($row['expires_at']) - time()) / 86400) === 30);
foreach ($savedSettings as $k => $val) {
    $setting($k, (string) $val);
}

// ---------------------------------------------------------------------
echo "\nNotifications, enquiries, activity log\n";
// ---------------------------------------------------------------------
$nid = (int) Database::value("SELECT id FROM notifications WHERE audience = 'admin' AND entity_type = 'limit_request' AND entity_id = ?", [$lr]);
Database::run('UPDATE notifications SET read_at = NULL WHERE id = ?', [$nid]);
$adm->get("/admin/notifications/$nid");
check('opening a notification marks it read and opens its link', redirectsTo($adm, "/admin/limit-requests/$lr") && Database::value('SELECT read_at FROM notifications WHERE id = ?', [$nid]) !== null);
$adm->get('/admin/notifications');
$adm->post('/admin/notifications/read');
check('mark all as read', (int) Database::value("SELECT COUNT(*) FROM notifications WHERE audience = 'admin' AND read_at IS NULL") === 0);
Database::insert(
    "INSERT INTO enquiries (source, property_id, owner_id, user_id, first_name, last_name, mobile_1, email, message, property_title, rent_at_enquiry)
     VALUES ('property_page', ?, ?, ?, 'Vikrant', 'Test', '919222200315', 'vikram6@test.invalid', 'Is it near the metro?', 'Admin edited 2 BHK', 23000)",
    [$pa, $ownerA, $viewer]
);
$adm->get('/admin/enquiries?q=9222200315');
check('enquiry search by mobile', $adm->has('Is it near the metro?') && $adm->has('₹23,000'));
$adm->get('/admin/enquiries?q=' . $pa);
check('enquiry search by property ID', $adm->has('Is it near the metro?'));
$adm->get('/admin/enquiries?owner=' . $ownerB);
check('enquiry filter by owner', !$adm->has('Is it near the metro?'));
$adm->get('/admin/enquiries?from=2000-01-01&to=2000-01-02');
check('enquiry date filter', !$adm->has('Is it near the metro?'));
$adm->get('/admin/activity');
check('activity log lists admin actions with details', $adm->has('property.approve') && $adm->has('status: pending → active') && $adm->has('Asha Test'));
$adm->get('/admin/activity?action=user.');
check('activity log filter by action', $adm->has('user.status_changed') && !$adm->has('property.approve'));
$adm->get('/admin/activity?tab=users');
check('user events tab', $adm->has('property.submitted'));

// ---------------------------------------------------------------------
echo "\nRegression: owner pages still scoped\n";
// ---------------------------------------------------------------------
$a->get('/login');
$a->post('/login', ['identifier' => 'rahul6@test.invalid', 'password' => $pw]);
$a->get("/owner/properties/$pc/step/2");
check("owner A cannot open owner B's listing", $a->status === 404);
$a->get("/admin/properties/$pc/step/2");
check('owner A cannot use the admin form', redirectsTo($a, '/admin/login'));
$adm->post('/admin/logout');
$adm->get('/admin/properties');
check('after admin sign-out, admin pages need login again', redirectsTo($adm, '/admin/login'));

// ---------------------------------------------------------------------
$cleanup();
array_map('unlink', glob("$fx/*") ?: []);
@rmdir($fx);
echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
