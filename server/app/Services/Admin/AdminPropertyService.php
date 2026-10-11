<?php
declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Core\Settings;
use App\Services\NotificationService;
use App\Services\PropertyForm;

/**
 * Admin actions on any listing (spec 14, 16, 55, 56): approve, reject,
 * request changes, pause, activate, delete (soft), restore, featured,
 * display order and ownership. Each action locks the row, checks the move
 * is allowed from the current status, writes the status history, tells
 * the owner, and records old/new values in admin_activity_logs.
 */
final class AdminPropertyService
{
    /** action => statuses it may start from */
    public const FROM = [
        'publish'  => ['draft'],
        'approve'  => ['pending', 'changes_requested', 'rejected', 'approved'],
        'reject'   => ['pending', 'changes_requested'],
        'changes'  => ['pending', 'active', 'approved', 'paused', 'rejected'],
        'pause'    => ['active', 'approved'],
        'activate' => ['paused', 'expired', 'approved'],
        'delete'   => ['draft', 'pending', 'changes_requested', 'approved', 'active', 'paused', 'rejected', 'expired'],
        'restore'  => ['deleted'],
    ];

    /** Actions that publish the listing and so need every required field. */
    private const PUBLISHING = ['publish', 'approve', 'activate'];

    /** Actions where the admin must give a reason the owner will see. */
    public const NEEDS_REASON = ['reject', 'changes'];

    public static function allowed(array $p): array
    {
        $actions = array_keys(array_filter(self::FROM, static fn (array $from): bool => in_array($p['status'], $from, true)));
        // An owner's own draft waits for the owner to submit it; only admin-created drafts are published directly.
        return self::adminCreated($p) ? $actions : array_values(array_diff($actions, ['publish']));
    }

    public static function adminCreated(array $p): bool
    {
        return $p['owner_id'] === null || (int) $p['created_by'] !== (int) $p['owner_id'];
    }

    /**
     * @return array{0: bool, 1: string} [ok, message for the admin]
     */
    public static function act(int $propertyId, string $action, int $adminId, string $reason = ''): array
    {
        if (!isset(self::FROM[$action])) {
            return [false, 'Unknown action.'];
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if (in_array($action, self::NEEDS_REASON, true) && mb_strlen($reason) < 5) {
            return [false, 'Please write a short note for the owner (at least 5 characters).'];
        }

        return Database::transaction(function () use ($propertyId, $action, $adminId, $reason): array {
            $p = Database::one('SELECT * FROM properties WHERE id = ? FOR UPDATE', [$propertyId]);
            if ($p === null) {
                return [false, 'Listing not found.'];
            }
            if (!in_array($p['status'], self::FROM[$action], true)) {
                return [false, 'This listing is ' . str_replace('_', ' ', $p['status']) . ', so it cannot be ' . self::pastTense($action) . '.'];
            }
            if ($action === 'publish' && !self::adminCreated($p)) {
                return [false, 'This is the owner\'s own draft. It can be approved after the owner submits it.'];
            }
            if (in_array($action, self::PUBLISHING, true)) {
                $missing = PropertyForm::missing($p, PropertyForm::type((int) $p['type_id']));
                if ($missing) {
                    $labels = array_merge(...array_values($missing));
                    return [false, 'This listing cannot go live yet. Missing: ' . implode(', ', array_slice($labels, 0, 6)) . (count($labels) > 6 ? '…' : '') . '.'];
                }
            }

            $old = $p['status'];
            $sets = [];
            $params = [];
            switch ($action) {
                case 'publish':
                case 'approve':
                case 'activate':
                    $new = 'active';
                    $sets = ['published_at = COALESCE(published_at, UTC_TIMESTAMP())', 'rejection_reason = NULL'];
                    if ($action !== 'activate') {
                        $sets[] = 'approved_at = UTC_TIMESTAMP()';
                        $sets[] = 'approved_by = ?';
                        $params[] = $adminId;
                    }
                    // A fresh approval or reactivation starts a new listing period.
                    $days = Settings::int('listing_expiry_days', 90);
                    $sets[] = $days > 0 ? 'expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $days . ' DAY)' : 'expires_at = NULL';
                    break;
                case 'reject':
                    $new = 'rejected';
                    $sets = ['rejection_reason = ?'];
                    $params[] = $reason;
                    break;
                case 'changes':
                    $new = 'changes_requested';
                    $sets = ['rejection_reason = ?'];
                    $params[] = $reason;
                    break;
                case 'pause':
                    $new = 'paused';
                    break;
                case 'delete':
                    $new = 'deleted';
                    $sets = ['deleted_at = UTC_TIMESTAMP()'];
                    self::cancelPendingRent($p, $adminId, 'Listing deleted');
                    break;
                case 'restore':
                    // Back to a safe, hidden state; the admin then activates it.
                    $new = $p['published_at'] !== null ? 'paused' : 'draft';
                    $sets = ['deleted_at = NULL'];
                    break;
                default:
                    return [false, 'Unknown action.'];
            }

            $sets[] = 'status = ?';
            $params[] = $new;
            $params[] = $propertyId;
            Database::run('UPDATE properties SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            Database::run(
                "INSERT INTO property_status_history (property_id, old_status, new_status, changed_by, actor_role, reason) VALUES (?, ?, ?, ?, 'admin', ?)",
                [$propertyId, $old, $new, $adminId, $reason !== '' ? $reason : self::defaultReason($action)]
            );
            AdminLog::record($adminId, 'property.' . $action, 'property', $propertyId,
                ['status' => $old], ['status' => $new] + ($reason !== '' ? ['note' => $reason] : []));
            self::notifyOwner($p, $action, $reason);
            return [true, self::message($action, $new)];
        });
    }

