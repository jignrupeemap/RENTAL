<?php
declare(strict_types=1);

/**
 * Restore a backup made by bin/backup.php into the database named in
 * config/env.php. It refuses to run unless that database is EMPTY, so it can
 * never overwrite live data. Typical use (see docs/DEPLOYMENT.md → Restore):
 *   1. hPanel → Databases: create a new, empty database and user
 *   2. put the new DB_NAME / DB_USER / DB_PASSWORD in config/env.php
 *   3. php bin/restore-db.php backups/db-20261009-023000.sql.gz
 *   4. php bin/migrate.php   (applies any migration newer than the backup)
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\Deploy\DatabaseBackup;

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/restore-db.php backups/db-YYYYMMDD-HHMMSS.sql.gz\n");
    exit(1);
}
try {
    $r = DatabaseBackup::restore($file);
    echo "Restored {$r['statements']} statements from " . basename($file) . ". Now run: php bin/migrate.php\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Not restored: ' . $e->getMessage() . "\n");
    exit(1);
}
