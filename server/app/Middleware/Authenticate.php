<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/** Requires a logged-in user; otherwise sends them to login and back here afterwards. */
final class Authenticate
{
    public function handle(Request $request): ?Response
    {
        if (Auth::check()) {
            return null;
        }
        if ($request->isAjax()) {
            return Response::json(['ok' => false, 'login_required' => true, 'error' => 'Please log in to continue.'], 401);
        }
        $next = $request->method === 'GET' ? $request->path : '/account';
        return Response::redirect('/login?next=' . rawurlencode($next));
    }
}
