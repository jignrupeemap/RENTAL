<?php
declare(strict_types=1);

namespace App\Services\Deploy;

use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;

/**
 * Go-live readiness check (spec 44, 65). Shown on /setup and printed by
 * `php bin/check.php`. Every check is read-only and works before the
 * database has been migrated, so it can be the first thing run on a new
 * Hostinger account.
 *
 * Each result is [label, level, detail] where level is
 *   ok   - nothing to do
 *   warn - works, but should be fixed before or soon after going live
 *   fail - the site will not work properly until this is fixed
 */
final class Preflight
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** Cron writes this file on every run; older than this means cron is not set up. */
    public const CRON_STALE_SECONDS = 3600;

    /**
     * @param bool|null $https whether the current web request is HTTPS (null = CLI, not known)
     * @return array<string, list<array{0: string, 1: string, 2: string}>> group => checks
     */
    public static function run(?bool $https = null): array
    {
        $prod = Config::isProduction();
        return [
            'Server'        => self::server(),
            'Folders'       => self::folders(),
            'Configuration' => self::configuration($prod, $https),
            'Database'      => self::database($prod),
            'Scheduled jobs' => [self::cron()],
        ];
    }

    /** @param array<string, list<array>> $groups @return array{ok: int, warn: int, fail: int} */
    public static function summary(array $groups): array
    {
        $n = [self::OK => 0, self::WARN => 0, self::FAIL => 0];
        foreach ($groups as $checks) {
            foreach ($checks as $c) {
                $n[$c[1]]++;
            }
        }
        return $n;
    }

    /** Written by bin/cron.php at the end of every run. */
    public static function cronStampFile(): string
    {
        return APP_ROOT . '/storage/cache/cron.last';
    }

    // ------------------------------------------------------------------

    private static function server(): array
    {
        $out = [];
        $out[] = PHP_VERSION_ID >= 80200
            ? ['PHP version', self::OK, PHP_VERSION]
            : ['PHP version', self::FAIL, PHP_VERSION . ' - choose PHP 8.2 or newer in hPanel → Advanced → PHP Configuration'];

        // Required: the site breaks without these. Optional: one feature degrades.
        $required = [
            'pdo_mysql' => 'database access',
            'mbstring'  => 'text handling',
            'fileinfo'  => 'checking uploaded file types',
            'gd'        => 'resizing photos',
            'dom'       => 'cleaning page content',
            'json'      => 'AJAX responses',
            'openssl'   => 'secure email (SMTP over SSL) and random tokens',
        ];
        foreach ($required as $ext => $why) {
            $out[] = extension_loaded($ext)
                ? ["Extension {$ext}", self::OK, $why]
                : ["Extension {$ext}", self::FAIL, "missing - needed for {$why}. Enable it in hPanel → PHP Configuration → PHP extensions"];
        }
        $out[] = function_exists('imagewebp')
            ? ['WebP images', self::OK, 'smaller photos for modern browsers']
            : ['WebP images', self::WARN, 'GD has no WebP support; photos are served as JPEG only'];
        $out[] = extension_loaded('curl')
            ? ['Extension curl', self::OK, 'SMS OTP provider calls']
            : ['Extension curl', self::WARN, 'missing - SMS OTP cannot be sent until it is enabled'];

        $upload = self::bytes((string) ini_get('upload_max_filesize'));
        $post = self::bytes((string) ini_get('post_max_size'));
        $needMb = 50;
        try {
            $needMb = max(1, Settings::int('max_video_upload_mb', 50));
        } catch (\Throwable) {
            // settings table not there yet
        }
        $limit = min($upload, $post);
        $out[] = $limit >= $needMb * 1048576
            ? ['Upload size limit', self::OK, self::mb($limit) . ' (videos up to ' . $needMb . ' MB)']
            : ['Upload size limit', self::WARN, self::mb($limit) . ' - videos are capped at this. Raise upload_max_filesize and post_max_size to at least ' . ($needMb + 8) . 'M in hPanel → PHP Configuration'];

        $mem = self::bytes((string) ini_get('memory_limit'));
        $out[] = $mem === -1 || $mem >= 256 * 1048576
            ? ['Memory limit', self::OK, $mem === -1 ? 'unlimited' : self::mb($mem)]
            : ['Memory limit', self::WARN, self::mb($mem) . ' - large phone photos need 256M; raise memory_limit in hPanel → PHP Configuration'];
        return $out;
    }

    private static function folders(): array
    {
        $dirs = [
            'storage/logs'            => APP_ROOT . '/storage/logs',
            'storage/sessions'        => APP_ROOT . '/storage/sessions',
            'storage/cache'           => APP_ROOT . '/storage/cache',
            'storage/tmp'             => APP_ROOT . '/storage/tmp',
            'uploads/properties'      => PUBLIC_ROOT . '/uploads/properties',
            'uploads/sliders'         => PUBLIC_ROOT . '/uploads/sliders',
        ];
        $out = [];
        foreach ($dirs as $label => $dir) {
            if (!is_dir($dir)) {
                $out[] = [$label, self::FAIL, 'folder is missing - create it (permissions 755)'];
            } elseif (!is_writable($dir)) {
                $out[] = [$label, self::FAIL, 'not writable - set permissions to 755 in hPanel → File Manager'];
            } else {
                $perms = substr(sprintf('%o', (int) fileperms($dir)), -3);
                $out[] = $perms === '777'
                    ? [$label, self::WARN, 'writable but set to 777; 755 is enough and safer']
                    : [$label, self::OK, 'writable'];
            }
        }
        $out[] = is_file(PUBLIC_ROOT . '/uploads/.htaccess')
            ? ['Upload protection', self::OK, 'uploads/.htaccess stops scripts running there']
            : ['Upload protection', self::FAIL, 'uploads/.htaccess is missing - upload it from the release zip'];
        $out[] = is_file(PUBLIC_ROOT . '/.htaccess')
            ? ['Web root .htaccess', self::OK, 'clean URLs, HTTPS redirect and caching']
            : ['Web root .htaccess', self::FAIL, 'public_html/.htaccess is missing - pages other than the home page will not open'];

        // Application code and secrets must not be reachable from the web.
        $public = realpath(PUBLIC_ROOT) ?: PUBLIC_ROOT;
        $app = realpath(APP_ROOT) ?: APP_ROOT;
        if (!str_starts_with($app . '/', $public . '/')) {
            $out[] = ['Application folder', self::OK, 'outside the web root'];
        } elseif (is_file($app . '/.htaccess')) {
            $out[] = ['Application folder', self::WARN, 'inside the web root, protected only by .htaccess; the public_html + rental-app layout is safer'];
        } else {
            $out[] = ['Application folder', self::FAIL, 'inside the web root without protection - config/env.php could be downloaded'];
        }
        $backups = APP_ROOT . '/backups';
        $out[] = is_dir($backups) && is_writable($backups)
            ? ['backups', self::OK, 'writable (bin/backup.php saves here)']
            : ['backups', self::WARN, (is_dir($backups) ? 'not writable' : 'missing') . ' - bin/backup.php creates it on first run; or create it with permissions 755'];
        return $out;
    }

    private static function configuration(bool $prod, ?bool $https): array
    {
        $env = static fn (string $k): string => trim((string) Config::env($k, ''));
        $out = [];
        $out[] = $prod
            ? ['APP_ENV', self::OK, 'production']
            : ['APP_ENV', self::WARN, ($env('APP_ENV') ?: 'empty') . " - set 'production' on the live site"];
        $out[] = Config::env('APP_DEBUG') && $prod
            ? ['APP_DEBUG', self::WARN, 'true - ignored in production, but set it to false']
            : (Config::env('APP_DEBUG') ? ['APP_DEBUG', $prod ? self::WARN : self::OK, 'true (development)'] : ['APP_DEBUG', self::OK, 'false']);

        $url = $env('APP_URL');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $out[] = ['APP_URL', self::FAIL, 'not set - emails, sitemap and share links need the full site address'];
        } elseif ($prod && !str_starts_with($url, 'https://')) {
            $out[] = ['APP_URL', self::WARN, $url . ' - use https:// once SSL is active'];
        } elseif (str_ends_with($url, '/')) {
            $out[] = ['APP_URL', self::WARN, 'remove the trailing slash'];
        } else {
            $out[] = ['APP_URL', self::OK, $url];
        }

        $key = $env('APP_KEY');
        $out[] = strlen($key) >= 64 && !preg_match('/^(.)\1+$/', $key)
            ? ['APP_KEY', self::OK, 'set (' . strlen($key) . ' characters)']
            : ['APP_KEY', $prod ? self::FAIL : self::WARN, 'too short - generate 64 hex characters (see DEPLOYMENT.md, step 4)'];

        $out[] = $env('DB_PASSWORD') !== ''
            ? ['DB_PASSWORD', self::OK, 'set']
            : ['DB_PASSWORD', $prod ? self::FAIL : self::WARN, 'empty - Hostinger databases always have a password'];

        $mail = $env('MAIL_DRIVER');
        if ($mail === 'smtp') {
            $missing = array_filter(['MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM'], static fn ($k) => $env($k) === '');
            $out[] = $missing
                ? ['Email', self::FAIL, 'SMTP is on but ' . implode(', ', $missing) . ' is empty']
                : ['Email', self::OK, 'SMTP via ' . $env('MAIL_HOST') . ' as ' . $env('MAIL_FROM')];
        } else {
            $out[] = ['Email', $prod ? self::FAIL : self::WARN, "MAIL_DRIVER is '{$mail}' - password reset and alert emails are only written to a log. Set up SMTP"];
        }

        $otpOn = false;
        try {
            $otpOn = Settings::bool('auth_otp_enabled');
        } catch (\Throwable) {
        }
        $sms = $env('SMS_DRIVER');
        if ($sms === 'log' || $sms === '') {
            $out[] = ['SMS OTP', $otpOn && $prod ? self::FAIL : ($otpOn ? self::WARN : self::OK),
                $otpOn ? 'OTP is switched on but codes only go to a log file - set SMS_DRIVER and SMS_API_KEY'
                       : 'OTP is off (Admin → Settings); no SMS provider needed'];
        } else {
            $out[] = $env('SMS_API_KEY') === ''
                ? ['SMS OTP', self::FAIL, "SMS_DRIVER is '{$sms}' but SMS_API_KEY is empty"]
                : ['SMS OTP', self::OK, $sms . ($otpOn ? ' (OTP on)' : ' (OTP off in Admin → Settings)')];
        }

        $out[] = $env('GOOGLE_MAPS_BROWSER_KEY') !== ''
            ? ['Google Maps', self::OK, 'browser key set']
            : ['Google Maps', self::WARN, 'no key yet - the posting form shows latitude/longitude boxes instead of a map to click; "View on Google Maps" on listings still works'];

        $token = $env('SETUP_TOKEN');
        $out[] = $token === ''
            ? ['SETUP_TOKEN', self::OK, 'empty (setup page switched off)']
            : ['SETUP_TOKEN', self::WARN, 'set - clear it in config/env.php once setup is finished'];

        if ($https !== null) {
            $out[] = $https
                ? ['HTTPS', self::OK, 'this page was opened over HTTPS']
                : ['HTTPS', $prod ? self::WARN : self::OK, 'this page was opened over plain HTTP - turn on the free SSL in hPanel → Security → SSL'];
        }
        return $out;
    }

    private static function database(bool $prod): array
    {
        try {
            $version = (string) Database::value('SELECT VERSION()');
        } catch (\Throwable $e) {
            return [['Connection', self::FAIL, 'cannot connect - check DB_HOST, DB_NAME, DB_USER and DB_PASSWORD in config/env.php']];
        }
        $out = [['Connection', self::OK, 'MySQL/MariaDB ' . $version]];

        $pending = self::pendingMigrations();
        $out[] = $pending === []
            ? ['Migrations', self::OK, 'all applied']
            : ['Migrations', self::FAIL, count($pending) . ' not applied yet (' . implode(', ', $pending) . ') - press "Run migrations" or run php bin/migrate.php'];
        if ($pending !== [] && !self::tableExists('users')) {
            return $out; // nothing else can be checked on an empty database
        }

        $admins = (int) Database::value(
            "SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles r ON r.user_id = u.id
              WHERE r.role IN ('admin','super_admin') AND u.status = 'active' AND u.deleted_at IS NULL"
        );
        $out[] = $admins > 0
            ? ['Admin accounts', self::OK, $admins . ' active']
            : ['Admin accounts', self::FAIL, 'none yet - create the first admin'];

        $demo = (int) Database::value('SELECT (SELECT COUNT(*) FROM users WHERE is_demo = 1) + (SELECT COUNT(*) FROM properties WHERE is_demo = 1)');
        $out[] = $demo === 0
            ? ['Demo data', self::OK, 'none']
            : ['Demo data', $prod ? self::FAIL : self::OK, $demo . ' demo users/listings' . ($prod ? ' - remove them with php bin/purge-demo.php' : ' (development only)')];
        return $out;
    }

    private static function cron(): array
    {
        $file = self::cronStampFile();
        if (!is_file($file)) {
            return ['bin/cron.php', self::WARN, 'has never run - add the cron job (DEPLOYMENT.md, step 8). Emails queue up and listings never expire without it'];
        }
        $age = time() - (int) filemtime($file);
        return $age <= self::CRON_STALE_SECONDS
            ? ['bin/cron.php', self::OK, 'last ran ' . self::ago($age)]
            : ['bin/cron.php', self::WARN, 'last ran ' . self::ago($age) . ' - check the cron job in hPanel → Advanced → Cron Jobs'];
    }

    // ------------------------------------------------------------------

    /** @return list<string> migration versions not yet applied */
    public static function pendingMigrations(): array
    {
        $files = array_map(static fn ($f) => basename($f, '.sql'), glob(APP_ROOT . '/database/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        if (!self::tableExists('schema_migrations')) {
            return $files;
        }
        $applied = array_column(Database::all('SELECT version FROM schema_migrations'), 'version');
        return array_values(array_diff($files, $applied));
    }

    public static function tableExists(string $table): bool
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        ) > 0;
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private static function mb(int $bytes): string
    {
        return $bytes < 0 ? 'unlimited' : round($bytes / 1048576) . ' MB';
    }

    private static function ago(int $s): string
    {
        return match (true) {
            $s < 120 => 'just now',
            $s < 7200 => intdiv($s, 60) . ' minutes ago',
            $s < 172800 => intdiv($s, 3600) . ' hours ago',
            default => intdiv($s, 86400) . ' days ago',
        };
    }
}
