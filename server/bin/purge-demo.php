<?php
declare(strict_types=1);

/**
 * Removes every DEVELOPMENT / DEMO row (is_demo = 1) and everything that
 * hangs off it. Run before going live:
 *   php bin/purge-demo.php
 * Real users and properties (is_demo = 0) are never touched.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$demoProps = 'SELECT id FROM properties WHERE is_demo = 1';
$demoUsers = 'SELECT id FROM users WHERE is_demo = 1';

$counts = Database::transaction(function () use ($demoProps, $demoUsers): array {
    $n = [];
    // Rows referencing demo properties or demo users (FK RESTRICT tables first).
    // MySQL cannot reference the target table in a subquery, so ids are fetched first.
    $propIds = array_map('intval', array_column(Database::all($demoProps), 'id')) ?: [0];
    $userIds = array_map('intval', array_column(Database::all($demoUsers), 'id')) ?: [0];
    $p = implode(',', $propIds);
    $u = implode(',', $userIds);

    $n['contact_history']       = Database::run("DELETE FROM contact_history WHERE property_id IN ($p) OR user_id IN ($u)")->rowCount();
    $n['shortlists']            = Database::run("DELETE FROM shortlists WHERE property_id IN ($p) OR user_id IN ($u)")->rowCount();
    $n['enquiries']             = Database::run("DELETE FROM enquiries WHERE is_demo = 1 OR property_id IN ($p) OR user_id IN ($u)")->rowCount();
    $n['bookings']              = Database::run("DELETE FROM bookings WHERE is_demo = 1 OR property_id IN ($p) OR user_id IN ($u)")->rowCount();
    $n['rental_change_history'] = Database::run("DELETE FROM rental_change_history WHERE property_id IN ($p)")->rowCount();
    $n['notifications']         = Database::run("DELETE FROM notifications WHERE user_id IN ($u) OR (entity_type = 'property' AND entity_id IN ($p))")->rowCount();
    // Demo photo/video files go with the demo rows (rows cascade below).
    $n['media_files'] = 0;
    foreach (Database::all("SELECT media_type, storage_dir, file_key, ext FROM property_media WHERE property_id IN ($p)") as $m) {
        $abs = PUBLIC_ROOT . '/uploads/' . $m['storage_dir'];
        $m['media_type'] === 'video' ? @unlink("{$abs}/{$m['file_key']}.{$m['ext']}") : \App\Services\Media\ImageProcessor::deleteFiles($abs, $m['file_key']);
        $n['media_files']++;
    }
    $n['properties']            = Database::run("DELETE FROM properties WHERE id IN ($p)")->rowCount(); // media/locations/amenities cascade
    $n['users']                 = Database::run("DELETE FROM users WHERE id IN ($u)")->rowCount();      // roles/sessions cascade
    return $n;
});

foreach ($counts as $table => $count) {
    printf("%-24s %d removed\n", $table, $count);
}
