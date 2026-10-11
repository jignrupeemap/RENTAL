<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\ListingRepository;

/**
 * Shortlist ❤️ (spec 24). One row per user + property (unique key), the
 * property's shortlists_count kept in the same transaction. Only listings
 * visitors can see may be added; removing always works, so a paused or
 * deleted listing can still be cleared from the viewer's list.
 */
final class ShortlistService
{
    /**
     * @param ?bool $want true = add, false = remove, null = toggle
     * @return array{0: ?bool, 1: ?string} [shortlisted now, error]
     */
    public static function set(int $userId, int $propertyId, ?bool $want, string $ip): array
    {
        return Database::transaction(static function () use ($userId, $propertyId, $want, $ip): array {
            $has = (bool) Database::value('SELECT 1 FROM shortlists WHERE user_id = ? AND property_id = ? FOR UPDATE', [$userId, $propertyId]);
            $want ??= !$has;
            if ($want === $has) {
                return [$has, null];
            }
            if ($want) {
                $p = ListingRepository::find($propertyId);
                if ($p === null) {
                    return [false, 'This listing is no longer available.'];
                }
                if ((int) ($p['owner_id'] ?? 0) === $userId) {
                    return [false, 'This is your own listing.'];
                }
                Database::run('INSERT IGNORE INTO shortlists (user_id, property_id) VALUES (?, ?)', [$userId, $propertyId]);
                Database::run('UPDATE properties SET shortlists_count = shortlists_count + 1 WHERE id = ?', [$propertyId]);
                ActivityLog::event($userId, 'shortlisted', 'property', $propertyId, [], $ip);
                return [true, null];
            }
            Database::run('DELETE FROM shortlists WHERE user_id = ? AND property_id = ?', [$userId, $propertyId]);
            Database::run('UPDATE properties SET shortlists_count = GREATEST(shortlists_count, 1) - 1 WHERE id = ?', [$propertyId]);
            ActivityLog::event($userId, 'shortlist_removed', 'property', $propertyId, [], $ip);
            return [false, null];
        });
    }
}
