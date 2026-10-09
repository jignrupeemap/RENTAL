<?php
declare(strict_types=1);

/**
 * DEVELOPMENT / DEMO data only. Refuses to run when APP_ENV=production.
 *   php bin/seed-demo.php          add demo users, properties, enquiries...
 *   php bin/purge-demo.php         remove every row flagged is_demo = 1
 * Demo logins (development only): mobile 9000000001 / 9000000002 / 9000000003,
 * password Demo@12345
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

if (Config::isProduction()) {
    fwrite(STDERR, "Refusing to seed demo data: APP_ENV is production.\n");
    exit(1);
}
if (Database::value('SELECT COUNT(*) FROM users WHERE is_demo = 1') > 0) {
    echo "Demo data already present. Run bin/purge-demo.php first to reseed.\n";
    exit(0);
}

$hash = password_hash('Demo@12345', PASSWORD_DEFAULT);
$type = static fn (string $code): array => Database::one(
    'SELECT t.id, t.category_id FROM property_types t WHERE t.code = ?', [$code]
) ?? throw new RuntimeException("Unknown type {$code}");
$locality = static fn (string $slug): int => (int) Database::value('SELECT id FROM localities WHERE slug = ? AND city_id = 1', [$slug]);
$amenity = static fn (string $code): int => (int) Database::value('SELECT id FROM amenities WHERE code = ?', [$code]);

Database::transaction(function () use ($hash, $type, $locality, $amenity): void {
    $users = [];
    $propIds = [];
    foreach ([
        ['Rahul', 'Patel', '919000000001', 'rahul.demo@example.com', ['viewer', 'owner']],
        ['Priya', 'Shah',  '919000000002', 'priya.demo@example.com', ['viewer']],
        ['Amit',  'Desai', '919000000003', 'amit.demo@example.com',  ['viewer', 'owner']],
    ] as [$first, $last, $mobile, $email, $roles]) {
        $id = Database::insert(
            'INSERT INTO users (first_name, last_name, mobile, email, password_hash, mobile_verified_at, property_limit, is_demo)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), 3, 1)',
            [$first, $last, $mobile, $email, $hash]
        );
        foreach ($roles as $role) {
            Database::run('INSERT INTO user_roles (user_id, role) VALUES (?, ?)', [$id, $role]);
        }
        $users[$first] = $id;
    }

    $props = [
        // owner, purpose, type, title, locality, extra columns, rent, amenities
        ['Rahul', 'rent',  'flat',             '2 BHK Premium Apartment', 'vesu',
            ['bedrooms' => 2, 'bathrooms' => 2, 'balconies' => 1, 'furnishing' => 'semi_furnished', 'building_name' => 'Green Residency'], 22000, ['lift', 'car_parking', 'power_backup']],
        ['Rahul', 'pg',    'flat',             'PG for Working Professionals', 'adajan',
            ['bedrooms' => 3, 'bathrooms' => 2, 'furnishing' => 'furnished', 'pg_available_for' => 'boys', 'pg_suitable_for' => 'professionals'], 8000, ['wifi', 'study_room']],
        ['Rahul', 'lease', 'shop',             'Ground Floor Shop on Main Road', 'city-light',
            ['area_value' => 450, 'area_unit' => 'sqft', 'area_sqft' => 450, 'furnishing' => 'unfurnished'], 35000, ['personal_washroom', 'power_backup']],
        ['Amit',  'rent',  'warehouse_godown', 'Warehouse near Sachin GIDC', 'sachin-gidc',
            ['area_value' => 1200, 'area_unit' => 'sqyd', 'area_sqft' => 10800, 'landmark' => 'Near GIDC Gate 2'], 65000, ['electricity_backup', 'rcc_road']],
        ['Amit',  'rent',  'bungalow',         '4 BHK Bungalow with Garden', 'piplod',
            ['bedrooms' => 4, 'bathrooms' => 4, 'balconies' => 2, 'furnishing' => 'furnished'], 55000, ['garden', 'pooja_room', 'servant_room']],
    ];

    foreach ($props as $i => [$owner, $purpose, $typeCode, $title, $loc, $extra, $rent, $amenities]) {
        $t = $type($typeCode);
        $localityId = $locality($loc);
        $cols = [
            'owner_id' => $users[$owner], 'created_by' => $users[$owner], 'purpose' => $purpose,
            'category_id' => $t['category_id'], 'type_id' => $t['id'], 'status' => 'active', 'draft_step' => 8,
            'title' => $title, 'slug' => slugify($title . ' ' . $loc . ' surat'),
            'description' => 'Demo listing for development. Not a real property.',
            'address_line' => 'Demo address', 'city_id' => 1, 'locality_id' => $localityId, 'pincode' => '395007',
            'available_from' => gmdate('Y-m-d'), 'rent' => $rent, 'security_deposit' => $rent * 2,
            'contact_name' => $owner . ' (demo)', 'contact_mobile_1' => '91900000000' . ($owner === 'Rahul' ? 1 : 3),
            'submitted_at' => now_utc(), 'approved_at' => now_utc(), 'published_at' => now_utc(),
            'display_rank' => $i + 1, 'is_demo' => 1,
        ] + $extra;
        $sql = 'INSERT INTO properties (' . implode(',', array_keys($cols)) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')';
        $pid = Database::insert($sql, array_values($cols));

        Database::run(
            'INSERT INTO property_locations (property_id, latitude, longitude, public_latitude, public_longitude, formatted_address, privacy)
             VALUES (?, ?, ?, ?, ?, ?, "approximate")',
            [$pid, 21.1418 + $i / 100, 72.7709 + $i / 100, 21.1450 + $i / 100, 72.7730 + $i / 100, ucfirst($loc) . ', Surat, Gujarat']
        );
        foreach ($amenities as $code) {
            Database::run('INSERT INTO property_amenities (property_id, amenity_id) VALUES (?, ?)', [$pid, $amenity($code)]);
        }
        if ($purpose === 'pg') {
            Database::run('INSERT INTO property_pg_services (property_id, service, price) VALUES (?, "food", 3500), (?, "laundry", 500)', [$pid, $pid]);
        }
        Database::run('INSERT INTO property_status_history (property_id, old_status, new_status, actor_role, reason) VALUES (?, NULL, "active", "system", "demo seed")', [$pid]);
        $propIds[] = $pid;
    }

    // Viewer Priya: shortlist, enquiry, booking
    $p = $propIds[0];
    Database::run('INSERT INTO shortlists (user_id, property_id) VALUES (?, ?)', [$users['Priya'], $p]);
    Database::run('UPDATE properties SET shortlists_count = 1, enquiries_count = 1, bookings_count = 1 WHERE id = ?', [$p]);
    $enq = Database::insert(
        'INSERT INTO enquiries (source, property_id, owner_id, user_id, first_name, last_name, mobile_1, email, interested_in, message,
                                property_title, property_url, owner_name, rent_at_enquiry, is_demo)
         VALUES ("property_page", ?, ?, ?, "Priya", "Shah", "919000000002", "priya.demo@example.com", "rent", "Is parking included?",
                 "2 BHK Premium Apartment", ?, "Rahul Patel", 22000, 1)',
        [$p, $users['Rahul'], $users['Priya'], '/rent/2-bhk-premium-apartment-vesu-surat-p' . $p]
    );
    Database::run('INSERT INTO contact_history (user_id, property_id, owner_id, enquiry_id, action, rent_at_contact) VALUES (?, ?, ?, ?, "enquiry", 22000)',
        [$users['Priya'], $p, $users['Rahul'], $enq]);
    Database::run(
        'INSERT INTO bookings (property_id, owner_id, user_id, preferred_date, preferred_time, message, property_title, rent_at_request, is_demo)
         VALUES (?, ?, ?, DATE_ADD(UTC_DATE(), INTERVAL 3 DAY), "11:00:00", "Weekend visit please", "2 BHK Premium Apartment", 22000, 1)',
        [$p, $users['Rahul'], $users['Priya']]
    );

    // Pending rent increase 22,000 → 24,000 (shows the admin alert in Phase 10)
    $rc = Database::insert(
        'INSERT INTO rental_change_history (property_id, owner_id, changed_by, old_rent, new_rent, difference, change_type, status,
                                            property_title, property_url, owner_name, owner_mobile, owner_email)
         VALUES (?, ?, ?, 22000, 24000, 2000, "increase", "pending", "2 BHK Premium Apartment", ?, "Rahul Patel", "919000000001", "rahul.demo@example.com")',
        [$p, $users['Rahul'], $users['Rahul'], '/rent/2-bhk-premium-apartment-vesu-surat-p' . $p]
    );
    Database::run('UPDATE properties SET pending_rent_change_id = ? WHERE id = ?', [$rc, $p]);

    // Rahul is at 3/3 and asks for more
    Database::run(
        'INSERT INTO property_limit_requests (user_id, current_limit, current_count, requested_limit, reason) VALUES (?, 3, 3, 10, "Managing more flats for family members.")',
        [$users['Rahul']]
    );
});

echo "Demo data created (flagged is_demo = 1).\n";