    /** @param ?string $until 'Y-m-d' (India date) or null for no end date */
    public static function setFeatured(int $propertyId, bool $on, ?string $until, int $adminId): bool
    {
        return Database::transaction(function () use ($propertyId, $on, $until, $adminId): bool {
            $p = Database::one("SELECT id, is_featured, featured_until FROM properties WHERE id = ? AND status <> 'deleted' FOR UPDATE", [$propertyId]);
            if ($p === null) {
                return false;
            }
            // End of the chosen India day, stored in UTC.
            $untilUtc = $on && $until !== null
                ? (new \DateTimeImmutable($until . ' 23:59:59', new \DateTimeZone('Asia/Kolkata')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
                : null;
            Database::run('UPDATE properties SET is_featured = ?, featured_until = ? WHERE id = ?', [$on ? 1 : 0, $untilUtc, $propertyId]);
            AdminLog::record($adminId, 'property.featured', 'property', $propertyId,
                ['featured' => (bool) $p['is_featured'], 'featured_until' => $p['featured_until']],
                ['featured' => $on, 'featured_until' => $untilUtc]);
            return true;
        });
    }

    /**
     * Save the display order of one purpose (Rent / PG / Lease).
     * @param list<int> $orderedIds listings in the order they should appear; they get priority 1, 2, 3...
     * @param list<int> $unrankedIds listings that go back to "no priority" (newest first after ranked ones)
     */
    public static function saveOrder(string $purpose, array $orderedIds, array $unrankedIds, int $adminId): int
    {
        return Database::transaction(function () use ($purpose, $orderedIds, $unrankedIds, $adminId): int {
            $ids = array_values(array_unique(array_map('intval', [...$orderedIds, ...$unrankedIds])));
            if (!$ids) {
                return 0;
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $current = [];
            foreach (Database::all("SELECT id, display_rank FROM properties WHERE purpose = ? AND status <> 'deleted' AND id IN ($in) FOR UPDATE", [$purpose, ...$ids]) as $r) {
                $current[(int) $r['id']] = (int) $r['display_rank'];
            }
            $changes = [];
            $rank = 0;
            foreach (array_values(array_unique(array_map('intval', $orderedIds))) as $id) {
                if (!isset($current[$id])) {
                    continue; // other purpose or deleted: ignore, never trust posted ids
                }
                $rank++;
                if ($current[$id] !== $rank) {
                    $changes[$id] = [$current[$id], $rank];
                }
            }
            foreach (array_map('intval', $unrankedIds) as $id) {
                if (isset($current[$id]) && !isset($changes[$id]) && !in_array($id, $orderedIds, true) && $current[$id] !== 1000000) {
                    $changes[$id] = [$current[$id], 1000000];
                }
            }
            foreach ($changes as $id => [, $newRank]) {
                Database::run('UPDATE properties SET display_rank = ? WHERE id = ?', [$newRank, $id]);
            }
            if ($changes) {
                AdminLog::record($adminId, 'property.order_saved', 'purpose', null,
                    ['purpose' => $purpose] + array_combine(array_map(static fn ($i) => '#' . $i, array_keys($changes)), array_map(static fn ($c) => self::rankLabel($c[0]), $changes)),
                    ['purpose' => $purpose] + array_combine(array_map(static fn ($i) => '#' . $i, array_keys($changes)), array_map(static fn ($c) => self::rankLabel($c[1]), $changes)));
            }
            return count($changes);
        });
    }

    /**
     * Move a listing to another account, or to admin/system ownership (null).
     * @return ?string error, or null on success
     */
    public static function assignOwner(int $propertyId, ?int $userId, int $adminId): ?string
    {
        return Database::transaction(function () use ($propertyId, $userId, $adminId): ?string {
            $p = Database::one("SELECT id, owner_id, title FROM properties WHERE id = ? AND status <> 'deleted' FOR UPDATE", [$propertyId]);
            if ($p === null) {
                return 'Listing not found.';
            }
            if ($userId !== null) {
                $u = Database::one('SELECT id, status FROM users WHERE id = ? AND deleted_at IS NULL', [$userId]);
                if ($u === null) {
                    return 'That account was not found.';
                }
            }
            $oldOwner = $p['owner_id'] !== null ? (int) $p['owner_id'] : null;
            if ($oldOwner === $userId) {
                return null;
            }
            Database::run('UPDATE properties SET owner_id = ? WHERE id = ?', [$userId, $propertyId]);
            // Enquiries, visit requests and waiting rent changes follow the listing, so the
            // previous owner no longer sees the visitors' details or answers for the new owner.
            Database::run('UPDATE enquiries SET owner_id = ? WHERE property_id = ?', [$userId, $propertyId]);
            Database::run('UPDATE bookings SET owner_id = ? WHERE property_id = ?', [$userId, $propertyId]);
            Database::run("UPDATE rental_change_history SET owner_id = ? WHERE property_id = ? AND status = 'pending'", [$userId, $propertyId]);
            if ($userId !== null) {
                Database::run("INSERT IGNORE INTO user_roles (user_id, role, granted_by) VALUES (?, 'owner', ?)", [$userId, $adminId]);
                NotificationService::toUser($userId, 'property_assigned', 'A listing was added to your account',
                    '#' . $propertyId . ' ' . $p['title'], '/owner/properties', 'property', $propertyId);
            }
            AdminLog::record($adminId, 'property.owner_changed', 'property', $propertyId,
                ['owner' => self::ownerLabel($oldOwner)], ['owner' => self::ownerLabel($userId)]);
            return null;
        });
    }

    public static function setNote(int $propertyId, string $note, int $adminId): void
    {
        $old = Database::value('SELECT admin_note FROM properties WHERE id = ?', [$propertyId]);
        $note = mb_substr(trim($note), 0, 500);
        if ((string) $old === $note) {
            return;
        }
        Database::run('UPDATE properties SET admin_note = ? WHERE id = ?', [$note !== '' ? $note : null, $propertyId]);
        AdminLog::record($adminId, 'property.note', 'property', $propertyId, ['admin_note' => $old], ['admin_note' => $note]);
    }

    // ------------------------------------------------------------------

    private static function cancelPendingRent(array $p, int $adminId, string $why): void
    {
        if (!$p['pending_rent_change_id']) {
            return;
        }
        Database::run("UPDATE rental_change_history SET status = 'cancelled', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), review_note = ? WHERE id = ? AND status = 'pending'",
            [$adminId, $why, $p['pending_rent_change_id']]);
        Database::run('UPDATE properties SET pending_rent_change_id = NULL WHERE id = ?', [$p['id']]);
    }

    private static function notifyOwner(array $p, string $action, string $reason): void
    {
        if ($p['owner_id'] === null) {
            return;
        }
        $title = mb_substr((string) $p['title'], 0, 90) ?: 'Property #' . $p['id'];
        [$type, $head] = match ($action) {
            'approve', 'publish' => ['property_approved', 'Your listing is live: '],
            'activate' => ['property_activated', 'Your listing is live again: '],
            'reject'   => ['property_rejected', 'Listing not approved: '],
            'changes'  => ['property_changes_requested', 'Changes needed: '],
            'pause'    => ['property_paused', 'Listing paused by admin: '],
            'delete'   => ['property_deleted', 'Listing removed: '],
            'restore'  => ['property_restored', 'Listing restored: '],
        };
        NotificationService::toUser((int) $p['owner_id'], $type, $head . $title, $reason !== '' ? $reason : null,
            $action === 'delete' ? '/owner/properties' : '/owner/properties/' . $p['id'] . '/edit', 'property', (int) $p['id']);
    }

    private static function pastTense(string $action): string
    {
        return ['publish' => 'published', 'approve' => 'approved', 'reject' => 'rejected', 'changes' => 'sent back for changes', 'pause' => 'paused',
            'activate' => 'activated', 'delete' => 'deleted', 'restore' => 'restored'][$action] ?? $action;
    }

    private static function defaultReason(string $action): string
    {
        return ['publish' => 'Published by admin', 'approve' => 'Approved by admin', 'activate' => 'Activated by admin', 'pause' => 'Paused by admin',
            'delete' => 'Deleted by admin', 'restore' => 'Restored by admin'][$action] ?? 'Changed by admin';
    }

    private static function message(string $action, string $new): string
    {
        return match ($action) {
            'publish'  => 'Published. The listing is now live.',
            'approve'  => 'Approved. The listing is now live.',
            'activate' => 'Activated. The listing is live.',
            'reject'   => 'Rejected. The owner can see your note.',
            'changes'  => 'Sent back to the owner with your note.',
            'pause'    => 'Paused. It is hidden from search; enquiries and shortlists are kept.',
            'delete'   => 'Deleted. It is hidden everywhere but kept in the database; you can restore it.',
            'restore'  => 'Restored as ' . str_replace('_', ' ', $new) . '.',
        };
    }

    private static function rankLabel(int $rank): string
    {
        return $rank >= 1000000 ? 'none' : (string) $rank;
    }

    private static function ownerLabel(?int $userId): string
    {
        if ($userId === null) {
            return 'Admin / system';
        }
        $u = Database::one('SELECT first_name, last_name FROM users WHERE id = ?', [$userId]);
        return '#' . $userId . ($u ? ' ' . trim($u['first_name'] . ' ' . $u['last_name']) : '');
    }
}
