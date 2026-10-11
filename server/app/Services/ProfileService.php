<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use App\Repositories\UserRepository;

/**
 * Name and email changes, shared by the owner and viewer profile pages.
 * Mobile is the login identity and is changed only by support/admin.
 */
final class ProfileService
{
    /**
     * @return array{0: array<string,string>, 1: bool} [errors, email changed]
     */
    public static function update(array $user, array $input, string $ip): array
    {
        $v = new Validator($input, [
            'first_name' => 'required|name|max:80',
            'last_name'  => 'required|name|max:80',
            'email'      => 'required|email',
        ]);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if (!$v->fails() && UserRepository::emailTaken($email, (int) $user['id'])) {
            $v->addError('email', 'This email is already used by another account.');
        }
        if ($v->fails()) {
            return [$v->errors(), false];
        }
        $emailChanged = $email !== strtolower((string) $user['email']);
        Database::run(
            'UPDATE users SET first_name = ?, last_name = ?, email = ?, email_verified_at = IF(?, NULL, email_verified_at) WHERE id = ?',
            [trim((string) $input['first_name']), trim((string) $input['last_name']), $email, $emailChanged ? 1 : 0, $user['id']]
        );
        ActivityLog::event((int) $user['id'], 'user.profile_updated', 'user', (int) $user['id'], ['email_changed' => $emailChanged], $ip);
        if ($emailChanged) {
            AuthService::sendEmailVerification((int) $user['id']);
        }
        return [[], $emailChanged];
    }
}
