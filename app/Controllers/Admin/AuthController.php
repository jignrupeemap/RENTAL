<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\ActivityLog;
use App\Services\AuthService;

/** /admin/login uses its own session cookie; a site login never opens /admin. */
final class AuthController
{
    public function showLogin(Request $request): Response
    {
        if (Auth::isAdmin()) {
            return Response::redirect('/admin');
        }
        return Response::html(View::render('admin/login', ['title' => 'Admin login']));
    }

    public function login(Request $request): Response
    {
        $v = new Validator($request->all(), ['identifier' => 'required|max:191', 'password' => 'required|max:128'],
            ['identifier' => 'Mobile or email']);
        if ($v->fails()) {
            return back_with_errors('/admin/login', $v->errors(), $request->all());
        }
        $result = AuthService::attemptPassword($request->str('identifier'), (string) $request->input('password'), $request, true);
        if (!$result['ok']) {
            return back_with_errors('/admin/login', ['identifier' => $result['error']], $request->all());
        }
        ActivityLog::event((int) $result['user']['id'], 'admin.login', 'user', (int) $result['user']['id'], [], $request->ip());
        return Response::redirect('/admin');
    }

    public function logout(Request $request): Response
    {
        $uid = Auth::id();
        Auth::logout();
        if ($uid) {
            ActivityLog::login($uid, 'logout', 'admin', null, $request->ip(), $request->userAgent());
        }
        Session::flash('success', 'Signed out of admin.');
        return Response::redirect('/admin/login');
    }
}
