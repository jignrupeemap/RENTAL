<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Services\Sms\SmsGateway;

/**
 * 6-digit SMS OTP: valid 10 minutes, 5 wrong tries, one active code per
 * mobile+purpose, send limits per mobile and per IP. Only an HMAC of the
 * code is stored.
 */
final class OtpService
{
    private const TTL_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;

    /** @return string|null error message, or null when sent */
    public static function send(string $mobile, string $purpose, string $ip): ?string
    {
        if (RateLimiter::tooMany('otp_send_target', $mobile) || RateLimiter::tooMany('otp_send_ip', $ip)) {
            $wait = (int) ceil(max(RateLimiter::availableIn('otp_send_target', $mobile), 60) / 60);
            return "Too many codes requested. Please try again in {$wait} minute" . ($wait === 1 ? '' : 's') . '.';
        }
        RateLimiter::hit('otp_send_target', $mobile);
        RateLimiter::hit('otp_send_ip', $ip);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Database::transaction(function () use ($mobile, $purpose, $code, $ip): void {
            Database::run(
                'UPDATE otp_codes SET consumed_at = UTC_TIMESTAMP() WHERE target = ? AND purpose = ? AND consumed_at IS NULL',
                [$mobile, $purpose]
            );
            Database::run(
                'INSERT INTO otp_codes (channel, target, purpose, code_hash, expires_at, ip_address)
                 VALUES ("sms", ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ' . self::TTL_MINUTES . ' MINUTE, ?)',
                [$mobile, $purpose, self::hash($code), $ip]
            );
        });

        try {
            SmsGateway::sendOtp($mobile, $code);
        } catch (\Throwable) {
            return 'We could not send the SMS right now. Please try again shortly.';
        }
        return null;
    }

    /** True when $code is the current valid code (consumes it). */
    public static function verify(string $mobile, string $purpose, string $code): bool
    {
        $row = Database::one(
            'SELECT id, code_hash, attempts FROM otp_codes
              WHERE target = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()
              ORDER BY id DESC LIMIT 1',
            [$mobile, $purpose]
        );
        if ($row === null || (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return false;
        }
        if (!preg_match('/^\d{6}$/', $code) || !hash_equals($row['code_hash'], self::hash($code))) {
            Database::run(
                'UPDATE otp_codes SET attempts = attempts + 1,
                        consumed_at = IF(attempts >= ' . self::MAX_ATTEMPTS . ', UTC_TIMESTAMP(), NULL)
                  WHERE id = ?',
                [$row['id']]
            );
            return false;
        }
        Database::run('UPDATE otp_codes SET consumed_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
        return true;
    }

    private static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) Config::env('APP_KEY'));
    }
}
