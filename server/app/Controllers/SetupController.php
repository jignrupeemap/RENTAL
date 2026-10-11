<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Migrator;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Deploy\AdminBootstrap;
use App\Services\Deploy\Preflight;

/**
 * Installation page for hosting plans without SSH (spec 44, 65).
 *
 * Exists only while config/env.php has a SETUP_TOKEN of 24+ characters.
 * Open https://your-domain/setup, paste the token, and the page shows the
 * readiness check, a "Run migrations" button and, while the site has no
 * admin yet, a form to create the first admin. Clear SETUP_TOKEN when done
 * and every /setup address returns 404 again.
 *
 * The token is only ever sent in a POST body (never the URL). Five wrong
 * tokens per hour per IP. A correct token unlocks the page for 30 minutes
 * in this browser only; changing or clearing the token ends that at once.
 * The page is plain HTML with no database-driven layout, so it works on an
 * empty database before the first migration.
 */
final class SetupController
{
    private const UNLOCK_SECONDS = 1800;

    public function index(Request $request): Response
    {
        if (!self::enabled()) {
            return self::notFound();
        }
        if (!self::unlocked()) {
            return self::tokenPage('');
        }
        return self::dashboard($request, (string) Session::flash('setup_notice'), (array) (Session::flash('setup_log') ?? []));
    }

    public function unlock(Request $request): Response
    {
        if (!self::enabled()) {
            return self::notFound();
        }
        $error = self::checkToken($request);
        if ($error !== null) {
            return $error;
        }
        Session::regenerate();
        Session::set('setup_until', time() + self::UNLOCK_SECONDS);
        Session::set('setup_key', self::tokenKey());
        return Response::redirect('/setup');
    }

    public function runMigrations(Request $request): Response
    {
        if (!self::enabled()) {
            return self::notFound();
        }
        if (!self::unlocked()) {
            return Response::redirect('/setup');
        }
        Session::flash('setup_log', self::applyMigrations());
        Session::flash('setup_notice', 'Migrations finished.');
        return Response::redirect('/setup');
    }

    public function createAdmin(Request $request): Response
    {
        if (!self::enabled()) {
            return self::notFound();
        }
        if (!self::unlocked()) {
            return Response::redirect('/setup');
        }
        if (Preflight::pendingMigrations() !== []) {
            return self::dashboard($request, 'Run the migrations first.');
        }
        if (AdminBootstrap::adminCount() > 0) {
            // Further admins come from php bin/create-admin.php (SSH), never from here.
            return self::dashboard($request, 'An admin account already exists. Sign in at /admin/login.');
        }
        $in = [];
        foreach (['first_name', 'last_name', 'mobile', 'email'] as $k) {
            $in[$k] = $request->str($k);
        }
        $in['password'] = (string) $request->input('password', '');
        $in['password_confirmation'] = (string) $request->input('password_confirmation', '');

        $result = AdminBootstrap::create($in, false);
        if ($result['errors']) {
            unset($in['password'], $in['password_confirmation']);
            return self::dashboard($request, '', [], $result['errors'], $in);
        }
        Logger::info('first admin created from /setup', ['user_id' => $result['user_id'], 'ip' => $request->ip()]);
        Session::flash('setup_notice', 'Admin account created. Sign in at /admin/login, then clear SETUP_TOKEN in config/env.php.');
        return Response::redirect('/setup');
    }

    public function lock(Request $request): Response
    {
        Session::forget('setup_until');
        Session::forget('setup_key');
        return Response::redirect(self::enabled() ? '/setup' : '/');
    }

    // ------------------------------------------------------------------
    // Original one-step runner (Phases 1 and 13), kept for bookmarks:
    // GET shows a token form, POST with the token runs pending migrations.
    // ------------------------------------------------------------------

    public function form(Request $request): Response
    {
        if (!self::enabled()) {
            return self::notFound();
        }
        return self::legacyPage('');
    }

