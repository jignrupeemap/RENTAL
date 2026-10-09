<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** In-app notifications (database first; email is added per event where configured). */
final class NotificationService
{
    public static function toAdmins(string $type, string $title, ?string $body = null, ?string $link = null, ?string $entityType = null, ?int $entityId = null): void
    {
        Database::run(
            'INSERT INTO notifications (audience, user_id, type, title, body, link_url, entity_type, entity_id)
             VALUES ("admin", NULL, ?, ?, ?, ?, ?, ?)',
            [$type, $title, $body, $link, $entityType, $entityId]
        );
    }

    public static function toUser(int $userId, string $type, string $title, ?string $body = null, ?string $link = null, ?string $entityType = null, ?int $entityId = null): void
    {
        Database::run(
            'INSERT INTO notifications (audience, user_id, type, title, body, link_url, entity_type, entity_id)
             VALUES ("user", ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $type, $title, $body, $link, $entityType, $entityId]
        );
    }
}
