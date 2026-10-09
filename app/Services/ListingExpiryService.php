<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Cron jobs for listing lifetime (settings.listing_expiry_days, admin
 * "featured until" dates). Public pages already hide anything past
 * expires_at; these jobs make the stored status match and tell the owner.
 */
final class ListingExpiryService
{
    /** Active listings past expires_at become 'expired'. Returns how many. */
    public static function expireDue(int $batch = 200): int
    {
        $due = Database::all(
            "SELECT id, owner_id, title FROM properties WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()
             ORDER BY expires_at LIMIT " . (int) $batch
        );
        $n = 0;
        foreach ($due as $p) {
            $changed = Database::transaction(static function () use ($p): bool {
                $rows = Database::run("UPDATE properties SET status = 'expired' WHERE id = ? AND status = 'active' AND expires_at <= UTC_TIMESTAMP()", [$p['id']])->rowCount();
                if ($rows === 0) {
                    return false;
                }
                Database::run("INSERT INTO property_status_history (property_id, old_status, new_status, changed_by, actor_role, reason)
                               VALUES (?, 'active', 'expired', NULL, 'system', 'Listing period ended')", [$p['id']]);
                if ($p['owner_id'] !== null) {
                    NotificationService::toUser((int) $p['owner_id'], 'property_expired', 'Your listing has expired',
                        ($p['title'] !== '' ? $p['title'] . ' (#' . $p['id'] . ')' : 'Listing #' . $p['id']) . ' is no longer shown to visitors. Contact us to renew it.',
                        '/owner/properties', 'property', (int) $p['id']);
                }
                return true;
            });
            $n += $changed ? 1 : 0;
        }
        return $n;
    }

    /** Featured flags whose end date has passed are switched off. */
    public static function endFeatured(): int
    {
        return Database::run('UPDATE properties SET is_featured = 0, featured_until = NULL WHERE is_featured = 1 AND featured_until IS NOT NULL AND featured_until <= UTC_TIMESTAMP()')->rowCount();
    }
}
