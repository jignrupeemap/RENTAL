<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Who is logged in for this request.
 *
 * The session holds the user id and a random login token; the token's sha256
 * is in user_sessions. Every request re-checks the token (so an admin can
 * revoke a device) and the account (so a blocked or deleted user is logged
 * out immediately, not at their next login).
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;

    /** @return array<string, mixed>|null the current user row + 'roles' list */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $uid = (int) Session::get('uid', 0);
        $token = Session::get('login_token');
        if ($uid <= 0 || !is_string($token)) {
            return null;
        }

        $row = Database::one(
            'SELECT u.*, s.id AS session_row_id, GROUP_CONCAT(r.role) AS role_list
               FROM users u
               JOIN user_sessions s ON s.user_id = u.id AND s.session_hash = ? AND s.revoked_at IS NULL AND s.context = ?
          LEFT JOIN user_roles r ON r.user_id = u.id
              WHERE u.id = ? AND u.deleted_at IS NULL
           GROUP BY u.id, s.id',
            [hash('sha256', $token), Session::context(), $uid]
        );

        if ($row === null || $row['status'] === 'blocked') {
            self::forgetSession();
            return null;
        }

        // Admin sessions expire after a period of inactivity.
        if (Session::context() === 'admin') {
            $idle = (int) Config::get('session.admin_idle', 1800);
            $max = (int) Config::get('session.admin_max', 43200);
            if (time() - (int) Session::get('login_at', 0) > $max) {
                Database::run('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE id = ?', [$row['session_row_id']]);
                self::forgetSession();
                Session::flash('info', 'For safety, admin sessions end 12 hours after signing in. Please sign in again.');
                return null;
            }
            if (time() - (int) Session::get('last_activity', 0) > $idle) {
                Database::run('UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE id = ?', [$row['session_row_id']]);
                self::forgetSession();
                Session::flash('info', 'You were signed out after 30 minutes of inactivity.');
                return null;
            }
            Session::set('last_activity', time());
        }

        // Touch last_seen_at at most every 5 minutes to avoid a write per request.
        if (time() - (int) Session::get('seen_at', 0) > 300) {
            Database::run('UPDATE user_sessions SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?', [$row['session_row_id']]);
            Session::set('seen_at', time());
        }

        $row['roles'] = $row['role_list'] ? explode(',', (string) $row['role_list']) : [];
        unset($row['password_hash'], $row['role_list']);
        return self::$user = $row;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function hasRole(string $role): bool
    {
        $u = self::user();
        return $u !== null && in_array($role, $u['roles'], true);
    }

    public static function isAdmin(): bool
    {
        return Session::context() === 'admin' && (self::hasRole('admin') || self::hasRole('super_admin'));
    }

    /**
     * Restricted actions (shortlist, contact, post/edit property, change rent)
     * need an active account, and a verified mobile when OTP is switched on.
     */
    public static function canAct(): bool
    {
        $u = self::user();
        if ($u === null || $u['status'] !== 'active') {
            return false;
        }
        return !Settings::bool('auth_otp_enabled') || $u['mobile_verified_at'] !== null;
    }

    /** Start an authenticated session for $userId in the current context. */
    public static function login(int $userId, Request $request): void
    {
        Session::regenerate();
        $token = bin2hex(random_bytes(32));
        Database::run(
            'INSERT INTO user_sessions (user_id, session_hash, context, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)',
            [$userId, hash('sha256', $token), Session::context(), $request->ip(), $request->userAgent()]
        );
        Database::run(
            'UPDATE users SET last_login_at = UTC_TIMESTAMP(), login_count = login_count + 1 WHERE id = ?',
            [$userId]
        );
        Session::set('uid', $userId);
        Session::set('login_token', $token);
        Session::set('last_activity', time());
        Session::set('login_at', time());
        Session::set('seen_at', time());
        Session::forget('_csrf'); // new token after privilege change
        self::$resolved = false;
        self::$user = null;
    }

    public static function logout(): void
    {
        $token = Session::get('login_token');
        if (is_string($token)) {
            Database::run(
                'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE session_hash = ? AND revoked_at IS NULL',
                [hash('sha256', $token)]
            );
        }
        self::forgetSession();
    }

    /** Sign the user out on every device (password reset, block). */
    public static function revokeAllSessions(int $userId, ?string $exceptToken = null): void
    {
        Database::run(
            'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL AND session_hash <> ?',
            [$userId, $exceptToken ? hash('sha256', $exceptToken) : '']
        );
    }

    private static function forgetSession(): void
    {
        Session::destroy();
        self::$user = null;
        self::$resolved = true;
    }
}
