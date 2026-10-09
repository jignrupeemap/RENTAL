<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/** Login/register pages send already-logged-in users to their account. */
final class RedirectIfAuthenticated
{
    public function handle(Request $request): ?Response
    {
        return Auth::check() ? Response::redirect(safe_next($request->str('next'))) : null;
    }
}
