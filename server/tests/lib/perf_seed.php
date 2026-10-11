<?php
declare(strict_types=1);

/**
 * Bulk test listings for performance checks (DEVELOPMENT ONLY). Titles start
 * with "P14T", is_demo = 1 and owner = system, so perfUnseed() removes them all.
 */

use App\Core\Database;

function perfSeed(int $n): void
{
    $types = Database::all('SELECT id, category_id, field_profile FROM property_types');
    $locs = Database::all('SELECT id, city_id FROM localities');
    $cities = array_map('intval', array_column(Database::all('SELECT id FROM cities'), 'id'));
    $purposes = ['rent', 'rent', 'rent', 'pg', 'lease'];
    $furn = ['unfurnished', 'semi_furnished', 'furnished'];
    mt_srand(14);
    $cols = 'owner_id, created_by, purpose, category_id, type_id, status, draft_step, title, slug, description, city_id, locality_id, pincode,
             rent, security_deposit, bedrooms, bathrooms, furnishing, display_rank, is_featured, is_demo, available_from,
             contact_name, contact_mobile_1, submitted_at, published_at, approved_at, expires_at, created_at';
    // created_by must be a real user: an admin if there is one, else any account (e.g. demo data).
    $admin = (int) (Database::value("SELECT user_id FROM user_roles WHERE role IN ('admin', 'super_admin') ORDER BY user_id LIMIT 1")
        ?: Database::value('SELECT MIN(id) FROM users'));
    if ($admin === 0) {
        throw new RuntimeException('perfSeed needs at least one user (run bin/seed-demo.php or bin/create-admin.php).');
    }
    for ($done = 0; $done < $n; $done += 500) {
        $rows = [];
        $params = [];
        for ($i = $done; $i < min($n, $done + 500); $i++) {
            $t = $types[mt_rand(0, count($types) - 1)];
            $purpose = $t['field_profile'] === 'residential' ? $purposes[mt_rand(0, 4)] : (mt_rand(0, 1) ? 'rent' : 'lease');
            $loc = mt_rand(0, 3) ? $locs[mt_rand(0, count($locs) - 1)] : null;
            $city = $loc ? (int) $loc['city_id'] : $cities[mt_rand(0, count($cities) - 1)];
            $res = $t['field_profile'] === 'residential';
            $status = mt_rand(1, 10) <= 8 ? 'active' : ['pending', 'paused', 'draft', 'expired'][mt_rand(0, 3)];
            $age = mt_rand(0, 365 * 86400);
            $rows[] = '(' . rtrim(str_repeat('?, ', 29), ', ') . ')';
            array_push($params, null, $admin, $purpose, $t['category_id'], $t['id'], $status, 8, "P14T Load listing $i", "p14t-load-listing-$i",
                'Performance test listing.', $city, $loc ? (int) $loc['id'] : null, '395007',
                mt_rand(5, 150) * 1000, mt_rand(10, 300) * 1000, $res ? mt_rand(1, 5) : null, $res ? mt_rand(1, 4) : null, $furn[mt_rand(0, 2)],
                mt_rand(1, 50) === 1 ? mt_rand(1, 50) : 1000000, mt_rand(1, 100) === 1 ? 1 : 0, 1, gmdate('Y-m-d'),
                'Load Test', '919000000000', gmdate('Y-m-d H:i:s', time() - $age), $status === 'draft' ? null : gmdate('Y-m-d H:i:s', time() - $age),
                gmdate('Y-m-d H:i:s', time() - $age), gmdate('Y-m-d H:i:s', $status === 'expired' ? time() - 3600 : time() + 86400 * 60), gmdate('Y-m-d H:i:s', time() - $age));
        }
        Database::run("INSERT INTO properties ($cols) VALUES " . implode(', ', $rows), $params);
    }
    Database::run('ANALYZE TABLE properties');
}

function perfUnseed(): int
{
    $n = 0;
    do {
        $ids = array_map('intval', array_column(Database::all("SELECT id FROM properties WHERE title LIKE 'P14T%' AND is_demo = 1 LIMIT 2000"), 'id'));
        if ($ids) {
            $in = implode(',', $ids);
            Database::run("DELETE FROM property_view_daily WHERE property_id IN ($in)");
            Database::run("DELETE FROM property_status_history WHERE property_id IN ($in)");
            Database::run("DELETE FROM properties WHERE id IN ($in)");
            $n += count($ids);
        }
    } while ($ids);
    return $n;
}
