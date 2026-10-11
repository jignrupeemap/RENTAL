<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Detail-page view counter (spec 32 "Property views"). No cookie and no
 * per-visitor record: a total on the property plus one row per day for the
 * admin dashboard date ranges, keyed by India date (UTC + 5:30, no DST).
 * Search-engine bots, link previews and the owner looking at their own
 * listing are not counted.
 */
final class PropertyViewService
{
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegram|preview|lighthouse|headless|curl\/|wget|python-requests/i';

    public static function shouldCount(string $userAgent, string $method, ?int $viewerId, ?int $ownerId): bool
    {
        return $method === 'GET'
            && $userAgent !== ''
            && !preg_match(self::BOTS, $userAgent)
            && ($viewerId === null || $viewerId !== $ownerId);
    }

    public static function record(int $propertyId): void
    {
        Database::run('UPDATE properties SET views_count = views_count + 1 WHERE id = ?', [$propertyId]);
        Database::run(
            'INSERT INTO property_view_daily (property_id, view_date, views) VALUES (?, DATE(UTC_TIMESTAMP() + INTERVAL 330 MINUTE), 1)
             ON DUPLICATE KEY UPDATE views = views + 1',
            [$propertyId]
        );
    }
}
