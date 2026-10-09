<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Fixed-window limiter stored in MySQL (works on shared hosting, no Redis).
 * Limits are named in config/app.php → rate_limits: [max hits, window seconds].
 */
final class RateLimiter
{
    /** True if the bucket is already at its limit (does not count a hit). */
    public static function tooMany(string $limit, string $key): bool
    {
        [$max] = self::limit($limit);
        $row = Database::one(
            'SELECT hits FROM rate_limits WHERE bucket = ? AND reset_at > UTC_TIMESTAMP()',
            [self::bucket($limit, $key)]
        );
        return $row !== null && (int) $row['hits'] >= $max;
    }

    /** Count one hit. Returns false when the limit is now exceeded. */
    public static function hit(string $limit, string $key): bool
    {
        [$max, $window] = self::limit($limit);
        $bucket = self::bucket($limit, $key);
        Database::run(
            'INSERT INTO rate_limits (bucket, hits, reset_at) VALUES (?, 1, UTC_TIMESTAMP() + INTERVAL ? SECOND)
             ON DUPLICATE KEY UPDATE
                hits = IF(reset_at <= UTC_TIMESTAMP(), 1, hits + 1),
                reset_at = IF(hits = 1, UTC_TIMESTAMP() + INTERVAL ? SECOND, reset_at)',
            [$bucket, $window, $window]
        );
        return (int) Database::value('SELECT hits FROM rate_limits WHERE bucket = ?', [$bucket]) <= $max;
    }

    /** Seconds until the bucket resets (for "try again in N minutes"). */
    public static function availableIn(string $limit, string $key): int
    {
        $s = Database::value(
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), reset_at) FROM rate_limits WHERE bucket = ?',
            [self::bucket($limit, $key)]
        );
        return max(0, (int) $s);
    }

    public static function clear(string $limit, string $key): void
    {
        Database::run('DELETE FROM rate_limits WHERE bucket = ?', [self::bucket($limit, $key)]);
    }

    /** @return array{0:int,1:int} */
    private static function limit(string $limit): array
    {
        $cfg = Config::get('rate_limits.' . $limit);
        if (!is_array($cfg)) {
            throw new \InvalidArgumentException("Unknown rate limit {$limit}");
        }
        return [(int) $cfg[0], (int) $cfg[1]];
    }

    private static function bucket(string $limit, string $key): string
    {
        // Hash the key so mobiles/emails are not stored in clear in this table.
        return $limit . ':' . substr(hash('sha256', strtolower($key)), 0, 40);
    }
}
