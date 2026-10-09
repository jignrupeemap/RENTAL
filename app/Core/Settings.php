<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Admin-editable settings from the `settings` table, loaded once per request.
 * Use for anything the admin may change (property limit, rent approval mode...).
 * Never hard-code those values in PHP.
 */
final class Settings
{
    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::$cache ??= self::loadAll();
        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return (bool) self::get($key, $default);
    }

    public static function set(string $key, mixed $value, ?int $userId): void
    {
        $row = Database::one('SELECT value_type FROM settings WHERE setting_key = ?', [$key]);
        if ($row === null) {
            throw new \InvalidArgumentException("Unknown setting {$key}");
        }
        $stored = match ($row['value_type']) {
            'bool'  => $value ? '1' : '0',
            'int'   => (string) (int) $value,
            'json'  => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
        Database::run('UPDATE settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?', [$stored, $userId, $key]);
        self::$cache = null;
    }

    /** @return array<string, mixed> */
    private static function loadAll(): array
    {
        $out = [];
        foreach (Database::all('SELECT setting_key, setting_value, value_type FROM settings') as $row) {
            $out[$row['setting_key']] = match ($row['value_type']) {
                'int'  => (int) $row['setting_value'],
                'bool' => $row['setting_value'] === '1',
                'json' => json_decode((string) $row['setting_value'], true),
                default => $row['setting_value'],
            };
        }
        return $out;
    }
}
