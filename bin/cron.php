<?php
declare(strict_types=1);

/**
 * Scheduled jobs. Hostinger: hPanel → Advanced → Cron Jobs → "Custom",
 * every 5 minutes:
 *   /usr/bin/php /home/u123456789/domains/example.com/rental-app/bin/cron.php
 * Safe to run more often; a lock file prevents overlapping runs.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Logger;
use App\Services\ListingExpiryService;
use App\Services\Mailer;
use App\Services\Media\MediaService;

$lock = fopen(APP_ROOT . '/storage/cache/cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // previous run still going
}

$jobs = [
    'email_queue' => static fn (): int => Mailer::processQueue(50),
    'expired_otps' => static fn (): int => Database::run('DELETE FROM otp_codes WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY')->rowCount(),
    'expired_rate_limits' => static fn (): int => Database::run('DELETE FROM rate_limits WHERE reset_at < UTC_TIMESTAMP()')->rowCount(),
    'old_password_resets' => static fn (): int => Database::run('DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 7 DAY')->rowCount(),
    'old_email_verifications' => static fn (): int => Database::run('DELETE FROM email_verifications WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 7 DAY')->rowCount(),
    // Photo/video files the owner deleted 30+ days ago (rows stay as history).
    'deleted_media_files' => static fn (): int => MediaService::purgeDeletedFiles(30),
    'expire_listings' => static fn (): int => ListingExpiryService::expireDue(),
    'end_featured' => static fn (): int => ListingExpiryService::endFeatured(),
    'old_revoked_sessions' => static fn (): int => Database::run('DELETE FROM user_sessions WHERE revoked_at < UTC_TIMESTAMP() - INTERVAL 30 DAY')->rowCount(),
];

foreach ($jobs as $name => $job) {
    try {
        $n = $job();
        if ($n > 0) {
            echo "{$name}: {$n}\n";
        }
    } catch (Throwable $e) {
        Logger::error("cron job {$name} failed", ['error' => $e->getMessage()]);
    }
}
// Heartbeat for the readiness check (/setup, bin/check.php).
@touch(\App\Services\Deploy\Preflight::cronStampFile());
flock($lock, LOCK_UN);
