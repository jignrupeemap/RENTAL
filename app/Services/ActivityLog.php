<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Records user events and login activity. Never pass passwords, OTPs or tokens in $meta. */
final class ActivityLog
{
    public static function event(?int $userId, string $event, ?string $entityType = null, ?int $entityId = null, array $meta = [], ?string $ip = null): void
    {
        Database::run(
            'INSERT INTO activity_logs (user_id, event, entity_type, entity_id, meta, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $event, $entityType, $entityId, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, $ip]
        );
    }

    /** @param string $event login_success|login_failed|logout|otp_sent|otp_failed|password_reset|locked_out */
    public static function login(?int $userId, string $event, string $context, ?string $identifier, string $ip, string $userAgent): void
    {
        Database::run(
            'INSERT INTO login_activity (user_id, context, event, identifier, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $context, $event, $identifier !== null ? self::maskIdentifier($identifier) : null, $ip, $userAgent]
        );
    }

    private static function maskIdentifier(string $id): string
    {
        if (str_contains($id, '@')) {
            [$local, $domain] = explode('@', $id, 2);
            return mb_substr($local, 0, 2) . '***@' . $domain;
        }
        return mask_mobile($id);
    }
}
