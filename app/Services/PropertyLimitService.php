<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Posting limit per owner. The limit lives in users.property_limit /
 * users.property_limit_unlimited (default copied from settings at
 * registration) and is never hard-coded. Every listing that is not
 * deleted counts, drafts included.
 */
final class PropertyLimitService
{
    public const MAX_REQUESTABLE = 1000;

    /** @return array{used:int, limit:int, unlimited:bool, remaining:?int, reached:bool} */
    public static function usage(int $userId): array
    {
        $row = Database::one(
            "SELECT u.property_limit, u.property_limit_unlimited,
                    (SELECT COUNT(*) FROM properties p WHERE p.owner_id = u.id AND p.status <> 'deleted') AS used
               FROM users u WHERE u.id = ?",
            [$userId]
        );
        $used = (int) ($row['used'] ?? 0);
        $limit = (int) ($row['property_limit'] ?? 0);
        $unlimited = (bool) ($row['property_limit_unlimited'] ?? false);
        return [
            'used'      => $used,
            'limit'     => $limit,
            'unlimited' => $unlimited,
            'remaining' => $unlimited ? null : max(0, $limit - $used),
            'reached'   => !$unlimited && $used >= $limit,
        ];
    }

    public static function pendingRequest(int $userId): ?array
    {
        return Database::one(
            "SELECT * FROM property_limit_requests WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
            [$userId]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function history(int $userId): array
    {
        return Database::all('SELECT * FROM property_limit_requests WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$userId]);
    }

    /**
     * @return string|null error message, or null on success
     */
    public static function submitRequest(array $user, ?int $requestedLimit, bool $unlimited, string $reason, string $ip): ?string
    {
        $userId = (int) $user['id'];
        if (self::pendingRequest($userId) !== null) {
            return 'You already have a request waiting for admin review.';
        }
        $usage = self::usage($userId);
        if ($usage['unlimited']) {
            return 'Your account already has unlimited listings.';
        }
        if (!$unlimited && ($requestedLimit === null || $requestedLimit <= $usage['limit'] || $requestedLimit > self::MAX_REQUESTABLE)) {
            return 'Please ask for a number higher than your current limit of ' . $usage['limit'] . ' (up to ' . self::MAX_REQUESTABLE . ').';
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            return 'Please give a short reason (10 to 1000 characters).';
        }

        $id = Database::transaction(function () use ($userId, $usage, $requestedLimit, $unlimited, $reason): int {
            return Database::insert(
                'INSERT INTO property_limit_requests (user_id, current_limit, current_count, requested_limit, requested_unlimited, reason)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $usage['limit'], $usage['used'], $unlimited ? null : $requestedLimit, $unlimited ? 1 : 0, $reason]
            );
        });

        $name = trim($user['first_name'] . ' ' . $user['last_name']);
        NotificationService::toAdmins(
            'limit_requested',
            "Listing limit request: {$name}",
            'Current limit ' . $usage['limit'] . ', requested ' . ($unlimited ? 'Unlimited' : (string) $requestedLimit),
            '/admin/limit-requests/' . $id,
            'limit_request',
            $id
        );
        ActivityLog::event($userId, 'limit.requested', 'limit_request', $id, [
            'current' => $usage['limit'], 'requested' => $unlimited ? 'unlimited' : $requestedLimit,
        ], $ip);
        return null;
    }

    /**
     * Admin decision on a limit request. On approval the admin may grant a
     * different number than asked for, or Unlimited.
     * @return array{0: bool, 1: string}
     */
    public static function review(int $requestId, bool $approve, int $adminId, ?int $limit, bool $unlimited, string $note): array
    {
        $note = mb_substr(trim($note), 0, 500);
        if (!$approve && mb_strlen($note) < 5) {
            return [false, 'Please write a short reason for the owner (at least 5 characters).'];
        }
        return Database::transaction(function () use ($requestId, $approve, $adminId, $limit, $unlimited, $note): array {
            $r = Database::one('SELECT * FROM property_limit_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($r === null) {
                return [false, 'Request not found.'];
            }
            if ($r['status'] !== 'pending') {
                return [false, 'This request was already ' . $r['status'] . '.'];
            }
            if ((int) $r['user_id'] === $adminId) {
                return [false, 'You cannot decide your own limit request. Another admin has to review it.'];
            }
            if (($err = Admin\AdminUserService::guard(['id' => (int) $r['user_id']], $adminId)) !== null) {
                return [false, $err];
            }
            if ($approve) {
                $u = Database::one('SELECT id, property_limit, property_limit_unlimited FROM users WHERE id = ? FOR UPDATE', [$r['user_id']]);
                if (!$unlimited && ($limit === null || $limit < 1 || $limit > self::MAX_REQUESTABLE)) {
                    return [false, 'Please enter a limit between 1 and ' . self::MAX_REQUESTABLE . ', or choose Unlimited.'];
                }
                if (!$unlimited && $limit <= (int) $u['property_limit'] && !$u['property_limit_unlimited']) {
                    return [false, 'The new limit must be higher than the current limit of ' . (int) $u['property_limit'] . '.'];
                }
                self::applyLimit($u, $unlimited ? (int) $u['property_limit'] : (int) $limit, $unlimited, $adminId);
                Database::run("UPDATE property_limit_requests SET status = 'approved', approved_limit = ?, approved_unlimited = ?, admin_note = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?",
                    [$unlimited ? null : $limit, $unlimited ? 1 : 0, $note !== '' ? $note : null, $adminId, $requestId]);
                $granted = $unlimited ? 'Unlimited' : (string) $limit;
                NotificationService::toUser((int) $r['user_id'], 'limit_approved', 'Listing limit increased to ' . $granted,
                    $note !== '' ? $note : 'You can now post more properties.', '/owner/limit', 'limit_request', $requestId);
                Admin\AdminLog::record($adminId, 'limit.approved', 'limit_request', $requestId, ['status' => 'pending'], ['status' => 'approved', 'granted' => $granted]);
                return [true, 'Approved. The new limit is ' . $granted . '.'];
            }
            Database::run("UPDATE property_limit_requests SET status = 'rejected', admin_note = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?",
                [$note, $adminId, $requestId]);
            NotificationService::toUser((int) $r['user_id'], 'limit_rejected', 'Listing limit request not approved', $note, '/owner/limit', 'limit_request', $requestId);
            Admin\AdminLog::record($adminId, 'limit.rejected', 'limit_request', $requestId, ['status' => 'pending'], ['status' => 'rejected', 'note' => $note]);
            return [true, 'Rejected. The owner can see your note.'];
        });
    }

    /**
     * Admin sets a user's limit directly (Users page). Lowering it never
     * removes listings; the owner just cannot start new ones until under it.
     * @return ?string error, or null on success
     */
    public static function setLimit(int $userId, int $limit, bool $unlimited, int $adminId): ?string
    {
        if (!$unlimited && ($limit < 0 || $limit > self::MAX_REQUESTABLE)) {
            return 'Please enter a limit between 0 and ' . self::MAX_REQUESTABLE . '.';
        }
        return Database::transaction(function () use ($userId, $limit, $unlimited, $adminId): ?string {
            $u = Database::one('SELECT id, property_limit, property_limit_unlimited FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($u === null) {
                return 'User not found.';
            }
            // Same rule as status changes: not your own account, and admin accounts only by a super admin.
            if (($err = Admin\AdminUserService::guard($u, $adminId)) !== null) {
                return $err;
            }
            if (self::applyLimit($u, $limit, $unlimited, $adminId)) {
                NotificationService::toUser($userId, 'limit_changed', 'Your listing limit is now ' . ($unlimited ? 'Unlimited' : (string) $limit), null, '/owner/limit', 'user', $userId);
                // A waiting request is answered by this change.
                Database::run("UPDATE property_limit_requests SET status = 'approved', approved_limit = ?, approved_unlimited = ?, admin_note = 'Limit changed from the user page', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                                WHERE user_id = ? AND status = 'pending'", [$unlimited ? null : $limit, $unlimited ? 1 : 0, $adminId, $userId]);
            }
            return null;
        });
    }

    private static function applyLimit(array $u, int $limit, bool $unlimited, int $adminId): bool
    {
        $old = ['limit' => (int) $u['property_limit'], 'unlimited' => (bool) $u['property_limit_unlimited']];
        $new = ['limit' => $limit, 'unlimited' => $unlimited];
        if ($old === $new) {
            return false;
        }
        Database::run('UPDATE users SET property_limit = ?, property_limit_unlimited = ? WHERE id = ?', [$limit, $unlimited ? 1 : 0, $u['id']]);
        Admin\AdminLog::record($adminId, 'user.limit_changed', 'user', (int) $u['id'], $old, $new);
        return true;
    }
}
