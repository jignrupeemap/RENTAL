<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Core\Settings;
use App\Repositories\UserRepository;

/**
 * Login, registration and password-reset rules shared by the site and admin
 * login screens. Controllers only translate requests/responses.
 */
final class AuthService
{
    /**
     * Rate-limit key for a login/reset identifier. Mobiles are normalised
     * ("+91 98765-43210" and "9876543210" are the same account), so typing
     * the number differently cannot start a fresh set of attempts.
     */
    public static function identifierKey(string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        if (!str_contains($identifier, '@') && ($mobile = normalize_mobile($identifier)) !== null) {
            return 'm:' . $mobile;
        }
        return 'e:' . $identifier;
    }

    /** A real hash at today's default cost, so unknown accounts take as long to check as real ones. */
    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
    }

    /**
     * @return array{ok:bool, error?:string, user?:array}
     */
    public static function attemptPassword(string $identifier, string $password, Request $request, bool $adminOnly = false): array
    {
        $ip = $request->ip();
        $context = Session::context();
        $accountKey = self::identifierKey($identifier);

        if (RateLimiter::tooMany('login_ip', $ip) || RateLimiter::tooMany('login_account', $accountKey)) {
            $mins = (int) ceil(max(RateLimiter::availableIn('login_account', $accountKey), RateLimiter::availableIn('login_ip', $ip), 60) / 60);
            ActivityLog::login(null, 'locked_out', $context, $identifier, $ip, $request->userAgent());
            return ['ok' => false, 'error' => "Too many login attempts. Please try again in {$mins} minute" . ($mins === 1 ? '' : 's') . '.'];
        }

        $user = UserRepository::findByIdentifier($identifier);
        $hash = $user['password_hash'] ?? null;
        $valid = password_verify($password, $hash ?? self::dummyHash()) && $hash !== null;
        $isAdmin = $user && self::hasAnyRole((int) $user['id'], ['admin', 'super_admin']);

        if (!$valid || ($adminOnly && !$isAdmin)) {
            RateLimiter::hit('login_ip', $ip);
            RateLimiter::hit('login_account', $accountKey);
            ActivityLog::login($user ? (int) $user['id'] : null, 'login_failed', $context, $identifier, $ip, $request->userAgent());
            return ['ok' => false, 'error' => 'Incorrect mobile/email or password.'];
        }

        if ($user['status'] === 'blocked') {
            ActivityLog::login((int) $user['id'], 'login_failed', $context, $identifier, $ip, $request->userAgent());
            return ['ok' => false, 'error' => 'This account has been blocked. Please contact support.'];
        }
        if ($adminOnly && $user['status'] !== 'active') {
            return ['ok' => false, 'error' => 'This admin account is not active.'];
        }

        RateLimiter::clear('login_account', $accountKey);
        UserRepository::rehashIfNeeded((int) $user['id'], $hash, $password);
        self::completeLogin($user, $request);
        return ['ok' => true, 'user' => $user];
    }

    /** Called after a password or OTP check succeeds. */
    public static function completeLogin(array $user, Request $request): void
    {
        Auth::login((int) $user['id'], $request);
        ActivityLog::login((int) $user['id'], 'login_success', Session::context(), null, $request->ip(), $request->userAgent());
    }

    public static function register(array $data, Request $request): int
    {
        $mobile = normalize_mobile($data['mobile']);
        $userId = UserRepository::create(trim($data['first_name']), trim($data['last_name']), $mobile, $data['email'], $data['password']);

        ActivityLog::event($userId, 'user.registered', 'user', $userId, [], $request->ip());
        if (Settings::bool('notify_admin_new_registration', true)) {
            NotificationService::toAdmins(
                'user_registered',
                'New registration: ' . trim($data['first_name'] . ' ' . $data['last_name']),
                format_mobile($mobile),
                '/admin/users/' . $userId,
                'user',
                $userId
            );
        }
        self::sendEmailVerification($userId);
        self::completeLogin(UserRepository::find($userId), $request);
        return $userId;
    }

    public static function sendEmailVerification(int $userId): void
    {
        $user = UserRepository::find($userId);
        if (!$user || !$user['email'] || $user['email_verified_at']) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        Database::run(
            'INSERT INTO email_verifications (user_id, email, token_hash, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL 2 DAY)',
            [$userId, $user['email'], hash('sha256', $token)]
        );
        Mailer::sendTemplate($user['email'], UserRepository::fullName($user), 'Verify your email address', 'verify-email', [
            'name' => $user['first_name'],
            'link' => url('/verify-email?token=' . $token),
        ], true);
    }

    public static function verifyEmailToken(string $token): ?int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = Database::one(
            'SELECT v.id, v.user_id FROM email_verifications v JOIN users u ON u.id = v.user_id AND u.email = v.email
              WHERE v.token_hash = ? AND v.used_at IS NULL AND v.expires_at > UTC_TIMESTAMP()',
            [hash('sha256', $token)]
        );
        if ($row === null) {
            return null;
        }
        Database::run('UPDATE email_verifications SET used_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
        UserRepository::markEmailVerified((int) $row['user_id']);
        return (int) $row['user_id'];
    }

    /** Always succeeds from the caller's view so the form never reveals which emails exist. */
    public static function sendPasswordResetLink(string $email, Request $request): void
    {
        $user = UserRepository::findByEmail($email);
        if ($user === null || $user['status'] === 'blocked') {
            return;
        }
        $token = bin2hex(random_bytes(32));
        Database::transaction(function () use ($user, $token, $request): void {
            Database::run('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$user['id']]);
            Database::run(
                'INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 60 MINUTE, ?)',
                [$user['id'], hash('sha256', $token), $request->ip()]
            );
        });
        Mailer::sendTemplate($user['email'], UserRepository::fullName($user), 'Reset your password', 'reset-password', [
            'name' => $user['first_name'],
            'link' => url('/reset-password?token=' . $token),
        ], true);
    }

    public static function findValidResetToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return Database::one(
            'SELECT r.id, r.user_id FROM password_resets r JOIN users u ON u.id = r.user_id AND u.deleted_at IS NULL
              WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > UTC_TIMESTAMP()',
            [hash('sha256', $token)]
        );
    }

    /** Sets the new password, burns the token and signs the user out everywhere. */
    public static function resetPassword(int $userId, string $password, Request $request, ?int $resetRowId = null): void
    {
        Database::transaction(function () use ($userId, $password, $resetRowId): void {
            UserRepository::updatePassword($userId, $password);
            Database::run('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$userId]);
            Auth::revokeAllSessions($userId);
        });
        ActivityLog::login($userId, 'password_reset', Session::context(), null, $request->ip(), $request->userAgent());
        ActivityLog::event($userId, 'user.password_reset', 'user', $userId, ['via' => $resetRowId ? 'email' : 'otp'], $request->ip());
    }

    private static function hasAnyRole(int $userId, array $roles): bool
    {
        $in = implode(',', array_fill(0, count($roles), '?'));
        return (bool) Database::value("SELECT 1 FROM user_roles WHERE user_id = ? AND role IN ({$in})", [$userId, ...$roles]);
    }
}
