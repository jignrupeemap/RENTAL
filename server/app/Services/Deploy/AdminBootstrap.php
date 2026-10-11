<?php
declare(strict_types=1);

namespace App\Services\Deploy;

use App\Core\Database;
use App\Core\Settings;
use App\Core\Validator;

/**
 * Creates the first admin without any credential in the code (spec 43, 65).
 * Used by `php bin/create-admin.php` (SSH) and by the token-protected /setup
 * page (hosting without SSH). The web route only works while no active admin
 * exists, so it cannot be used to add a second admin later.
 */
final class AdminBootstrap
{
    public const MIN_PASSWORD = 10;

    public static function adminCount(): int
    {
        return (int) Database::value(
            "SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles r ON r.user_id = u.id
              WHERE r.role IN ('admin','super_admin') AND u.status = 'active' AND u.deleted_at IS NULL"
        );
    }

    /**
     * @param array{first_name?: string, last_name?: string, mobile?: string, email?: string, password?: string, password_confirmation?: string} $in
     * @return array{errors: array<string, string>, user_id: ?int, existing: bool}
     */
    public static function create(array $in, bool $allowExisting): array
    {
        foreach (['first_name', 'last_name', 'mobile', 'email', 'password', 'password_confirmation'] as $k) {
            $in[$k] = is_string($in[$k] ?? null) ? $in[$k] : '';
            if (!str_starts_with($k, 'password')) {
                $in[$k] = trim($in[$k]); // passwords are used exactly as typed
            }
        }
        $v = new Validator($in, [
            'first_name' => 'required|name',
            'last_name'  => 'name',
            'mobile'     => 'required|mobile',
            'email'      => 'required|email',
        ]);
        $errors = $v->errors();
        if ($errors) {
            return ['errors' => $errors, 'user_id' => null, 'existing' => false];
        }
        $mobile = (string) normalize_mobile($in['mobile']);
        $email = strtolower($in['email']);

        $existing = Database::all('SELECT id, mobile, email, deleted_at FROM users WHERE mobile = ? OR email = ?', [$mobile, $email]);
        if ($existing) {
            if (!$allowExisting || count($existing) > 1 || $existing[0]['deleted_at'] !== null) {
                return ['errors' => ['mobile' => 'This mobile number or email already belongs to an account.'], 'user_id' => null, 'existing' => true];
            }
            $userId = (int) $existing[0]['id'];
            self::grant($userId);
            return ['errors' => [], 'user_id' => $userId, 'existing' => true];
        }

        $pv = new Validator($in, ['password' => 'required|password|min:' . self::MIN_PASSWORD, 'password_confirmation' => 'required|same:password'],
            ['password' => 'Password', 'password_confirmation' => 'Repeat password']);
        if ($pv->fails()) {
            return ['errors' => $pv->errors(), 'user_id' => null, 'existing' => false];
        }

        $userId = Database::transaction(static function () use ($in, $mobile, $email): int {
            $id = Database::insert(
                "INSERT INTO users (first_name, last_name, mobile, email, password_hash, status, mobile_verified_at, email_verified_at, property_limit, property_limit_unlimited)
                 VALUES (?, ?, ?, ?, ?, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, 1)",
                [$in['first_name'], $in['last_name'], $mobile, $email, password_hash($in['password'], PASSWORD_DEFAULT), Settings::int('default_property_limit', 3)]
            );
            self::grant($id);
            return $id;
        });
        return ['errors' => [], 'user_id' => $userId, 'existing' => false];
    }

    private static function grant(int $userId): void
    {
        Database::run("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'viewer'), (?, 'owner'), (?, 'admin')", [$userId, $userId, $userId]);
    }
}
