<?php
declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;

/**
 * Every admin write is recorded here with the values before and after
 * (spec 42: "Admin Rahul changed property status Old: Active New: Paused").
 * Never pass passwords, OTPs or tokens.
 */
final class AdminLog
{
    private static string $ip = '';
    private static string $userAgent = '';

    /** Set once per request by the admin middleware so services need not pass them around. */
    public static function context(string $ip, string $userAgent): void
    {
        self::$ip = $ip;
        self::$userAgent = mb_substr($userAgent, 0, 255);
    }

    public static function record(int $adminId, string $action, ?string $entityType, ?int $entityId, ?array $old = null, ?array $new = null): void
    {
        Database::run(
            'INSERT INTO admin_activity_logs (admin_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$adminId, $action, $entityType, $entityId,
             $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
             $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
             self::$ip !== '' ? self::$ip : null, self::$userAgent !== '' ? self::$userAgent : null]
        );
    }

    /** Readable sentence for the activity log page. */
    public static function describe(array $row): string
    {
        $old = $row['old_values'] ? (json_decode((string) $row['old_values'], true) ?: []) : [];
        $new = $row['new_values'] ? (json_decode((string) $row['new_values'], true) ?: []) : [];
        $parts = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $k) {
            $o = self::show($old[$k] ?? null);
            $n = self::show($new[$k] ?? null);
            if (array_key_exists($k, $old) && array_key_exists($k, $new)) {
                $parts[] = str_replace('_', ' ', (string) $k) . ': ' . $o . ' → ' . $n;
            } else {
                $parts[] = str_replace('_', ' ', (string) $k) . ': ' . (array_key_exists($k, $new) ? $n : $o);
            }
        }
        return implode(' · ', $parts);
    }

    private static function show(mixed $v): string
    {
        return match (true) {
            $v === null => '—',
            is_bool($v) => $v ? 'yes' : 'no',
            is_array($v) => implode(', ', array_map(static fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $v)),
            default => (string) $v,
        };
    }
}
