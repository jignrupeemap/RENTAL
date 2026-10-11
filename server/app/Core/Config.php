<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @var array<string, mixed> */
    private static array $env = [];
    /** @var array<string, mixed> */
    private static array $app = [];

    public static function load(string $dir): void
    {
        $envFile = $dir . '/env.php';
        if (!is_file($envFile)) {
            throw new \RuntimeException('Missing config/env.php. Copy config/env.example.php and fill it in.');
        }
        self::$env = require $envFile;
        self::$app = require $dir . '/app.php';

        if (strlen((string) (self::$env['APP_KEY'] ?? '')) < 32) {
            throw new \RuntimeException('APP_KEY in config/env.php must be at least 32 characters.');
        }
    }

    /** Secret / environment value from config/env.php. */
    public static function env(string $key, mixed $default = null): mixed
    {
        return self::$env[$key] ?? $default;
    }

    /** Non-secret value from config/app.php, dot notation: get('session.name'). */
    public static function get(string $path, mixed $default = null): mixed
    {
        $value = self::$app;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function isProduction(): bool
    {
        return self::env('APP_ENV') === 'production';
    }
}
