<?php
declare(strict_types=1);

/**
 * Makes the area map picture (public/uploads/maps) for every live listing
 * that doesn't have one yet. deploy.sh runs it after each deploy; pages
 * also make a missing picture on first view.
 *   php bin/map-images.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\Media\AreaMapImage;

$rows = Database::all(
    "SELECT l.property_id, l.public_latitude, l.public_longitude, l.privacy
     FROM property_locations l JOIN properties p ON p.id = l.property_id
     WHERE p.status <> 'deleted' AND l.public_latitude IS NOT NULL AND l.public_longitude IS NOT NULL"
);
$made = $had = $failed = 0;
foreach ($rows as $r) {
    [$lat, $lng, $exact] = [(float) $r['public_latitude'], (float) $r['public_longitude'], $r['privacy'] === 'exact'];
    if (AreaMapImage::url($lat, $lng, $exact, false) !== null) {
        $had++;
        continue;
    }
    if (AreaMapImage::url($lat, $lng, $exact) !== null) {
        $made++;
        usleep(200000); // be gentle with the free OpenStreetMap tile server
    } else {
        $failed++;
        echo "  could not make the map picture for listing #{$r['property_id']} (see storage/logs)\n";
    }
}
echo "Area map pictures: {$made} made, {$had} already there, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
