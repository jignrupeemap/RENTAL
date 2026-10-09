<?php
declare(strict_types=1);

/**
 * Phase 4 end-to-end tests: posting form for every purpose/category,
 * drafts, validation, preview, submit, edit, rent changes, the posting
 * limit, and ownership checks.
 * DEVELOPMENT ONLY (creates and removes @test.invalid accounts).
 *
 *   php -S 127.0.0.1:8088 -t public public/index.php &
 *   php tests/phase4_wizard_test.php
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
    $rch = array_map('intval', array_column(Database::all("SELECT id FROM rental_change_history WHERE property_id IN ($p)"), 'id')) ?: [0];
    $r = implode(',', $rch);
    foreach (Database::all("SELECT storage_dir, file_key FROM property_media WHERE property_id IN ($p)") as $m) {
        array_map('unlink', glob(PUBLIC_ROOT . '/uploads/' . $m['storage_dir'] . '/' . $m['file_key'] . '*') ?: []);
    }
    foreach ([
        "DELETE FROM notifications WHERE user_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in)) OR (entity_type = 'property' AND entity_id IN ($p)) OR (entity_type = 'rent_change' AND entity_id IN ($r)) OR (entity_type = 'user' AND entity_id IN ($in))",
        "UPDATE properties SET pending_rent_change_id = NULL, cover_media_id = NULL WHERE id IN ($p)",
        "DELETE FROM rental_change_history WHERE property_id IN ($p)",
        "DELETE FROM shortlists WHERE property_id IN ($p)",
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
$setting('auth_otp_enabled', '0');
$setting('rent_change_approval_mode', 'increase_only');
$setting('reapprove_on_owner_edit', '0');
$setting('default_property_limit', '3');

$pw = 'Secret123';
function registerUser(Browser $b, string $first, string $mobile, string $email): int
{
    global $pw;
    $b->get('/register');
    $b->post('/register', ['first_name' => $first, 'last_name' => 'Test', 'mobile' => $mobile, 'email' => $email,
        'password' => $pw, 'password_confirmation' => $pw, 'terms' => '1', 'website' => '']);
    return (int) Database::value('SELECT id FROM users WHERE email = ?', [$email]);
}
function typeId(string $code): int { return (int) Database::value('SELECT id FROM property_types WHERE code = ?', [$code]); }
function catId(string $code): int { return (int) Database::value('SELECT id FROM property_categories WHERE code = ?', [$code]); }
function amenityId(string $code): int { return (int) Database::value('SELECT id FROM amenities WHERE code = ?', [$code]); }
function localityId(string $slug): int { return (int) Database::value('SELECT id FROM localities WHERE slug = ?', [$slug]); }
function prop(int $id): array { return Database::one('SELECT * FROM properties WHERE id = ?', [$id]); }
function adminNotes(string $type, string $entityType, int $entityId): int
{
    return (int) Database::value("SELECT COUNT(*) FROM notifications WHERE audience = 'admin' AND type = ? AND entity_type = ? AND entity_id = ?", [$type, $entityType, $entityId]);
}
/** Start a listing from step 1; returns the new id (0 on failure). */
function startListing(Browser $b, int $owner, string $purpose, string $cat, string $type): int
{
    $b->get('/owner/properties/new');
    $b->post('/owner/properties/new', ['purpose' => $purpose, 'category_id' => catId($cat), 'type_id' => typeId($type)]);
    $id = (int) Database::value('SELECT MAX(id) FROM properties WHERE owner_id = ?', [$owner]);
    return redirectsTo($b, '/owner/properties/' . $id . '/step/2') ? $id : 0;
}
/** Open a step (for the CSRF token) and post it. */
function step(Browser $b, int $id, int $n, array $data, string $action = 'continue'): Browser
{
    $b->get("/owner/properties/{$id}/step/{$n}");
    return $b->post("/owner/properties/{$id}/step/{$n}", $data + ['action' => $action]);
}
function followHas(Browser $b, string $text): bool
{
    $b->follow();
    return $b->has($text);
}

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$a = new Browser($base);
$ownerA = registerUser($a, 'Rahul', '9222200101', 'rahul4@test.invalid');
$b = new Browser($base);
$ownerB = registerUser($b, 'Bina', '9222200102', 'bina4@test.invalid');
check('two test accounts registered', $ownerA > 0 && $ownerB > 0);

