<?php
declare(strict_types=1);

/**
 * DEMO data for testing: demo owners/viewers, 35+ active listings (at least
 * 10 each for Rent, PG and Lease, with real stock photos downloaded from
 * Unsplash, or generated pictures when offline) and sample
 * enquiries, bookings and alerts. Every row is flagged is_demo = 1.
 *   php bin/seed-demo.php                     add demo data (development)
 *   php bin/seed-demo.php --allow-production  same on the live site, for testing
 *   php bin/purge-demo.php                    remove every demo row and photo
 * Demo logins: mobile 9000000001 ... 9000000006. Password Demo@12345 in
 * development; on production a random password is printed once instead.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Deploy\DemoPhotos;
use App\Services\Media\ImageProcessor;

$production = Config::isProduction();
if ($production && !in_array('--allow-production', $argv, true)) {
    fwrite(STDERR, "APP_ENV is production. To add demo listings to the live site anyway, run:\n  php bin/seed-demo.php --allow-production\nRemove them later with: php bin/purge-demo.php\n");
    exit(1);
}
if (Database::value('SELECT COUNT(*) FROM users WHERE is_demo = 1') > 0) {
    echo "Demo data already present. Run bin/purge-demo.php first to reseed.\n";
    exit(0);
}

// A known password on a public site would let anyone sign in as a demo owner.
$password = $production ? 'Demo-' . bin2hex(random_bytes(6)) : 'Demo@12345';
$hash = password_hash($password, PASSWORD_DEFAULT);
$type = static fn (string $code): array => Database::one(
    'SELECT t.id, t.category_id FROM property_types t WHERE t.code = ?', [$code]
) ?? throw new RuntimeException("Unknown type {$code}");
$locality = static fn (string $slug): array => Database::one('SELECT id, name, pincode FROM localities WHERE slug = ? AND city_id = 1', [$slug])
    ?? throw new RuntimeException("Unknown locality {$slug}");
$amenity = static fn (string $code): int => (int) Database::value('SELECT id FROM amenities WHERE code = ?', [$code]);

// Rough centre of each Surat locality, for the map pin.
$centres = [
    'vesu' => [21.1418, 72.7709], 'adajan' => [21.1959, 72.7933], 'pal' => [21.2010, 72.7740], 'piplod' => [21.1560, 72.7720],
    'city-light' => [21.1690, 72.7930], 'althan' => [21.1530, 72.8070], 'varachha' => [21.2100, 72.8650], 'katargam' => [21.2290, 72.8330],
    'udhna' => [21.1700, 72.8400], 'sachin-gidc' => [21.0830, 72.8660], 'pandesara-gidc' => [21.1350, 72.8390], 'hazira' => [21.1100, 72.6500],
];


$written = []; // [dir, key] of generated photos, removed again if seeding fails
$users = [];
$propIds = [];
$startedAt = time();

try {
Database::transaction(function () use ($hash, $type, $locality, $amenity, $centres, &$written, &$users, &$propIds, $startedAt): void {
    foreach ([
        // first, last, mobile, email, roles, unlimited listings
        ['Rahul',  'Patel', '919000000001', 'rahul.demo@example.com',  ['viewer', 'owner'], false],
        ['Priya',  'Shah',  '919000000002', 'priya.demo@example.com',  ['viewer'], false],
        ['Amit',   'Desai', '919000000003', 'amit.demo@example.com',   ['viewer', 'owner'], true],
        ['Neha',   'Mehta', '919000000004', 'neha.demo@example.com',   ['viewer', 'owner'], true],
        ['Kiran',  'Joshi', '919000000005', 'kiran.demo@example.com',  ['viewer', 'owner'], true],
        ['Vikram', 'Rao',   '919000000006', 'vikram.demo@example.com', ['viewer', 'owner'], true],
    ] as [$first, $last, $mobile, $email, $roles, $unlimited]) {
        $id = Database::insert(
            'INSERT INTO users (first_name, last_name, mobile, email, password_hash, mobile_verified_at, property_limit, property_limit_unlimited, is_demo)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), 3, ?, 1)',
            [$first, $last, $mobile, $email, $hash, $unlimited ? 1 : 0]
        );
        foreach ($roles as $role) {
            Database::run('INSERT INTO user_roles (user_id, role) VALUES (?, ?)', [$id, $role]);
        }
        $users[$first] = ['id' => $id, 'mobile' => $mobile, 'name' => "{$first} {$last}"];
    }

    $res = static fn (int $bhk, string $furn, array $more = []): array => ['bedrooms' => $bhk, 'bathrooms' => max(1, $bhk - ($bhk > 2 ? 1 : 0)), 'balconies' => min(2, $bhk - 1), 'furnishing' => $furn] + $more;
    $pg = static fn (int $bhk, string $for, string $suits, string $furn = 'furnished'): array => ['bedrooms' => $bhk, 'bathrooms' => $bhk, 'furnishing' => $furn, 'pg_available_for' => $for, 'pg_suitable_for' => $suits];
    $area = static fn (int $v, string $unit = 'sqft', ?string $furn = 'unfurnished', array $more = []): array =>
        ['area_value' => $v, 'area_unit' => $unit, 'area_sqft' => $unit === 'sqyd' ? $v * 9 : $v] + ($furn === null ? [] : ['furnishing' => $furn]) + $more;

    $props = [
        // owner, purpose, type, title, locality, extra columns, rent, amenities, featured
        // Rahul is at his 3-listing limit (used by the limit-request demo below).
        ['Rahul', 'rent',  'flat',             '2 BHK Premium Apartment', 'vesu', $res(2, 'semi_furnished', ['building_name' => 'Green Residency']), 22000, ['lift', 'car_parking', 'power_backup'], false],
        ['Rahul', 'pg',    'flat',             'PG for Working Professionals', 'adajan', $pg(3, 'boys', 'professionals'), 8000, ['wifi', 'study_room'], false],
        ['Rahul', 'lease', 'shop',             'Ground Floor Shop on Main Road', 'city-light', $area(450), 35000, ['personal_washroom', 'power_backup'], false],
        ['Amit',  'rent',  'warehouse_godown', 'Warehouse near Sachin GIDC', 'sachin-gidc', $area(1200, 'sqyd', null, ['landmark' => 'Near GIDC Gate 2']), 65000, ['electricity_backup', 'rcc_road'], false],
        ['Amit',  'rent',  'bungalow',         '4 BHK Bungalow with Garden', 'piplod', $res(4, 'furnished'), 55000, ['garden', 'pooja_room', 'servant_room'], false],

        // Rent
        ['Neha',   'rent', 'flat',     '1 BHK Compact Flat near Station', 'varachha', $res(1, 'unfurnished', ['building_name' => 'Shreeji Apartment']), 9500, ['water_supply', 'two_wheeler_parking'], false],
        ['Neha',   'rent', 'flat',     '3 BHK Spacious Flat with Lake View', 'pal', $res(3, 'semi_furnished', ['building_name' => 'Lake View Heights']), 28000, ['lift', 'car_parking', 'gated_society', 'cctv'], true],
        ['Kiran',  'rent', 'flat',     '2 BHK Furnished Flat in Adajan', 'adajan', $res(2, 'furnished', ['building_name' => 'Sundaram Residency']), 19000, ['lift', 'car_parking', 'air_conditioning'], false],
        ['Kiran',  'rent', 'flat',     '3 BHK Flat in Gated Society', 'althan', $res(3, 'unfurnished', ['building_name' => 'Avadh Greens']), 21000, ['gated_society', 'play_area', 'security', 'lift'], false],
        ['Vikram', 'rent', 'bungalow', '3 BHK Row House with Parking', 'katargam', $res(3, 'semi_furnished'), 24000, ['car_parking', 'garden', 'water_supply'], false],
        ['Vikram', 'rent', 'farmhouse_villa', '5 BHK Villa with Private Lawn', 'hazira', $res(5, 'furnished'), 85000, ['garden', 'servant_room', 'security', 'car_parking'], true],
        ['Amit',   'rent', 'office',   'Furnished Office for 15 People', 'city-light', $area(900, 'sqft', 'furnished', ['building_name' => 'Rajhans Business Hub']), 42000, ['lift', 'power_backup', 'air_conditioning', 'wifi'], false],
        ['Neha',   'rent', 'shop',     'Corner Shop in Busy Market', 'udhna', $area(300, 'sqft', 'unfurnished', ['building_name' => 'Udhna Shopping Centre']), 18000, ['power_backup', 'cctv'], false],
        ['Kiran',  'rent', 'flat',     '2 BHK Flat for Small Family', 'katargam', $res(2, 'semi_furnished', ['building_name' => 'Krishna Park']), 13500, ['lift', 'two_wheeler_parking', 'water_supply'], false],
        ['Vikram', 'rent', 'flat',     '4 BHK Penthouse with Terrace', 'vesu', $res(4, 'furnished', ['building_name' => 'Skyline Towers']), 65000, ['lift', 'car_parking', 'air_conditioning', 'gated_society', 'power_backup'], false],

        // PG
        ['Neha',   'pg', 'flat',     'Girls PG with Home-cooked Food', 'vesu', $pg(3, 'girls', 'students'), 7500, ['wifi', 'security', 'water_supply'], true],
        ['Kiran',  'pg', 'flat',     'Boys PG near VNSGU Campus', 'pal', $pg(2, 'boys', 'students', 'semi_furnished'), 6000, ['wifi', 'study_room', 'two_wheeler_parking'], false],
        ['Vikram', 'pg', 'bungalow', 'Premium PG Rooms in Bungalow', 'piplod', $pg(5, 'any', 'professionals'), 12000, ['wifi', 'air_conditioning', 'garden', 'car_parking'], false],
        ['Amit',   'pg', 'flat',     'Sharing Rooms for Students', 'katargam', $pg(2, 'boys', 'students', 'semi_furnished'), 5000, ['wifi', 'water_supply'], false],
        ['Neha',   'pg', 'flat',     'Ladies PG in Safe Society', 'adajan', $pg(3, 'girls', 'both'), 8500, ['wifi', 'security', 'cctv', 'lift'], false],
        ['Kiran',  'pg', 'bungalow', 'Co-living PG with AC Rooms', 'althan', $pg(4, 'any', 'both'), 10000, ['wifi', 'air_conditioning', 'power_backup'], true],
        ['Vikram', 'pg', 'flat',     'Single Room PG for Professionals', 'city-light', $pg(2, 'boys', 'professionals'), 9000, ['wifi', 'lift', 'car_parking'], false],
        ['Amit',   'pg', 'flat',     'Budget PG near Udhna Station', 'udhna', $pg(3, 'any', 'both', 'semi_furnished'), 4500, ['water_supply', 'two_wheeler_parking'], false],
        ['Neha',   'pg', 'flat',     'Girls Hostel with Study Room', 'varachha', $pg(4, 'girls', 'students'), 6500, ['study_room', 'wifi', 'cctv'], false],
        ['Kiran',  'pg', 'bungalow', 'Family-run PG with Meals', 'vesu', $pg(4, 'boys', 'both'), 9500, ['wifi', 'pooja_room', 'garden'], false],

        // Lease
        ['Vikram', 'lease', 'office',          'Corporate Office Floor on Lease', 'vesu', $area(3500, 'sqft', 'semi_furnished', ['building_name' => 'Times Square Arcade']), 175000, ['lift', 'power_backup', 'car_parking', 'air_conditioning'], true],
        ['Amit',   'lease', 'showroom',        'Double-height Showroom on Ring Road', 'udhna', $area(2200, 'sqft', 'unfurnished', ['building_name' => 'Ring Road Plaza']), 120000, ['car_parking', 'power_backup', 'cctv'], false],
        ['Neha',   'lease', 'restaurant',      'Ready Restaurant Space with Kitchen', 'piplod', $area(1800, 'sqft', 'furnished', ['building_name' => 'Food Court Complex']), 95000, ['water_supply', 'power_backup', 'car_parking'], false],
        ['Kiran',  'lease', 'hall',            'Banquet Hall for Events', 'althan', $area(5000, 'sqft', 'semi_furnished', ['building_name' => 'Shubh Mangal Complex']), 150000, ['air_conditioning', 'car_parking', 'power_backup'], false],
        ['Vikram', 'lease', 'commercial_plot', 'Commercial Plot on Main Road', 'pal', $area(1500, 'sqyd', null), 90000, ['rcc_road'], false],
        ['Amit',   'lease', 'factory',         'Textile Factory with Power Connection', 'pandesara-gidc', $area(2500, 'sqyd', null, ['landmark' => 'Behind Pandesara Police Station']), 160000, ['three_phase_power', 'electricity_backup', 'loading_dock'], true],
        ['Neha',   'lease', 'warehouse_godown', 'Large Godown near Highway', 'sachin-gidc', $area(3000, 'sqyd', null, ['landmark' => 'Near Sachin Railway Crossing']), 140000, ['loading_dock', 'rcc_road', 'security'], false],
        ['Kiran',  'lease', 'shed',            'Industrial Shed in GIDC', 'pandesara-gidc', $area(800, 'sqyd', null, ['landmark' => 'GIDC Road No. 3']), 55000, ['three_phase_power', 'rcc_road'], false],
        ['Vikram', 'lease', 'industrial_plot', 'Open Industrial Plot near Port', 'hazira', $area(5000, 'sqyd', null, ['landmark' => 'Hazira Port Road']), 110000, ['rcc_road', 'security'], false],
        ['Amit',   'lease', 'cold_storage',    'Cold Storage Unit with Backup', 'sachin-gidc', $area(1200, 'sqyd', null, ['landmark' => 'Near GIDC Gate 4']), 130000, ['electricity_backup', 'cctv', 'loading_dock'], false],
        ['Neha',   'lease', 'office',          'Small Office Space for Startups', 'adajan', $area(600, 'sqft', 'furnished', ['building_name' => 'Silver Business Point']), 30000, ['lift', 'wifi', 'air_conditioning'], false],
    ];

    foreach ($props as $i => [$owner, $purpose, $typeCode, $title, $loc, $extra, $rent, $amenities, $featured]) {
        $t = $type($typeCode);
        $l = $locality($loc);
        $o = $users[$owner];
        $published = gmdate('Y-m-d H:i:s', $startedAt - $i * 3 * 3600); // spread over the last few days
        $cols = [
            'owner_id' => $o['id'], 'created_by' => $o['id'], 'purpose' => $purpose,
            'category_id' => $t['category_id'], 'type_id' => $t['id'], 'status' => 'active', 'draft_step' => 8,
            'title' => $title, 'slug' => slugify($title . ' ' . $loc . ' surat'),
            'description' => "{$title} in {$l['name']}, Surat.\n\nThis is a DEMO listing created for testing the website. It is not a real property and will be removed before launch.",
            'address_line' => 'Demo address, ' . $l['name'], 'city_id' => 1, 'locality_id' => (int) $l['id'], 'pincode' => $l['pincode'],
            'available_from' => gmdate('Y-m-d', $startedAt + ($i % 4) * 7 * 86400), 'rent' => $rent,
            'security_deposit' => $purpose === 'pg' ? $rent : $rent * ($purpose === 'lease' ? 6 : 2),
            'contact_name' => $o['name'] . ' (demo)', 'contact_mobile_1' => $o['mobile'],
            'submitted_at' => $published, 'approved_at' => $published, 'published_at' => $published,
            'display_rank' => $i < 5 ? $i + 1 : 1000000, 'is_demo' => 1,
        ] + ($featured ? ['is_featured' => 1, 'featured_until' => gmdate('Y-m-d H:i:s', $startedAt + 30 * 86400)] : []) + $extra;
        $sql = 'INSERT INTO properties (' . implode(',', array_keys($cols)) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')';
        $pid = Database::insert($sql, array_values($cols));

        [$lat, $lng] = $centres[$loc];
        $lat += (($i * 37) % 21 - 10) / 2000;
        $lng += (($i * 53) % 21 - 10) / 2000;
        Database::run(
            'INSERT INTO property_locations (property_id, latitude, longitude, public_latitude, public_longitude, formatted_address, privacy)
             VALUES (?, ?, ?, ?, ?, ?, "approximate")',
            [$pid, $lat, $lng, $lat + 0.0021, $lng - 0.0017, $l['name'] . ', Surat, Gujarat']
        );
        foreach ($amenities as $code) {
            Database::run('INSERT INTO property_amenities (property_id, amenity_id) VALUES (?, ?)', [$pid, $amenity($code)]);
        }
        if ($purpose === 'pg') {
            Database::run('INSERT INTO property_pg_services (property_id, service, price) VALUES (?, "food", 3500), (?, "laundry", 500)', [$pid, $pid]);
        }

        // Two photos: outside view (cover) and an inside view.
        array_push($written, ...DemoPhotos::attach($pid, $typeCode, $title, $o['id'], $i));
        Database::run('INSERT INTO property_status_history (property_id, old_status, new_status, actor_role, reason) VALUES (?, NULL, "active", "system", "demo seed")', [$pid]);
        $propIds[] = $pid;
    }

    // Viewer Priya: shortlist, enquiry, booking
    $p = $propIds[0];
    Database::run('INSERT INTO shortlists (user_id, property_id) VALUES (?, ?)', [$users['Priya']['id'], $p]);
    Database::run('UPDATE properties SET shortlists_count = 1, enquiries_count = 1, bookings_count = 1 WHERE id = ?', [$p]);
    $enq = Database::insert(
        'INSERT INTO enquiries (source, property_id, owner_id, user_id, first_name, last_name, mobile_1, email, interested_in, message,
                                property_title, property_url, owner_name, rent_at_enquiry, is_demo)
         VALUES ("property_page", ?, ?, ?, "Priya", "Shah", "919000000002", "priya.demo@example.com", "rent", "Is parking included?",
                 "2 BHK Premium Apartment", ?, "Rahul Patel", 22000, 1)',
        [$p, $users['Rahul']['id'], $users['Priya']['id'], '/rent/2-bhk-premium-apartment-vesu-surat-p' . $p]
    );
    Database::run('INSERT INTO contact_history (user_id, property_id, owner_id, enquiry_id, action, rent_at_contact) VALUES (?, ?, ?, ?, "enquiry", 22000)',
        [$users['Priya']['id'], $p, $users['Rahul']['id'], $enq]);
    Database::run(
        'INSERT INTO bookings (property_id, owner_id, user_id, preferred_date, preferred_time, message, property_title, rent_at_request, is_demo)
         VALUES (?, ?, ?, DATE_ADD(UTC_DATE(), INTERVAL 3 DAY), "11:00:00", "Weekend visit please", "2 BHK Premium Apartment", 22000, 1)',
        [$p, $users['Rahul']['id'], $users['Priya']['id']]
    );

    // Pending rent increase 22,000 → 24,000 (shows the admin alert in Phase 10)
    $rc = Database::insert(
        'INSERT INTO rental_change_history (property_id, owner_id, changed_by, old_rent, new_rent, difference, change_type, status,
                                            property_title, property_url, owner_name, owner_mobile, owner_email)
         VALUES (?, ?, ?, 22000, 24000, 2000, "increase", "pending", "2 BHK Premium Apartment", ?, "Rahul Patel", "919000000001", "rahul.demo@example.com")',
        [$p, $users['Rahul']['id'], $users['Rahul']['id'], '/rent/2-bhk-premium-apartment-vesu-surat-p' . $p]
    );
    Database::run('UPDATE properties SET pending_rent_change_id = ? WHERE id = ?', [$rc, $p]);

    // Rahul is at 3/3 and asks for more
    Database::run(
        'INSERT INTO property_limit_requests (user_id, current_limit, current_count, requested_limit, reason) VALUES (?, 3, 3, 10, "Managing more flats for family members.")',
        [$users['Rahul']['id']]
    );
});
} catch (Throwable $e) {
    foreach ($written as [$d, $k]) {
        ImageProcessor::deleteFiles($d, $k);
    }
    throw $e;
} finally {
    DemoPhotos::cleanup();
}

$perPurpose = Database::all('SELECT purpose, COUNT(*) AS n FROM properties WHERE is_demo = 1 GROUP BY purpose ORDER BY purpose');
echo "Demo data created (flagged is_demo = 1): ", count($propIds), " listings (",
    implode(', ', array_map(static fn ($r) => "{$r['purpose']} {$r['n']}", $perPurpose)), ").\n";
printf("Photos: %d real, %d generated (download failed).\n", DemoPhotos::$realUsed, DemoPhotos::$drawnUsed);
echo "Demo logins: mobile 9000000001 to 9000000006, password {$password}\n";
echo "Remove all of it later with: php bin/purge-demo.php\n";
