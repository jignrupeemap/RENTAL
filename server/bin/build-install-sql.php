<?php
declare(strict_types=1);

/**
 * Regenerates database/install.sql (one-file import for phpMyAdmin) from
 * database/migrations/. Run after adding a migration; bin/package.php runs
 * it automatically.
 *   php bin/build-install-sql.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/Services/Deploy/InstallSql.php';

$sql = App\Services\Deploy\InstallSql::build(APP_ROOT . '/database/migrations');
file_put_contents(APP_ROOT . '/database/install.sql', $sql);
printf("database/install.sql written (%s KB).\n", number_format(strlen($sql) / 1024, 1));