// ---------------------------------------------------------------------
echo "\nStep 1: purpose, category, type\n";
// ---------------------------------------------------------------------
$a->get('/owner/properties/new');
check('step 1 opens', $a->status === 200 && $a->has('Step 1 of 8') && $a->has('I want to list my property for'), (string) $a->status);
check('progress shows all 8 steps', $a->has('Property Details') && $a->has('Photos & Video') && $a->has('Preview & Submit'));
check('account is not an owner yet', !Database::value("SELECT 1 FROM user_roles WHERE user_id = ? AND role = 'owner'", [$ownerA]));
$a->post('/owner/properties/new', ['purpose' => 'pg', 'category_id' => catId('commercial'), 'type_id' => typeId('shop')]);
check('PG + Shop is refused', redirectsTo($a, '/owner/properties/new') && followHas($a, 'PG can be listed for a Flat or a Bungalow only.'));
$a->post('/owner/properties/new', ['purpose' => 'rent', 'category_id' => catId('commercial'), 'type_id' => typeId('flat')]);
check('type outside the chosen category is refused', followHas($a, 'Please choose a type from the selected category.'));
$a->post('/owner/properties/new', ['purpose' => 'sale', 'category_id' => catId('residential'), 'type_id' => typeId('flat')]);
check('unknown purpose is refused', followHas($a, 'Please choose Rent, Lease or PG.'));
check('no listing created by refused attempts', (int) Database::value('SELECT COUNT(*) FROM properties WHERE owner_id = ?', [$ownerA]) === 0);

$flat = startListing($a, $ownerA, 'rent', 'residential', 'flat');
check('Rent › Residential › Flat creates a draft and opens step 2', $flat > 0);
$row = prop($flat);
check('draft row: status draft, owner from session, step 1 done', $row['status'] === 'draft' && (int) $row['owner_id'] === $ownerA && (int) $row['draft_step'] === 1);
check('owner role granted with the first listing', (bool) Database::value("SELECT 1 FROM user_roles WHERE user_id = ? AND role = 'owner'", [$ownerA]));
check('status history records the draft', (bool) Database::value("SELECT 1 FROM property_status_history WHERE property_id = ? AND new_status = 'draft'", [$flat]));
check('property ids start at 10001 or above', $flat >= 10001);
$a->get("/owner/properties/{$flat}/step/5");
check('cannot skip ahead to step 5', redirectsTo($a, "/owner/properties/{$flat}/step/2"));

// ---------------------------------------------------------------------
echo "\nStep 2: details\n";
// ---------------------------------------------------------------------
$a->get("/owner/properties/{$flat}/step/2");
check('flat shows bedrooms, bathrooms, balconies, furnishing', $a->has('Bedrooms') && $a->has('Bathrooms') && $a->has('Balconies') && $a->has('Semi-Furnished'));
check('flat does not show PG fields', !$a->has('Suitable for'));
step($a, $flat, 2, []);
check('Save & Continue with nothing filled shows required errors', followHas($a, 'Please enter Listing title.') && $a->has('Please choose Bedrooms (BHK).'));
step($a, $flat, 2, ['title' => 'Sunny 2 BHK near VR Mall'], 'draft');
check('Save Draft keeps a partly filled step', redirectsTo($a, '/owner/properties?status=draft') && prop($flat)['title'] === 'Sunny 2 BHK near VR Mall');
check('a partial save does not mark the step done', (int) prop($flat)['draft_step'] === 1);
step($a, $flat, 2, ['title' => 'Sunny 2 BHK', 'bedrooms' => '9'], 'draft');
check('Save Draft still rejects wrong values', followHas($a, 'Please choose Bedrooms (BHK).') && prop($flat)['bedrooms'] === null);
step($a, $flat, 2, ['title' => 'Sun', 'bedrooms' => '2', 'bathrooms' => '2', 'furnishing' => 'semi_furnished', 'available_from' => '2031-13-01']);
check('invalid date and too-short title are rejected', followHas($a, 'Please choose a valid date') && $a->has('at least 5 characters'));
step($a, $flat, 2, [
    'title' => 'Sunny 2 BHK near VR Mall', 'description' => "<script>alert(1)</script> Bright corner flat.\nNear schools.",
    'bedrooms' => '2', 'bathrooms' => '2', 'balconies' => '1', 'area_value' => '1,250', 'area_unit' => 'sqft',
    'furnishing' => 'semi_furnished', 'available_from' => $today,
    // mass-assignment attempts and a field flats don't have
    'status' => 'active', 'owner_id' => (string) $ownerB, 'display_rank' => '1', 'is_featured' => '1', 'pg_available_for' => 'boys',
]);
$row = prop($flat);
check('valid step 2 saves and opens step 3', redirectsTo($a, "/owner/properties/{$flat}/step/3") && (int) $row['bedrooms'] === 2 && (int) $row['draft_step'] === 2);
check('area "1,250 sq ft" stored and normalised', (float) $row['area_value'] === 1250.0 && (float) $row['area_sqft'] === 1250.0);
check('status / owner / rank / featured cannot be set by the form', $row['status'] === 'draft' && (int) $row['owner_id'] === $ownerA && (int) $row['display_rank'] === 1000000 && (int) $row['is_featured'] === 0);
check('field not in the flat profile is not saved', $row['pg_available_for'] === null);

