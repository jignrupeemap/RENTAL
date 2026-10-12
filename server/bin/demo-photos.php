<?php
declare(strict_types=1);

/**
 * Swaps the photos of existing DEMO listings (is_demo = 1) for real stock
 * photos from Unsplash. Real listings are never touched.
 *   php bin/demo-photos.php
 * Photos that cannot be downloaded keep a generated picture instead.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\Deploy\DemoPhotos;
use App\Services\Media\ImageProcessor;

$props = Database::all(
    'SELECT p.id, p.title, p.owner_id, p.created_by, t.code AS type_code
     FROM properties p JOIN property_types t ON t.id = p.type_id
     WHERE p.is_demo = 1 ORDER BY p.id'
);
if (!$props) {
    echo "No demo listings found. Add them first with: php bin/seed-demo.php --allow-production\n";
    exit(0);
}

try {
    foreach ($props as $n => $p) {
        $pid = (int) $p['id'];
        $old = Database::all("SELECT storage_dir, file_key FROM property_media WHERE property_id = ? AND media_type = 'image'", [$pid]);
        Database::transaction(function () use ($pid, $p, $n): void {
            Database::run('UPDATE properties SET cover_media_id = NULL WHERE id = ?', [$pid]);
            Database::run("DELETE FROM property_media WHERE property_id = ? AND media_type = 'image'", [$pid]);
            DemoPhotos::attach($pid, $p['type_code'], $p['title'], (int) ($p['owner_id'] ?? $p['created_by']), $n);
        });
        foreach ($old as $m) {
            ImageProcessor::deleteFiles(PUBLIC_ROOT . '/uploads/' . $m['storage_dir'], $m['file_key']);
        }
        echo '.';
    }
} finally {
    DemoPhotos::cleanup();
}
printf("\nUpdated %d demo listings: %d real photos, %d generated (download failed).\n", count($props), DemoPhotos::$realUsed, DemoPhotos::$drawnUsed);
