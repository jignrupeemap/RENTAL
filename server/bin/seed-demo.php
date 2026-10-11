<?php
declare(strict_types=1);

/**
 * DEMO data for testing: demo owners/viewers, 35+ active listings (at least
 * 10 each for Rent, PG and Lease, with generated photos) and sample
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

/**
 * Draws a simple, clearly-fake property picture (1200 x 800 JPEG) so demo
 * cards are not empty. $kind: tower | house | shop | shed | room.
 */
$drawPhoto = static function (string $kind, int $seed, string $caption): string {
    mt_srand($seed);
    [$w, $h] = [1200, 800];
    $im = imagecreatetruecolor($w, $h);
    $c = static fn (int|float $r, int|float $g, int|float $b): int => imagecolorallocate($im, ...array_map(static fn ($v) => max(0, min(255, (int) $v)), [$r, $g, $b]));
    $hue = [[47, 111, 143], [58, 134, 120], [150, 96, 70], [120, 84, 140], [70, 110, 160], [160, 120, 60]][mt_rand(0, 5)];
    $wall = $c(...array_map(static fn ($v) => $v + mt_rand(-15, 15), $hue));
    $light = $c(236, 240, 244);

    if ($kind === 'room') {
        // Interior: wall, floor, window, sofa/bed block.
        imagefilledrectangle($im, 0, 0, $w, 560, $c(232 - mt_rand(0, 20), 226 - mt_rand(0, 20), 214));
        for ($y = 560; $y < $h; $y++) {
            imageline($im, 0, $y, $w, $y, $c(170 - ($y - 560) / 6, 130 - ($y - 560) / 8, 95));
        }
        imagefilledrectangle($im, 760, 120, 1060, 420, $c(170, 205, 235));
        imagerectangle($im, 760, 120, 1060, 420, $c(255, 255, 255));
        imageline($im, 910, 120, 910, 420, $c(255, 255, 255));
        imagefilledrectangle($im, 140, 420, 620, 600, $wall);
        imagefilledrectangle($im, 140, 360, 620, 440, $c($hue[0] + 30, $hue[1] + 30, $hue[2] + 30));
        imagefilledrectangle($im, 260, 140, 480, 300, $c(255, 255, 255));
        imagefilledrectangle($im, 272, 152, 468, 288, $c($hue[0] + 60, $hue[1] + 50, $hue[2] + 40));
    } else {
        // Sky gradient and ground.
        for ($y = 0; $y < 600; $y++) {
            imageline($im, 0, $y, $w, $y, $c(120 + $y / 6, 170 + $y / 10, 225));
        }
        imagefilledrectangle($im, 0, 600, $w, $h, $c(110, 150, 95));
        imagefilledrectangle($im, 0, 640, $w, 720, $c(120, 120, 125));
        for ($x = 0; $x < $w; $x += 120) {
            imagefilledrectangle($im, $x + 20, 676, $x + 80, 684, $c(235, 235, 235));
        }
        $windows = static function (int $x1, int $y1, int $x2, int $y2, int $cols, int $rows) use ($im, $c): void {
            $cw = ($x2 - $x1) / $cols;
            $rh = ($y2 - $y1) / $rows;
            for ($i = 0; $i < $cols; $i++) {
                for ($j = 0; $j < $rows; $j++) {
                    $lit = mt_rand(0, 4) === 0;
                    imagefilledrectangle($im, (int) ($x1 + $i * $cw + $cw * .2), (int) ($y1 + $j * $rh + $rh * .2),
                        (int) ($x1 + ($i + 1) * $cw - $cw * .2), (int) ($y1 + ($j + 1) * $rh - $rh * .25),
                        $lit ? $c(250, 225, 150) : $c(185, 215, 240));
                }
            }
        };
        switch ($kind) {
            case 'tower':
                $floors = mt_rand(7, 11);
                $top = 600 - $floors * 48;
                imagefilledrectangle($im, 330, $top, 870, 600, $wall);
                imagefilledrectangle($im, 330, $top - 14, 870, $top, $light);
                $windows(350, $top + 10, 850, 590, 6, $floors);
                imagefilledrectangle($im, 900, 360, 1080, 600, $c($hue[0] - 20, $hue[1] - 20, $hue[2] - 20));
                $windows(910, 370, 1070, 590, 3, 5);
                break;
            case 'house':
                imagefilledrectangle($im, 300, 330, 900, 600, $wall);
                imagefilledpolygon($im, [270, 340, 600, 160, 930, 340], $c(150, 60, 45));
                imagefilledrectangle($im, 540, 450, 660, 600, $c(110, 70, 40));
                $windows(330, 370, 520, 560, 2, 2);
                $windows(680, 370, 870, 560, 2, 2);
                imagefilledellipse($im, 1040, 540, 180, 200, $c(60, 120, 60));
                imagefilledellipse($im, 150, 550, 160, 170, $c(70, 130, 65));
                break;
            case 'shop':
                imagefilledrectangle($im, 200, 260, 1000, 600, $wall);
                imagefilledrectangle($im, 200, 230, 1000, 260, $light);
                for ($x = 200; $x < 1000; $x += 80) {
                    imagefilledrectangle($im, $x, 330, $x + 40, 380, $c(240, 240, 240));
                    imagefilledrectangle($im, $x + 40, 330, $x + 80, 380, $c(210, 70, 60));
                }
                imagefilledrectangle($im, 240, 400, 960, 600, $c(190, 220, 240));
                imageline($im, 600, 400, 600, 600, $c(90, 90, 90));
                imagefilledrectangle($im, 360, 275, 840, 320, $c(255, 255, 255));
                break;
            default: // shed / warehouse
                imagefilledrectangle($im, 160, 340, 1040, 600, $c(170, 175, 180));
                imagefilledpolygon($im, [130, 350, 600, 200, 1070, 350], $wall);
                for ($x = 170; $x < 1040; $x += 22) {
                    imageline($im, $x, 350, $x, 600, $c(150, 155, 160));
                }
                imagefilledrectangle($im, 300, 430, 520, 600, $c(95, 100, 105));
                imagefilledrectangle($im, 680, 430, 900, 600, $c(95, 100, 105));
                break;
        }
    }

    // "DEMO" caption, scaled up from GD's built-in font.
    $text = strtoupper('Demo photo · ' . $caption);
    $text = preg_replace('/[^\x20-\x7E]/', '-', $text);
    $tw = imagefontwidth(5) * strlen($text) + 12;
    $label = imagecreatetruecolor($tw, 24);
    imagefill($label, 0, 0, imagecolorallocate($label, 20, 30, 40));
    imagestring($label, 5, 6, 4, $text, imagecolorallocate($label, 255, 255, 255));
    imagecopyresized($im, $label, 24, $h - 72, 0, 0, $tw * 2, 48, $tw, 24);
    imagedestroy($label);

    $tmp = tempnam(sys_get_temp_dir(), 'demo');
    imagejpeg($im, $tmp, 90);
    imagedestroy($im);
    return $tmp;
};