// ---------------------------------------------------------------------
echo "\nStep 3: location\n";
// ---------------------------------------------------------------------
$a->get("/owner/properties/{$flat}/step/3");
check('flat shows "Flat No" and Building name', $a->has('Flat No') && $a->has('Building name'));
check('without a Maps key, coordinates box and current-location button are shown', $a->has('Use my current location') && $a->has('name="coordinates"'));
$addr = ['unit_no' => 'A-402', 'building_name' => 'Shanti Heights', 'project_name' => 'Shanti Residency', 'landmark' => 'Near VR Mall',
    'address_line' => 'Magdalla Road, Vesu', 'city_id' => '1', 'locality_id' => (string) localityId('vesu'), 'pincode' => '395007',
    'coordinates' => '21.1418, 72.7709', 'location_privacy' => 'approximate'];
step($a, $flat, 3, ['city_id' => '2'] + $addr);
check('locality from another city is refused', followHas($a, 'Please choose a locality in the selected city.'));
step($a, $flat, 3, ['pincode' => '012345'] + $addr);
check('invalid pincode is refused', followHas($a, 'Please enter a valid 6-digit pincode.'));
step($a, $flat, 3, ['coordinates' => '51.5072, -0.1276'] + $addr);
check('pin outside India is refused', followHas($a, 'outside India'));
step($a, $flat, 3, ['coordinates' => 'near the mall'] + $addr);
check('unreadable coordinates are refused', followHas($a, 'Please paste the location as'));
step($a, $flat, 3, ['coordinates' => ''] + $addr);
check('map pin is required', followHas($a, 'Please mark the property on the map.'));
step($a, $flat, 3, $addr);
$loc = Database::one('SELECT * FROM property_locations WHERE property_id = ?', [$flat]);
$dist = static fn (array $l): float => 111320 * sqrt((($l['latitude'] - $l['public_latitude']) ** 2) + ((($l['longitude'] - $l['public_longitude']) * cos(deg2rad((float) $l['latitude']))) ** 2));
check('valid address saves and opens step 4', redirectsTo($a, "/owner/properties/{$flat}/step/4") && prop($flat)['pincode'] === '395007');
check('latitude / longitude stored', $loc !== null && (float) $loc['latitude'] === 21.1418 && (float) $loc['longitude'] === 72.7709);
check('approximate pin is moved 150-400 m', $loc['privacy'] === 'approximate' && $dist($loc) > 140 && $dist($loc) < 410, (string) round($dist($loc)));
step($a, $flat, 3, $addr);
$loc2 = Database::one('SELECT * FROM property_locations WHERE property_id = ?', [$flat]);
check('re-saving the same pin keeps the same public point', $loc2['public_latitude'] === $loc['public_latitude'] && $loc2['public_longitude'] === $loc['public_longitude']);
step($a, $flat, 3, ['location_privacy' => 'exact'] + $addr);
$loc3 = Database::one('SELECT * FROM property_locations WHERE property_id = ?', [$flat]);
check('exact privacy shows the real point', $loc3['privacy'] === 'exact' && $loc3['public_latitude'] === $loc3['latitude']);
check('slug built from BHK, type, locality, city', prop($flat)['slug'] === '2-bhk-flat-vesu-surat', prop($flat)['slug']);

// ---------------------------------------------------------------------
echo "\nSteps 4-7\n";
// ---------------------------------------------------------------------
$a->get("/owner/properties/{$flat}/step/4");
check('flat facilities include Pooja Room, not Personal Washroom', $a->has('Pooja Room') && !$a->has('Personal Washroom'));
step($a, $flat, 4, ['amenities' => [amenityId('lift'), amenityId('pooja_room'), amenityId('rcc_road'), 99999]]);
$am = array_map('intval', array_column(Database::all('SELECT amenity_id FROM property_amenities WHERE property_id = ?', [$flat]), 'amenity_id'));
sort($am);
$want = [amenityId('lift'), amenityId('pooja_room')];
sort($want);
check('only facilities offered for a flat are saved', $am === $want, json_encode($am));

step($a, $flat, 5, ['rent' => 'abc']);
check('non-numeric rent is refused', followHas($a, 'in whole rupees'));
step($a, $flat, 5, ['rent' => '50']);
check('rent below ₹100 is refused', followHas($a, 'must be between'));
step($a, $flat, 5, ['rent' => '22,000', 'security_deposit' => '50000', 'maintenance' => '1500', 'other_charges' => 'Electricity as per meter']);
$row = prop($flat);
check('rent "22,000" and charges saved', (int) $row['rent'] === 22000 && (int) $row['security_deposit'] === 50000 && (int) $row['maintenance'] === 1500);
check('unpublished listing: rent saved directly, no change history', (int) Database::value('SELECT COUNT(*) FROM rental_change_history WHERE property_id = ?', [$flat]) === 0);

