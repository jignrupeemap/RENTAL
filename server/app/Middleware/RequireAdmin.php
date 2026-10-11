<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\AdminLog;

/**
 * Every /admin route except the login form. Requires the separate admin
 * session AND the admin role; a normal site login never grants access.
 */
final class RequireAdmin
{
    public function handle(Request $request): ?Response
    {
        if (Auth::isAdmin() && Auth::user()['status'] === 'active') {
            AdminLog::context($request->ip(), $request->userAgent());
            return null;
        }
        if ($request->isAjax()) {
            return Response::json(['ok' => false, 'error' => 'Admin login required.'], 401);
        }
        return Response::redirect('/admin/login');
    }
}
