<?php
declare(strict_types=1);

/**
 * Back up the database (and optionally photos, videos and config/env.php)
 * into backups/ next to app/, outside the web root.
 *   php bin/backup.php              database only, keeps the newest 14
 *   php bin/backup.php --keep=30    keep 30 database backups
 *   php bin/backup.php --files      also uploads/ + config/env.php as a zip (keeps 2)
 * Hostinger cron (daily, 02:30): see docs/DEPLOYMENT.md → Backups.
 * Download the files from hPanel → File Manager → rental-app/backups.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Logger;
use App\Services\Deploy\DatabaseBackup;
use App\Services\Deploy\FilesBackup;

$opts = getopt('', ['keep::', 'files', 'dir::']);
$keep = max(1, (int) ($opts['keep'] ?? 14));
$dir = rtrim((string) ($opts['dir'] ?? APP_ROOT . '/backups'), '/');

if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}
// In case the whole app was uploaded inside public_html.
if (!is_file($dir . '/.htaccess')) {
    file_put_contents($dir . '/.htaccess', "Require all denied\n");
}

$stamp = gmdate('Ymd-His');
try {
    $file = "{$dir}/db-{$stamp}.sql.gz";
    $r = DatabaseBackup::dump($file);
    printf("Database: %s (%d tables, %d rows, %s KB)\n", basename($file), $r['tables'], $r['rows'], number_format($r['bytes'] / 1024, 1));
    $pruned = DatabaseBackup::prune($dir, $keep);
    if ($pruned > 0) {
        echo "Removed {$pruned} older database backup(s); keeping {$keep}.\n";
    }
    if (isset($opts['files'])) {
        $f = FilesBackup::archive("{$dir}/files-{$stamp}", PUBLIC_ROOT . '/uploads', APP_ROOT . '/config/env.php');
        printf("Files: %s (%d files, %s MB). It contains config/env.php: keep it private.\n", basename($f['file']), $f['files'], number_format($f['bytes'] / 1048576, 1));
        FilesBackup::prune($dir, 2);
    }
} catch (Throwable $e) {
    Logger::error('backup failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
