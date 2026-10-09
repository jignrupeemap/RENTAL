<?php
declare(strict_types=1);

namespace App\Core;

/**
 * PHP sessions with secure cookie settings.
 *
 * - Two separate cookies: the public site and /admin never share a session.
 * - Lazy: a visitor who never logs in or submits a form gets no session file.
 * - Files are kept in storage/sessions (outside the web root) so Hostinger's
 *   shared /tmp garbage collection cannot log people out early.
 */
final class Session
{
    private static string $context = 'site';
    private static bool $secure = false;

    public static function configure(Request $request): void
    {
        self::$context = ($request->path === '/admin' || str_starts_with($request->path, '/admin/')) ? 'admin' : 'site';
        self::$secure = $request->isSecure();

        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) {
            return;
        }
        $lifetime = (int) Config::get('session.lifetime', 604800);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        if (PHP_VERSION_ID < 80400) { // deprecated (and already strong by default) in 8.4+
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '5');
        }
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '200');
        $path = APP_ROOT . '/storage/sessions';
        if (is_dir($path) && is_writable($path)) {
            session_save_path($path);
        }
        session_name(self::$context === 'admin'
            ? (string) Config::get('session.admin_name', 'rw_admin')
            : (string) Config::get('session.name', 'rw_sess'));
        session_set_cookie_params([
            // Admin cookie dies with the browser; site cookie lasts `lifetime`.
            'lifetime' => self::$context === 'admin' ? 0 : $lifetime,
            'path'     => '/',
            'secure'   => self::$secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Resume an existing session only; new ones start on first write.
        if (isset($_COOKIE[session_name()])) {
            self::start();
        }
    }

    public static function context(): string
    {
        return self::$context;
    }

    public static function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public static function start(): void
    {
        if (self::isStarted() || PHP_SAPI === 'cli') {
            return;
        }
        session_start();
        // Age flash data: what was flashed last request is readable now, then gone.
        $_SESSION['_flash_now'] = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
        // Refresh the expiry of the persistent site cookie on activity.
        if (self::$context === 'site' && isset($_COOKIE[session_name()]) && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), (string) session_id(), [
                'expires'  => time() + (int) Config::get('session.lifetime', 604800),
                'path'     => $params['path'],
                'secure'   => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::isStarted() ? ($_SESSION[$key] ?? $default) : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        if (self::isStarted()) {
            unset($_SESSION[$key]);
        }
    }

    /** New session id, old one destroyed. Call after login and privilege changes. */
    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        if (!self::isStarted()) {
            return;
        }
        $_SESSION = [];
        $params = session_get_cookie_params();
        if (!headers_sent()) {
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => $params['path'],
                'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    /** One-request messages: flash('success', 'Saved') now, flash('success') on the next request. */
    public static function flash(string $key, mixed $value = null): mixed
    {
        if ($value !== null) {
            self::start();
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        return self::peekFlash($key);
    }

    /** Read a value flashed by the previous request. */
    public static function peekFlash(string $key): mixed
    {
        return self::isStarted() ? ($_SESSION['_flash_now'][$key] ?? null) : null;
    }
}
