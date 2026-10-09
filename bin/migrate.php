<?php
declare(strict_types=1);

/**
 * Apply pending database migrations.
 *   php bin/migrate.php
 * On Hostinger: hPanel → Advanced → SSH Access, then cd to the app folder.
 * Without SSH: open /setup and press "Run migrations" (see docs/DEPLOYMENT.md).
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Migrator;

(new Migrator(APP_ROOT . '/database/migrations'))->migrate(static function (string $m): void {
    echo $m, PHP_EOL;
});