    public function migrate(Request $request): Response
    {
        if (!self::enabled()) {
            return self::notFound();
        }
        $error = self::checkToken($request, true);
        if ($error !== null) {
            return $error;
        }
        $lines = self::applyMigrations();
        return new Response(implode("\n", $lines) . "\n\nDone. Now clear SETUP_TOKEN in config/env.php.\n", 200,
            ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    // ------------------------------------------------------------------

    private static function enabled(): bool
    {
        return strlen((string) Config::env('SETUP_TOKEN', '')) >= 24;
    }

    private static function tokenKey(): string
    {
        return hash('sha256', 'setup|' . Config::env('SETUP_TOKEN', '') . '|' . Config::env('APP_KEY', ''));
    }

    private static function unlocked(): bool
    {
        return (int) Session::get('setup_until', 0) > time()
            && hash_equals(self::tokenKey(), (string) Session::get('setup_key', ''));
    }

    /** Null when the posted token is right; otherwise the error page to show. */
    private static function checkToken(Request $request, bool $legacy = false): ?Response
    {
        $ip = $request->ip();
        $page = static fn (string $msg, int $status) => $legacy ? self::legacyPage($msg, $status) : self::tokenPage($msg, $status);
        if (self::limited($ip)) {
            return $page('Too many attempts. Please try again in an hour.', 429);
        }
        if (!hash_equals((string) Config::env('SETUP_TOKEN', ''), $request->str('token'))) {
            self::hit($ip);
            return $page('That token is not correct.', 403);
        }
        return null;
    }

    /** @return list<string> */
    private static function applyMigrations(): array
    {
        $lines = [];
        try {
            (new Migrator(APP_ROOT . '/database/migrations'))->migrate(function (string $m) use (&$lines): void {
                $lines[] = $m;
            });
        } catch (\Throwable $e) {
            Logger::error('setup migration failed', ['error' => $e->getMessage()]);
            $lines[] = 'FAILED: ' . $e->getMessage();
        }
        return $lines;
    }

    // Wrong-token limit. Uses the rate_limits table once it exists; before the
    // first migration it falls back to a small file in storage/cache.

    private static function limited(string $ip): bool
    {
        try {
            return RateLimiter::tooMany('setup', $ip);
        } catch (\PDOException) {
            $s = self::fileLimit($ip);
            return $s['reset'] > time() && $s['hits'] >= (int) Config::get('rate_limits.setup.0', 5);
        }
    }

    private static function hit(string $ip): void
    {
        try {
            RateLimiter::hit('setup', $ip);
        } catch (\PDOException) {
            $s = self::fileLimit($ip);
            $s = $s['reset'] > time() ? ['hits' => $s['hits'] + 1, 'reset' => $s['reset']]
                                      : ['hits' => 1, 'reset' => time() + (int) Config::get('rate_limits.setup.1', 3600)];
            @file_put_contents(self::fileLimitPath($ip), (string) json_encode($s), LOCK_EX);
        }
    }

    /** @return array{hits: int, reset: int} */
    private static function fileLimit(string $ip): array
    {
        $raw = @file_get_contents(self::fileLimitPath($ip));
        $s = is_string($raw) ? json_decode($raw, true) : null;
        return ['hits' => (int) ($s['hits'] ?? 0), 'reset' => (int) ($s['reset'] ?? 0)];
    }

    private static function fileLimitPath(string $ip): string
    {
        return APP_ROOT . '/storage/cache/setup-limit-' . hash('sha256', $ip) . '.json';
    }

    // ------------------------------------------------------------------
    // HTML
    // ------------------------------------------------------------------

    private static function notFound(): Response
    {
        return Response::html('Not found', 404);
    }

    private static function tokenPage(string $error, int $status = 200): Response
    {
        $body = '<h1>Site setup</h1>'
            . '<p>Paste the <code>SETUP_TOKEN</code> from <code>config/env.php</code>. Setup then stays unlocked in this browser for 30 minutes.</p>'
            . ($error !== '' ? '<p class="err">' . e($error) . '</p>' : '')
            . '<form method="post" action="' . e(url('/setup')) . '">' . csrf_field()
            . '<label for="token">SETUP_TOKEN</label>'
            . '<input id="token" name="token" type="password" autocomplete="off" required autofocus>'
            . '<button type="submit">Continue</button></form>';
        return self::page('Site setup', $body, $status);
    }

    /**
     * @param list<string> $log
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private static function dashboard(Request $request, string $notice = '', array $log = [], array $errors = [], array $old = []): Response
    {
        $groups = Preflight::run($request->isSecure());
        $sum = Preflight::summary($groups);
        $pending = Preflight::pendingMigrations();
        $canCreateAdmin = $pending === [] && AdminBootstrap::adminCount() === 0;

        $h = '<div class="top"><h1>Site setup</h1><form method="post" action="' . e(url('/setup/lock')) . '">' . csrf_field()
            . '<button class="link" type="submit">Lock setup</button></form></div>';
        if ($notice !== '') {
            $h .= '<p class="note">' . e($notice) . '</p>';
        }
        if ($log) {
            $h .= '<pre class="log">' . e(implode("\n", $log)) . '</pre>';
        }
        $h .= sprintf('<p class="sum"><span class="b ok">%d OK</span> <span class="b warn">%d to review</span> <span class="b fail">%d to fix</span></p>',
            $sum['ok'], $sum['warn'], $sum['fail']);

        $h .= '<section><h2>1. Database tables</h2>';
        if ($pending) {
            $h .= '<p>' . count($pending) . ' migration' . (count($pending) > 1 ? 's' : '') . ' to apply. This only adds what is missing; existing data is never reset.</p>'
                . '<form method="post" action="' . e(url('/setup/run')) . '">' . csrf_field() . '<button type="submit">Run migrations</button></form>';
        } else {
            $h .= '<p class="okline">All migrations are applied.</p>';
        }
        $h .= '</section>';

        $h .= '<section><h2>2. First admin account</h2>';
        if ($pending) {
            $h .= '<p>Available after the migrations have run.</p>';
        } elseif (!$canCreateAdmin) {
            $h .= '<p class="okline">An admin account exists. Sign in at <a href="' . e(url('/admin/login')) . '">/admin/login</a>. To add more admins, run <code>php bin/create-admin.php</code> over SSH.</p>';
        } else {
            $f = static function (string $name, string $label, string $type = 'text', string $extra = '') use ($errors, $old): string {
                return '<label for="' . $name . '">' . e($label) . '</label>'
                    . '<input id="' . $name . '" name="' . $name . '" type="' . $type . '" value="' . e($old[$name] ?? '') . '" ' . $extra . '>'
                    . (isset($errors[$name]) ? '<p class="err">' . e($errors[$name]) . '</p>' : '');
            };
            $h .= '<p>This form only works while the site has no admin. The password is stored hashed, never in plain text.</p>'
                . '<form method="post" action="' . e(url('/setup/admin')) . '" autocomplete="off">' . csrf_field()
                . '<div class="two"><div>' . $f('first_name', 'First name', 'text', 'required') . '</div><div>' . $f('last_name', 'Last name') . '</div></div>'
                . $f('mobile', 'Mobile (10 digits)', 'tel', 'required inputmode="numeric"')
                . $f('email', 'Email', 'email', 'required')
                . $f('password', 'Password (at least ' . AdminBootstrap::MIN_PASSWORD . ' characters, with a letter and a number)', 'password', 'required autocomplete="new-password"')
                . $f('password_confirmation', 'Repeat password', 'password', 'required autocomplete="new-password"')
                . '<button type="submit">Create admin</button></form>';
        }
        $h .= '</section>';

        $h .= '<section><h2>3. Readiness check</h2>';
        $words = [Preflight::OK => 'OK', Preflight::WARN => 'Review', Preflight::FAIL => 'Fix'];
        foreach ($groups as $group => $checks) {
            $h .= '<h3>' . e($group) . '</h3><table>';
            foreach ($checks as [$label, $level, $detail]) {
                $h .= '<tr><td><span class="b ' . $level . '">' . $words[$level] . '</span></td><th>' . e($label) . '</th><td>' . e($detail) . '</td></tr>';
            }
            $h .= '</table>';
        }
        $h .= '</section><section><h2>4. Finish</h2><p>When everything above is OK, set <code>SETUP_TOKEN</code> to <code>\'\'</code> in <code>config/env.php</code>. This page then disappears.</p></section>';
        return self::page('Site setup', $h);
    }

    private static function legacyPage(string $error, int $status = 200): Response
    {
        $body = '<h1>Run database migrations</h1>'
            . '<p>Only new migration files are applied. Existing data is never reset.</p>'
            . ($error !== '' ? '<p class="err">' . e($error) . '</p>' : '')
            . '<form method="post" action="' . e(url('/setup/migrate')) . '">' . csrf_field()
            . '<label for="token">SETUP_TOKEN from config/env.php</label>'
            . '<input id="token" name="token" type="password" autocomplete="off" required>'
            . '<button type="submit">Run migrations</button></form>'
            . '<p class="small">The full setup page, with the readiness check and the first admin, is at <a href="' . e(url('/setup')) . '">/setup</a>.</p>';
        return self::page('Run migrations', $body, $status);
    }

    private static function page(string $title, string $body, int $status = 200): Response
    {
        $css = 'body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#1d2433;background:#f6f7f9;margin:0;padding:2rem 1rem;line-height:1.45}'
            . 'main{max-width:46rem;margin:0 auto;background:#fff;border:1px solid #e3e6eb;border-radius:12px;padding:1.5rem}'
            . 'h1{font-size:1.4rem;margin:0 0 1rem}h2{font-size:1.1rem;margin:1.75rem 0 .5rem}h3{font-size:.95rem;margin:1rem 0 .25rem;color:#5b6475}'
            . 'label{display:block;font-weight:600;font-size:.9rem;margin:.75rem 0 .25rem}'
            . 'input{width:100%;box-sizing:border-box;padding:.55rem .65rem;border:1px solid #c9ced8;border-radius:8px;font:inherit}'
            . 'button{margin-top:1rem;padding:.55rem 1.1rem;border:0;border-radius:8px;background:#1f5eff;color:#fff;font:inherit;font-weight:600;cursor:pointer}'
            . 'button.link{background:none;color:#1f5eff;padding:0;margin:0;font-weight:500}'
            . '.top{display:flex;justify-content:space-between;align-items:baseline;gap:1rem}'
            . '.two{display:grid;grid-template-columns:1fr 1fr;gap:0 1rem}@media(max-width:520px){.two{grid-template-columns:1fr}}'
            . 'table{width:100%;border-collapse:collapse;font-size:.88rem}td,th{text-align:left;vertical-align:top;padding:.35rem .4rem;border-top:1px solid #eef0f3}'
            . 'th{font-weight:600;width:10rem}td:first-child{width:4.5rem}td:last-child{color:#4a5263;overflow-wrap:anywhere}'
            . '@media(max-width:520px){tr{display:grid;grid-template-columns:auto 1fr;border-top:1px solid #eef0f3}td,th{border:0;width:auto!important}td:last-child{grid-column:1/-1;padding-top:0}}'
            . '.b{display:inline-block;font-size:.75rem;font-weight:700;padding:.1rem .45rem;border-radius:999px;white-space:nowrap}'
            . '.b.ok{background:#e3f6e8;color:#17663a}.b.warn{background:#fff3d6;color:#7a5200}.b.fail{background:#fde4e4;color:#a61b1b}'
            . '.err{color:#b02a37;margin:.3rem 0 0;font-size:.88rem}.note{background:#eaf1ff;border-radius:8px;padding:.6rem .8rem}'
            . '.okline{color:#17663a}.small{font-size:.85rem;color:#5b6475}'
            . 'pre.log{background:#f1f3f6;border-radius:8px;padding:.75rem;white-space:pre-wrap;font-size:.85rem}code{background:#f1f3f6;padding:0 .25rem;border-radius:4px}';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . e($title) . '</title><style>' . $css . '</style></head><body><main>' . $body . '</main></body></html>';
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