step($a, $flat, 6, ['video_url' => 'https://evil.example/watch?v=dQw4w9WgXcQ']);
check('non-YouTube video link is refused', followHas($a, 'Please paste a YouTube link'));
step($a, $flat, 6, ['video_url' => 'https://youtu.be/dQw4w9WgXcQ?t=5']);
check('photos are required to continue (Phase 5)', followHas($a, 'Please add at least one photo.') && prop($flat)['video_url'] === null);
$a->get("/owner/properties/{$flat}/step/6");
$a->upload("/owner/properties/{$flat}/photos", ['photos[]' => testImage(sys_get_temp_dir() . '/p4-photo.jpg', 1200, 900)]);
step($a, $flat, 6, ['video_url' => 'https://youtu.be/dQw4w9WgXcQ?t=5']);
check('YouTube link saved in a standard form', prop($flat)['video_url'] === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');

$a->get("/owner/properties/{$flat}/step/7");
check('contact details pre-filled from the account', $a->has('value="Rahul Test"') && $a->has('value="9222200101"') && $a->has('rahul4@test.invalid'));
step($a, $flat, 7, ['contact_name' => 'Rahul Test', 'contact_mobile_1' => '12345']);
check('invalid mobile is refused', followHas($a, 'valid 10-digit Indian mobile number'));
step($a, $flat, 7, ['contact_name' => 'Rahul Test', 'contact_mobile_1' => '9222200101', 'contact_mobile_2' => '+91 92222 00101']);
check('second mobile equal to the first is refused', followHas($a, 'The second number is the same as the first.'));
step($a, $flat, 7, ['contact_name' => 'Rahul Test', 'contact_mobile_1' => '09222200101', 'contact_mobile_2' => '9822200109', 'contact_email' => 'Rahul4@Test.invalid']);
$row = prop($flat);
check('contact saved with normalised mobiles', $row['contact_mobile_1'] === '919222200101' && $row['contact_mobile_2'] === '919822200109' && $row['contact_email'] === 'rahul4@test.invalid');
check('step 7 done, preview opens next', redirectsTo($a, "/owner/properties/{$flat}/step/8") && (int) $row['draft_step'] === 7);

// ---------------------------------------------------------------------
echo "\nStep 8: preview and submit\n";
// ---------------------------------------------------------------------
$a->get("/owner/properties/{$flat}/step/8");
check('preview shows title, rent, deposit, details', $a->has('Sunny 2 BHK near VR Mall') && $a->has('₹22,000') && $a->has('₹50,000') && $a->has('Semi-Furnished') && $a->has('1250 Sq Ft'));
check('preview shows facilities and location', $a->has('Lift') && $a->has('Pooja Room') && !$a->has('RCC Road') && $a->has('Vesu, Surat') && $a->has('VIEW ON GOOGLE MAPS'));
check('description is escaped (no script injection)', str_contains($a->body, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($a->body, '<script>alert(1)'));
check('nothing missing, Submit button offered', !$a->has('Please complete these') && $a->has('Submit for Admin Approval'));
check('each section has an Edit link', substr_count($a->body, '>Edit</a>') >= 5);
$a->post("/owner/properties/{$flat}/submit");
$row = prop($flat);
check('submit sets Pending approval', redirectsTo($a, '/owner/properties?status=pending') && $row['status'] === 'pending' && $row['submitted_at'] !== null);
check('submit recorded in history and activity', (bool) Database::value("SELECT 1 FROM property_status_history WHERE property_id = ? AND new_status = 'pending'", [$flat])
    && (bool) Database::value("SELECT 1 FROM activity_logs WHERE user_id = ? AND event = 'property.submitted' AND entity_id = ?", [$ownerA, $flat]));
check('admin notified of the new listing', adminNotes('property_submitted', 'property', $flat) === 1);
$a->get("/owner/properties/{$flat}/step/8");
$a->post("/owner/properties/{$flat}/submit");
check('submitting twice is refused', followHas($a, 'already been submitted') && adminNotes('property_submitted', 'property', $flat) === 1);
step($a, $flat, 1, ['purpose' => 'rent', 'category_id' => catId('commercial'), 'type_id' => typeId('shop')]);
check('type cannot change after submission', followHas($a, 'cannot be changed after the listing has been submitted') && (int) prop($flat)['type_id'] === typeId('flat'));
$a->get('/owner/properties');
check('My Properties: Edit and Preview for submitted listing', $a->has("/owner/properties/{$flat}/step/2") && $a->has("/owner/properties/{$flat}/step/8"));

// ---------------------------------------------------------------------
echo "\nCommercial › Shop, incomplete submit, type change\n";
// ---------------------------------------------------------------------
$shop = startListing($a, $ownerA, 'rent', 'commercial', 'shop');
check('Rent › Commercial › Shop draft created', $shop > 0);
$a->get("/owner/properties/{$shop}/step/2");
check('shop step 2 has Area, no Bedrooms', $a->has('Area') && !$a->has('Bedrooms'));
$a->get("/owner/properties/{$shop}/step/8");
check('preview not reachable before the steps are done', redirectsTo($a, "/owner/properties/{$shop}/step/2"));
$a->post("/owner/properties/{$shop}/submit", ['_token' => $a->token()]);
check('incomplete draft cannot be submitted', prop($shop)['status'] === 'draft' && adminNotes('property_submitted', 'property', $shop) === 0);
step($a, $shop, 2, ['title' => 'Corner shop on Ring Road', 'bedrooms' => '3', 'area_value' => '500', 'area_unit' => 'sqyd', 'furnishing' => 'unfurnished', 'available_from' => $today]);
$row = prop($shop);
check('shop ignores bedrooms; 500 sq yd = 4500 sq ft', $row['bedrooms'] === null && (float) $row['area_sqft'] === 4500.0);
$a->get("/owner/properties/{$shop}/step/3");
check('shop address asks for "Shop No"', $a->has('Shop No'));
step($a, $shop, 3, ['unit_no' => 'G-12', 'address_line' => 'Ring Road, Surat', 'city_id' => '1', 'locality_id' => (string) localityId('adajan'), 'pincode' => '395009', 'coordinates' => '21.1959, 72.7933']);
$a->get("/owner/properties/{$shop}/step/4");
check('shop facilities include Personal Washroom, not Pooja Room', $a->has('Personal Washroom') && !$a->has('Pooja Room'));
step($a, $shop, 4, ['amenities' => [amenityId('personal_washroom'), amenityId('power_backup')]]);

// third listing: flat with 3 BHK and Pooja Room, then switch to Shop
$switch = startListing($a, $ownerA, 'rent', 'residential', 'flat');
step($a, $switch, 2, ['title' => 'Family flat 3 BHK', 'bedrooms' => '3', 'bathrooms' => '2', 'furnishing' => 'furnished', 'available_from' => $today]);
step($a, $switch, 3, ['address_line' => 'Pal Road, Surat', 'city_id' => '1', 'locality_id' => (string) localityId('pal'), 'pincode' => '395009', 'coordinates' => '21.19, 72.77']);
step($a, $switch, 4, ['amenities' => [amenityId('pooja_room'), amenityId('car_parking')]]);
step($a, $switch, 1, ['purpose' => 'rent', 'category_id' => catId('commercial'), 'type_id' => typeId('office')]);
$row = prop($switch);
$am = array_column(Database::all('SELECT a.code FROM property_amenities pa JOIN amenities a ON a.id = pa.amenity_id WHERE pa.property_id = ?', [$switch]), 'code');
check('draft type change Flat → Office applied', (int) $row['type_id'] === typeId('office') && followHas($a, 'Property type changed'));
check('bedroom / bathroom data cleared on type change', $row['bedrooms'] === null && $row['bathrooms'] === null);
check('facilities that offices do not have are removed', $am === ['car_parking'], json_encode($am));
check('address kept on type change', $row['address_line'] === 'Pal Road, Surat');

// ---------------------------------------------------------------------
echo "\nPosting limit\n";
// ---------------------------------------------------------------------
$a->get('/owner/properties/new');
check('4th listing blocked at 3 / 3 (drafts count)', $a->status === 403 && $a->has('You have reached your current property posting limit of 3 properties.'));
$a->get('/owner/properties');
$a->post('/owner/properties/new', ['purpose' => 'rent', 'category_id' => catId('residential'), 'type_id' => typeId('flat'), '_token' => $a->token()]);
check('posting step 1 directly is also blocked', (int) Database::value("SELECT COUNT(*) FROM properties WHERE owner_id = ? AND status <> 'deleted'", [$ownerA]) === 3);
$a->get('/owner/properties');
check('My Properties: drafts show Continue draft and Delete draft', $a->has('Continue draft') && $a->has('Delete draft'));
$a->post("/owner/properties/{$flat}/delete");
check('a submitted listing cannot be deleted by the owner', prop($flat)['status'] === 'pending' && followHas($a, 'Only drafts can be deleted'));
$a->get('/owner/properties');
$a->post("/owner/properties/{$shop}/delete");
check('owner deletes a draft (soft delete)', prop($shop)['status'] === 'deleted' && prop($shop)['deleted_at'] !== null);
$a->get('/owner/properties/new');
check('deleting a draft frees a slot', $a->status === 200 && $a->has('1 more property'));
$a->get("/owner/properties/{$shop}/step/2");
check('deleted draft can no longer be opened', $a->status === 404);

// ---------------------------------------------------------------------
echo "\nOwnership checks (Bina tries Rahul's listings)\n";
// ---------------------------------------------------------------------
$b->get("/owner/properties/{$flat}/step/2");
check('other owner cannot open a step', $b->status === 404);
$b->get("/owner/properties/{$flat}/edit");
check('other owner cannot open edit', $b->status === 404);
$b->get('/owner/properties');
$tok = $b->token();
$b->post("/owner/properties/{$flat}/step/2", ['_token' => $tok, 'title' => 'Hacked title here', 'action' => 'continue']);
check('other owner cannot save a step', $b->status === 404 && prop($flat)['title'] === 'Sunny 2 BHK near VR Mall');
$b->post("/owner/properties/{$switch}/submit", ['_token' => $tok]);
check('other owner cannot submit', $b->status === 404 && prop($switch)['status'] === 'draft');
$b->post("/owner/properties/{$switch}/delete", ['_token' => $tok]);
check('other owner cannot delete a draft', $b->status === 404 && prop($switch)['status'] === 'draft');
$b->post("/owner/properties/{$switch}/step/1", ['_token' => $tok, 'purpose' => 'pg', 'category_id' => catId('residential'), 'type_id' => typeId('flat')]);
check('other owner cannot change type', $b->status === 404 && (int) prop($switch)['type_id'] === typeId('office'));

// ---------------------------------------------------------------------
echo "\nIndustrial, PG, Lease, Commercial Plot (Bina)\n";
// ---------------------------------------------------------------------
$fac = startListing($b, $ownerB, 'lease', 'industrial', 'factory');
check('Lease › Industrial › Factory draft created', $fac > 0);
$b->get("/owner/properties/{$fac}/step/2");
check('factory has no furnishing or bedrooms', !$b->has('Furnishing') && !$b->has('Bedrooms'));
step($b, $fac, 2, ['title' => 'GIDC factory shed 10,000 sq ft', 'area_value' => '10000', 'area_unit' => 'sqft']);
step($b, $fac, 3, ['unit_no' => 'Plot 44', 'address_line' => 'GIDC Sachin, Surat', 'city_id' => '1', 'locality_id' => (string) localityId('vesu'), 'pincode' => '394230', 'coordinates' => '21.08, 72.88']);
check('factory: landmark is required', followHas($b, 'Please enter Landmark.'));
$b->get("/owner/properties/{$fac}/step/3");
check('factory address asks for "Plot No"', $b->has('Plot No') && !$b->has('Building name'));
$b->get("/owner/properties/{$fac}/step/2"); // ensure step 3 reachable again
step($b, $fac, 3, ['unit_no' => 'Plot 44', 'landmark' => 'Near Sachin GIDC gate', 'address_line' => 'GIDC Sachin, Surat', 'city_id' => '1', 'locality_id' => (string) localityId('vesu'), 'pincode' => '394230', 'coordinates' => '21.08, 72.88']);
$b->get("/owner/properties/{$fac}/step/4");
check('factory facilities: Electricity Backup and RCC Road', $b->has('Electricity Backup') && $b->has('RCC Road') && !$b->has('Pooja Room'));

$pg = startListing($b, $ownerB, 'pg', 'residential', 'flat');
check('PG › Flat draft created', $pg > 0);
$b->get("/owner/properties/{$pg}/step/2");
check('PG step 2 shows Available for / Suitable for', $b->has('Available for') && $b->has('Suitable for') && $b->has('Professionals'));
step($b, $pg, 2, ['title' => 'Girls PG near college', 'bedrooms' => '3', 'bathrooms' => '2', 'furnishing' => 'furnished', 'available_from' => $today]);
check('PG: Available for is required', followHas($b, 'Please choose Available for.'));
step($b, $pg, 2, ['title' => 'Girls PG near college', 'bedrooms' => '3', 'bathrooms' => '2', 'furnishing' => 'furnished', 'available_from' => $today, 'pg_available_for' => 'girls', 'pg_suitable_for' => 'students']);
check('PG details saved', prop($pg)['pg_available_for'] === 'girls' && prop($pg)['pg_suitable_for'] === 'students');
step($b, $pg, 3, ['address_line' => 'Judges Bungalow Road', 'city_id' => '2', 'locality_id' => 'other', 'locality_name' => 'Bodakdev', 'pincode' => '380054', 'coordinates' => '23.0395, 72.5070']);
$row = prop($pg);
check('area not in the list can be typed', $row['locality_id'] === null && $row['locality_name'] === 'Bodakdev' && (int) $row['city_id'] === 2);
check('PG slug includes typed area and city', str_contains($row['slug'], 'bodakdev-ahmedabad'), $row['slug']);
$b->get("/owner/properties/{$pg}/step/4");
check('PG step 4 offers Food / Laundry / Car Washing', $b->has('Other services with extra cost') && $b->has('Car Washing'));
step($b, $pg, 4, ['amenities' => [amenityId('wifi')], 'pg_services' => ['food', 'laundry', 'spa'], 'pg_service_price' => ['food' => '3,000', 'laundry' => '']]);
$sv = array_column(Database::all('SELECT service, price FROM property_pg_services WHERE property_id = ?', [$pg]), 'price', 'service');
check('PG services saved with optional price; unknown service ignored', $sv === ['food' => 3000, 'laundry' => null] || $sv == ['food' => '3000', 'laundry' => null], json_encode($sv));
$b->get("/owner/properties/{$pg}/step/5");
check('PG rent step has deposit but no maintenance field', $b->has('Security deposit') && !$b->has('Maintenance per month'));

$plot = startListing($b, $ownerB, 'rent', 'commercial', 'commercial_plot');
check('Rent › Commercial Plot draft created', $plot > 0);
$b->get("/owner/properties/{$plot}/step/2");
check('commercial plot has no furnishing', !$b->has('Furnishing'));
step($b, $plot, 2, ['title' => 'Open plot on highway', 'area_value' => '2000', 'area_unit' => 'sqyd', 'furnishing' => 'furnished', 'available_from' => $today]);
check('furnishing value ignored for a plot', prop($plot)['furnishing'] === null && (int) prop($plot)['draft_step'] === 2);
$b->get('/owner/properties/new');
check('Bina at 3 / 3 is blocked too', $b->status === 403);

// ---------------------------------------------------------------------
echo "\nRent changes on a live listing\n";
// ---------------------------------------------------------------------
Database::run("UPDATE properties SET status = 'active', published_at = UTC_TIMESTAMP() WHERE id = ?", [$flat]);
$rch = static fn (): array => Database::all('SELECT * FROM rental_change_history WHERE property_id = ? ORDER BY id', [$flat]);
$lastRch = static fn (): array => Database::one('SELECT * FROM rental_change_history WHERE property_id = ? ORDER BY id DESC LIMIT 1', [$flat]);
step($a, $flat, 5, ['rent' => '22000', 'security_deposit' => '50000', 'maintenance' => '1500']);
check('same rent: nothing recorded', count($rch()) === 0 && adminNotes('rent_changed', 'property', $flat) === 0);
step($a, $flat, 5, ['rent' => '21000', 'security_deposit' => '50000', 'maintenance' => '1500']);
$h = $rch();
check('decrease goes live at once', (int) prop($flat)['rent'] === 21000 && count($h) === 1 && $h[0]['status'] === 'auto_applied' && (int) $h[0]['difference'] === -1000);
check('decrease: admin alerted', (int) Database::value("SELECT COUNT(*) FROM notifications WHERE audience = 'admin' AND type = 'rent_changed' AND entity_id = ?", [$h[0]['id']]) === 1);
step($a, $flat, 5, ['rent' => '23000', 'security_deposit' => '50000', 'maintenance' => '1500']);
$h = $rch();
$row = prop($flat);
check('increase waits for approval; old rent stays live', (int) $row['rent'] === 21000 && end($h)['status'] === 'pending' && (int) $row['pending_rent_change_id'] === (int) end($h)['id']);
check('owner told the increase is waiting', followHas($a, 'waiting for admin approval') && $a->has('₹21,000 stays live'));
$alert = Database::one("SELECT * FROM notifications WHERE audience = 'admin' AND type = 'rent_changed' AND entity_id = ?", [end($h)['id']]);
check('RENTAL CHANGE ALERT with owner, old/new rent and link', $alert !== null && str_starts_with($alert['title'], 'RENTAL CHANGE ALERT')
    && str_contains($alert['body'], 'Rahul Test') && str_contains($alert['body'], '₹21,000 → ₹23,000') && str_contains($alert['body'], '+₹2,000')
    && $alert['link_url'] === '/admin/rent-changes/' . end($h)['id']);
check('change snapshot: owner mobile, email, URL, IP', end($h)['owner_mobile'] === '919222200101' && end($h)['owner_email'] === 'rahul4@test.invalid'
    && end($h)['property_url'] === '/rent/2-bhk-flat-vesu-surat-p' . $flat && end($h)['ip_address'] !== null);
$a->get("/owner/properties/{$flat}/step/5");
check('rent step shows the waiting change and the requested amount', $a->has('is waiting for admin approval') && $a->has('value="23000"'));
$a->get('/owner/properties');
check('My Properties shows "waiting for approval" tag', $a->has('₹23,000 waiting for approval'));
step($a, $flat, 5, ['rent' => '23000', 'security_deposit' => '50000', 'maintenance' => '1500']);
check('saving the same waiting rent adds nothing', count($rch()) === 2);
step($a, $flat, 5, ['rent' => '24000', 'security_deposit' => '50000', 'maintenance' => '1500']);
$h = $rch();
check('a new increase replaces the waiting one', count($h) === 3 && $h[1]['status'] === 'cancelled' && $h[2]['status'] === 'pending' && (int) prop($flat)['pending_rent_change_id'] === (int) $h[2]['id']);
step($a, $flat, 5, ['rent' => '21000', 'security_deposit' => '50000', 'maintenance' => '1500']);
$h = $rch();
check('setting the live rent again withdraws the change', end($h)['status'] === 'cancelled' && prop($flat)['pending_rent_change_id'] === null && count($h) === 3
    && adminNotes('rent_change_withdrawn', 'rent_change', (int) $h[2]['id']) === 1);
$setting('rent_change_approval_mode', 'all');
step($a, $flat, 5, ['rent' => '20000', 'security_deposit' => '50000', 'maintenance' => '1500']);
check('mode "all": a decrease also waits', $lastRch()['status'] === 'pending' && (int) prop($flat)['rent'] === 21000);
step($a, $flat, 5, ['rent' => '21000', 'security_deposit' => '50000', 'maintenance' => '1500']);
$setting('rent_change_approval_mode', 'none');
step($a, $flat, 5, ['rent' => '25000', 'security_deposit' => '50000', 'maintenance' => '1500']);
check('mode "none": increase goes live, still logged', (int) prop($flat)['rent'] === 25000 && $lastRch()['status'] === 'auto_applied');
$setting('rent_change_approval_mode', 'increase_only');
$a->get('/owner/rent-changes');
check('Rental Change History lists the changes', $a->has('₹25,000') && $a->has('Cancelled') && $a->has('Applied'));

// ---------------------------------------------------------------------
echo "\nEditing a live listing\n";
// ---------------------------------------------------------------------
$base2 = ['title' => 'Sunny 2 BHK near VR Mall, Vesu', 'description' => 'Bright corner flat.', 'bedrooms' => '2', 'bathrooms' => '2', 'balconies' => '1',
    'area_value' => '1250', 'area_unit' => 'sqft', 'furnishing' => 'semi_furnished', 'available_from' => $today];
step($a, $flat, 2, ['title' => ''] + $base2, 'draft');
check('live listing cannot be saved with a required field empty', followHas($a, 'Please enter Listing title.') && prop($flat)['title'] === 'Sunny 2 BHK near VR Mall');
step($a, $flat, 2, $base2, 'draft');
check('edit of a live listing: saved, stays active', redirectsTo($a, '/owner/properties') && prop($flat)['title'] === 'Sunny 2 BHK near VR Mall, Vesu' && prop($flat)['status'] === 'active');
check('edit logged', (bool) Database::value("SELECT 1 FROM activity_logs WHERE user_id = ? AND event = 'property.edited' AND entity_id = ?", [$ownerA, $flat]));
step($a, $flat, 2, $base2, 'draft');
check('saving without changes says so', followHas($a, 'No changes to save.'));
$setting('reapprove_on_owner_edit', '1');
step($a, $flat, 2, ['description' => 'Bright corner flat, newly painted.'] + $base2, 'draft');
check('with re-approval on, an edit sends the listing back to Pending', prop($flat)['status'] === 'pending' && adminNotes('property_edited', 'property', $flat) === 1);
$setting('reapprove_on_owner_edit', '0');
$a->get("/owner/properties/{$flat}/edit");
check('Edit on a submitted listing opens the overview', redirectsTo($a, "/owner/properties/{$flat}/step/8"));

Database::run("UPDATE properties SET status = 'rejected', rejection_reason = 'Please add the society name' WHERE id = ?", [$flat]);
$a->get("/owner/properties/{$flat}/step/8");
check('rejected listing shows admin note and Resubmit', $a->has('Please add the society name') && $a->has('Resubmit for Approval'));
$a->post("/owner/properties/{$flat}/submit");
check('resubmit → Pending, note cleared, admin notified', prop($flat)['status'] === 'pending' && prop($flat)['rejection_reason'] === null && adminNotes('property_resubmitted', 'property', $flat) === 1);

// ---------------------------------------------------------------------
echo "\nAccount status and CSRF\n";
// ---------------------------------------------------------------------
Database::run("UPDATE users SET status = 'inactive' WHERE id = ?", [$ownerA]);
$a->get("/owner/properties/{$switch}/step/2");
check('inactive owner cannot open the form', redirectsTo($a, '/account'));
$a->get('/owner/properties');
$a->post("/owner/properties/{$switch}/step/2", ['title' => 'Changed while inactive', 'action' => 'draft']);
check('inactive owner cannot save', redirectsTo($a, '/account') && prop($switch)['title'] === 'Family flat 3 BHK');
Database::run("UPDATE users SET status = 'active' WHERE id = ?", [$ownerA]);
$a->post("/owner/properties/{$switch}/step/2", ['title' => 'No token here', 'action' => 'draft'], false);
check('save without CSRF token is refused', $a->status === 419 && prop($switch)['title'] === 'Family flat 3 BHK');

// ---------------------------------------------------------------------
$cleanup();
echo PHP_EOL, "{$pass} passed, {$fail} failed.", PHP_EOL;
exit($fail > 0 ? 1 : 0);
