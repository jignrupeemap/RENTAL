<?php
declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Validator;
use App\Repositories\UserRepository;
use App\Services\NotificationService;

/**
 * Admin changes to accounts (spec 4, 34): status, profile, soft delete,
 * restore and sign-out. Admins cannot lock themselves out, and only a
 * super admin can change another admin's account.
 */
final class AdminUserService
{
    public const STATUSES = ['active', 'inactive', 'suspended', 'blocked'];

    /** @return ?string why the acting admin may not change this account, or null */
    public static function guard(array $target, int $adminId): ?string
    {
        if ((int) $target['id'] === $adminId) {
            return 'You cannot change your own account status here.';
        }
        $roles = array_column(Database::all('SELECT role FROM user_roles WHERE user_id = ?', [$target['id']]), 'role');
        if (array_intersect($roles, ['admin', 'super_admin']) && !Auth::hasRole('super_admin')) {
            return 'This is an admin account. Only a super admin can change it.';
        }
        return null;
    }

    /** @return array{0: bool, 1: string} */
    public static function setStatus(int $userId, string $status, string $reason, int $adminId): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            return [false, 'Unknown status.'];
        }
        $reason = mb_substr(trim($reason), 0, 255);
        if ($status !== 'active' && mb_strlen($reason) < 5) {
            return [false, 'Please give a short reason (at least 5 characters). The user sees it when they log in.'];
        }
        return Database::transaction(function () use ($userId, $status, $reason, $adminId): array {
            $u = Database::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$userId]);
            if ($u === null) {
                return [false, 'User not found.'];
            }
            if (($why = self::guard($u, $adminId)) !== null) {
                return [false, $why];
            }
            if ($u['status'] === $status) {
                return [true, 'No change: the account is already ' . $status . '.'];
            }
            Database::run('UPDATE users SET status = ?, status_reason = ? WHERE id = ?', [$status, $status === 'active' ? null : $reason, $userId]);
            if ($status === 'blocked') {
                // Blocked users are signed out everywhere at once (Auth also re-checks on every request).
                Auth::revokeAllSessions($userId);
            }
            AdminLog::record($adminId, 'user.status_changed', 'user', $userId,
                ['status' => $u['status']], ['status' => $status] + ($reason !== '' ? ['reason' => $reason] : []));
            if ($status !== 'blocked') {
                NotificationService::toUser($userId, 'account_status', 'Your account is now ' . $status,
                    $status === 'active' ? 'You can use every feature again.' : $reason, '/account', 'user', $userId);
            }
            return [true, 'Account set to ' . $status . '.' . ($status === 'blocked' ? ' They were signed out on every device.' : '')];
        });
    }

    /**
     * @return array{0: bool, 1: array<string, string>} [ok, field errors]
     */
    public static function updateProfile(int $userId, array $input, int $adminId): array
    {
        $v = new Validator($input, [
            'first_name' => 'required|name|max:80',
            'last_name'  => 'required|name|max:80',
            'mobile'     => 'required|mobile',
            'email'      => 'required|email',
        ]);
        $mobile = normalize_mobile((string) ($input['mobile'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if (!$v->fails()) {
            if ((bool) Database::value('SELECT 1 FROM users WHERE mobile = ? AND id <> ?', [$mobile, $userId])) {
                $v->addError('mobile', 'This mobile number is used by another account.');
            }
            if (UserRepository::emailTaken($email, $userId)) {
                $v->addError('email', 'This email is used by another account.');
            }
        }
        if ($v->fails()) {
            return [false, $v->errors()];
        }
        return Database::transaction(function () use ($userId, $input, $mobile, $email, $adminId): array {
            $u = Database::one('SELECT * FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($u === null) {
                return [false, ['first_name' => 'User not found.']];
            }
            if ((int) $u['id'] !== $adminId && ($why = self::guard($u, $adminId)) !== null) {
                return [false, ['first_name' => $why]];
            }
            $new = ['first_name' => trim((string) $input['first_name']), 'last_name' => trim((string) $input['last_name']), 'mobile' => $mobile, 'email' => $email];
            $old = array_intersect_key($u, $new);
            $diffOld = array_diff_assoc($old, $new);
            if (!$diffOld) {
                return [true, []];
            }
            // A changed mobile or email is no longer verified.
            Database::run(
                'UPDATE users SET first_name = ?, last_name = ?, mobile = ?, email = ?,
                        mobile_verified_at = IF(mobile <> ?, NULL, mobile_verified_at),
                        email_verified_at = IF(COALESCE(email, "") <> ?, NULL, email_verified_at)
                  WHERE id = ?',
                [$new['first_name'], $new['last_name'], $mobile, $email, $mobile, $email, $userId]
            );
            AdminLog::record($adminId, 'user.profile_updated', 'user', $userId, $diffOld, array_intersect_key($new, $diffOld));
            return [true, []];
        });
    }

    /** Soft delete: hidden everywhere, signed out, data kept. Mobile/email stay reserved. */
    public static function delete(int $userId, string $reason, int $adminId): array
    {
        return Database::transaction(function () use ($userId, $reason, $adminId): array {
            $u = Database::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$userId]);
            if ($u === null) {
                return [false, 'User not found.'];
            }
            if (($why = self::guard($u, $adminId)) !== null) {
                return [false, $why];
            }
            Database::run("UPDATE users SET deleted_at = UTC_TIMESTAMP(), status = 'blocked', status_reason = ? WHERE id = ?",
                [mb_substr(trim($reason) !== '' ? trim($reason) : 'Account deleted by admin', 0, 255), $userId]);
            Auth::revokeAllSessions($userId);
            AdminLog::record($adminId, 'user.deleted', 'user', $userId, ['status' => $u['status'], 'deleted' => false], ['status' => 'blocked', 'deleted' => true]);
            return [true, 'Account deleted. Their listings stay in the database but are hidden from visitors.'];
        });
    }

    public static function restore(int $userId, int $adminId): array
    {
        return Database::transaction(function () use ($userId, $adminId): array {
            $u = Database::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NOT NULL FOR UPDATE', [$userId]);
            if ($u === null) {
                return [false, 'User not found.'];
            }
            if (($why = self::guard($u, $adminId)) !== null) {
                return [false, $why];
            }
            Database::run("UPDATE users SET deleted_at = NULL, status = 'inactive', status_reason = 'Restored by admin' WHERE id = ?", [$userId]);
            AdminLog::record($adminId, 'user.restored', 'user', $userId, ['deleted' => true], ['deleted' => false, 'status' => 'inactive']);
            return [true, 'Account restored as Inactive. Set it to Active when ready.'];
        });
    }

    public static function signOutEverywhere(int $userId, int $adminId): array
    {
        $u = Database::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($u === null) {
            return [false, 'User not found.'];
        }
        if (($why = self::guard($u, $adminId)) !== null) {
            return [false, $why];
        }
        $n = Database::run('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL', [$userId])->rowCount();
        AdminLog::record($adminId, 'user.signed_out', 'user', $userId, null, ['sessions_ended' => $n]);
        return [true, $n > 0 ? 'Signed out of ' . plural($n, 'device', 'devices') . '.' : 'They were not signed in anywhere.'];
    }
}
