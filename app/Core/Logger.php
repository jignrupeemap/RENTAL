<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Daily log files in storage/logs (outside the web root). Context keys that
 * look like secrets are masked before writing.
 */
final class Logger
{
    private const SECRET_KEYS = ['password', 'pass', 'otp', 'code', 'token', 'secret', 'key', 'csrf'];

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? json_encode(self::mask($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );
        @file_put_contents(APP_ROOT . '/storage/logs/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    private static function mask(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::mask($value);
            } elseif (is_string($key)) {
                foreach (self::SECRET_KEYS as $secret) {
                    if (stripos($key, $secret) !== false) {
                        $context[$key] = '***';
                        break;
                    }
                }
            }
        }
        return $context;
    }
}
