<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Settings;

final class UserRepository
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public static function findByMobile(string $mobile): ?array
    {
        return Database::one('SELECT * FROM users WHERE mobile = ? AND deleted_at IS NULL', [$mobile]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::one('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [strtolower(trim($email))]);
    }

    /** Login identifier may be an email or a mobile number. */
    public static function findByIdentifier(string $identifier): ?array
    {
        if (str_contains($identifier, '@')) {
            return self::findByEmail($identifier);
        }
        $mobile = normalize_mobile($identifier);
        return $mobile ? self::findByMobile($mobile) : null;
    }

    /** Includes soft-deleted rows so a deleted account's mobile/email cannot be silently reused. */
    public static function mobileTaken(string $mobile): bool
    {
        return (bool) Database::value('SELECT 1 FROM users WHERE mobile = ?', [$mobile]);
    }

    public static function emailTaken(string $email, ?int $exceptUserId = null): bool
    {
        return (bool) Database::value(
            'SELECT 1 FROM users WHERE email = ? AND id <> ?',
            [strtolower(trim($email)), $exceptUserId ?? 0]
        );
    }

    /** Creates a viewer account. The owner role is added on first listing (Phase 4). */
    public static function create(string $first, string $last, string $mobile, string $email, string $password): int
    {
        return Database::transaction(function () use ($first, $last, $mobile, $email, $password): int {
            $id = Database::insert(
                'INSERT INTO users (first_name, last_name, mobile, email, password_hash, password_changed_at,
                                    terms_accepted_at, property_limit, status)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, "active")',
                [$first, $last, $mobile, strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT),
                 Settings::int('default_property_limit', 3)]
            );
            Database::run('INSERT INTO user_roles (user_id, role) VALUES (?, "viewer")', [$id]);
            return $id;
        });
    }

    public static function updatePassword(int $userId, string $password): void
    {
        Database::run(
            'UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP() WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $userId]
        );
    }

    /** Upgrade old hashes transparently when PHP's default algorithm changes. */
    public static function rehashIfNeeded(int $userId, string $hash, string $password): void
    {
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
        }
    }

    public static function markMobileVerified(int $userId): void
    {
        Database::run('UPDATE users SET mobile_verified_at = COALESCE(mobile_verified_at, UTC_TIMESTAMP()) WHERE id = ?', [$userId]);
    }

    public static function markEmailVerified(int $userId): void
    {
        Database::run('UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?', [$userId]);
    }

    public static function fullName(array $user): string
    {
        return trim($user['first_name'] . ' ' . $user['last_name']);
    }
}
