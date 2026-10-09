<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/** Every POST must carry the session's CSRF token. Runs on all routes. */
final class VerifyCsrf
{
    public function handle(Request $request): ?Response
    {
        if ($request->method !== 'POST' || Csrf::verify($request)) {
            return null;
        }
        // A body bigger than post_max_size reaches PHP empty (no token, no
        // files). Say "too big" instead of "session expired".
        if ($request->contentLength() > self::postMax()) {
            $msg = 'That upload is too big for the server. Please choose smaller files or fewer at a time.';
            if ($request->isAjax()) {
                return Response::json(['ok' => false, 'errors' => [$msg]], 413);
            }
            \App\Core\Session::flash('error', $msg);
            return Response::redirect(safe_next($request->refererPath(), '/owner/properties'));
        }
        if ($request->isAjax()) {
            return Response::json(['ok' => false, 'error' => 'Your session expired. Please refresh the page.'], 419);
        }
        return Response::html(View::render('errors/419'), 419);
    }

    private static function postMax(): int
    {
        $v = trim((string) ini_get('post_max_size'));
        $n = (int) $v;
        return $n <= 0 ? PHP_INT_MAX : match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
        };
    }
}