$written = []; // [dir, key] of generated photos, removed again if seeding fails
$users = [];
$propIds = [];
$startedAt = time();

try {
Database::transaction(function () use ($hash, $type, $locality, $amenity, $centres, $drawPhoto, &$written, &$users, &$propIds, $startedAt): void {
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

    $kindOf = static fn (string $typeCode): string => match ($typeCode) {
        'flat' => 'tower',
        'bungalow', 'farmhouse_villa' => 'house',
        'office', 'shop', 'showroom', 'restaurant', 'hall', 'house_building', 'hospital', 'school', 'hotel', 'gym' => 'shop',
        default => 'shed',
    };
    $dir = 'properties/' . gmdate('Y/m');
    $abs = PUBLIC_ROOT . '/uploads/' . $dir;

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

        // Two generated photos: outside view (cover) and an inside view.
        foreach ([$kindOf($typeCode), 'room'] as $n => $kind) {
            $tmp = $drawPhoto($kind, $pid * 10 + $n, $n === 0 ? $title : 'inside view');
            $key = bin2hex(random_bytes(16));
            try {
                $img = ImageProcessor::process($tmp, 'image/jpeg', $abs, $key);
            } finally {
                @unlink($tmp);
            }
            $written[] = [$abs, $key];
            $mid = Database::insert(
                "INSERT INTO property_media (property_id, media_type, storage_dir, file_key, ext, has_webp, width, height, size_bytes,
                                             mime_type, original_name, alt_text, sort_order, is_cover, uploaded_by)
                 VALUES (?, 'image', ?, ?, 'jpg', ?, ?, ?, ?, 'image/jpeg', ?, ?, ?, ?, ?)",
                [$pid, $dir, $key, $img['has_webp'] ? 1 : 0, $img['width'], $img['height'], $img['size_bytes'],
                 "demo-{$n}.jpg", $title, $n, $n === 0 ? 1 : 0, $o['id']]
            );
            if ($n === 0) {
                Database::run('UPDATE properties SET cover_media_id = ? WHERE id = ?', [$mid, $pid]);
            }
        }
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
}

$perPurpose = Database::all('SELECT purpose, COUNT(*) AS n FROM properties WHERE is_demo = 1 GROUP BY purpose ORDER BY purpose');
echo "Demo data created (flagged is_demo = 1): ", count($propIds), " listings (",
    implode(', ', array_map(static fn ($r) => "{$r['purpose']} {$r['n']}", $perPurpose)), ").\n";
echo "Demo logins: mobile 9000000001 to 9000000006, password {$password}\n";
echo "Remove all of it later with: php bin/purge-demo.php\n";
