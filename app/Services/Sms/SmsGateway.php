<?php
declare(strict_types=1);

namespace App\Services\Sms;

use App\Core\Config;
use App\Core\Logger;

/**
 * Sends OTP codes by SMS. SMS_DRIVER in config/env.php:
 *   log       development only: writes the code to storage/logs/sms-*.log
 *   msg91     MSG91 OTP/Flow API (DLT-approved template with variable ##otp##)
 *   twofactor 2Factor.in OTP API (template name in SMS_TEMPLATE_ID)
 * Indian SMS needs a DLT-registered sender and template; set those up with
 * the provider first.
 */
final class SmsGateway
{
    public static function sendOtp(string $mobile, string $code): void
    {
        $driver = (string) Config::env('SMS_DRIVER', 'log');
        match ($driver) {
            'log'       => self::log($mobile, $code),
            'msg91'     => self::msg91($mobile, $code),
            'twofactor' => self::twoFactor($mobile, $code),
            default     => throw new \RuntimeException("Unknown SMS_DRIVER {$driver}"),
        };
    }

    public static function isConfigured(): bool
    {
        $driver = (string) Config::env('SMS_DRIVER', 'log');
        return $driver === 'log' ? !Config::isProduction() : Config::env('SMS_API_KEY', '') !== '';
    }

    private static function log(string $mobile, string $code): void
    {
        if (Config::isProduction()) {
            throw new \RuntimeException('SMS_DRIVER=log is not allowed in production.');
        }
        @file_put_contents(
            APP_ROOT . '/storage/logs/sms-' . gmdate('Y-m-d') . '.log',
            sprintf("[%s UTC] OTP for %s: %s\n", gmdate('H:i:s'), $mobile, $code),
            FILE_APPEND | LOCK_EX
        );
    }

    private static function msg91(string $mobile, string $code): void
    {
        self::post('https://control.msg91.com/api/v5/flow/', [
            'template_id' => (string) Config::env('SMS_TEMPLATE_ID'),
            'short_url'   => '0',
            'recipients'  => [['mobiles' => $mobile, 'otp' => $code]],
        ], ['authkey: ' . Config::env('SMS_API_KEY'), 'Content-Type: application/json']);
    }

    private static function twoFactor(string $mobile, string $code): void
    {
        $url = sprintf(
            'https://2factor.in/API/V1/%s/SMS/%s/%s/%s',
            rawurlencode((string) Config::env('SMS_API_KEY')),
            rawurlencode(substr($mobile, 2)),
            rawurlencode($code),
            rawurlencode((string) Config::env('SMS_TEMPLATE_ID'))
        );
        self::post($url, null, []);
    }

    private static function post(string $url, ?array $json, array $headers): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ] + ($json !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($json)] : []));
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status >= 400) {
            // Provider responses never contain the code; safe to log a snippet.
            Logger::error('SMS send failed', ['status' => $status, 'error' => $error, 'body' => mb_substr((string) $body, 0, 300)]);
            throw new \RuntimeException('Could not send SMS right now.');
        }
    }
}
