<?php
declare(strict_types=1);

/**
 * Go-live readiness check: PHP version and extensions, folder permissions,
 * config/env.php values, database, migrations, admin account, demo data
 * and cron. Read-only; safe to run any time.
 *   php bin/check.php
 * Exit code 0 = no failures, 1 = something must be fixed.
 * The same list is on /setup for hosting without SSH.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\Deploy\Preflight;

$groups = Preflight::run();
$marks = [Preflight::OK => ' OK ', Preflight::WARN => 'WARN', Preflight::FAIL => 'FAIL'];
foreach ($groups as $group => $checks) {
    echo PHP_EOL, $group, PHP_EOL;
    foreach ($checks as [$label, $level, $detail]) {
        printf("  [%s] %-20s %s\n", $marks[$level], $label, $detail);
    }
}
$n = Preflight::summary($groups);
printf("\n%d ok, %d warnings, %d to fix.\n", $n['ok'], $n['warn'], $n['fail']);
exit($n['fail'] > 0 ? 1 : 0);
