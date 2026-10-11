<?php
declare(strict_types=1);

namespace App\Core;

/**
 * One CSRF token per session. Forms include Csrf::field(); fetch() calls
 * send it in the X-CSRF-Token header (read from <meta name="csrf-token">).
 */
final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            Session::set('_csrf', $token);
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function verify(Request $request): bool
    {
        $expected = Session::get('_csrf');
        $given = $request->input('_token') ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        return is_string($expected) && is_string($given) && hash_equals($expected, $given);
    }
}
