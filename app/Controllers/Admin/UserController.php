<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AdminRepository;
use App\Services\Admin\AdminUserService;
use App\Services\PropertyLimitService;

/**
 * User management (spec 4, 34, 60):
 *   GET  /admin/users                  search (name, mobile, email) + status/role filters
 *   GET  /admin/users/{id}             profile, listings, enquiries, logins, activity
 *   POST /admin/users/{id}             edit profile
 *   POST /admin/users/{id}/status      active | inactive | suspended | blocked (+ reason)
 *   POST /admin/users/{id}/limit       posting limit or Unlimited
 *   POST /admin/users/{id}/signout     end every session
 *   POST /admin/users/{id}/delete      soft delete
 *   POST /admin/users/{id}/restore     undo delete
 */
final class UserController
{
    public function index(Request $request): Response
    {
        $filters = ['q' => $request->str('q'), 'status' => $request->str('status'), 'role' => $request->str('role')];
        $page = current_page();
        $result = AdminRepository::users($filters, $page);
        return Response::html(View::render('admin/users/index', [
            'title' => 'Users', 'section' => 'users', 'filters' => $filters,
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => AdminRepository::PER_PAGE,
        ]));
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $u = AdminRepository::user($id);
        if ($u === null) {
            return Response::html(View::render('errors/404', ['title' => 'Not found']), 404);
        }
        return Response::html(View::render('admin/users/show', [
            'title'      => $u['first_name'] . ' ' . $u['last_name'],
            'section'    => 'users',
            'u'          => $u,
            'guard'      => AdminUserService::guard($u, (int) Auth::id()),
            'usage'      => PropertyLimitService::usage($id),
            'counts'     => AdminRepository::userCounts($id),
            'properties' => AdminRepository::userProperties($id),
            'logins'     => AdminRepository::userLogins($id),
            'activity'   => AdminRepository::userActivity($id),
            'adminLog'   => AdminRepository::adminLog(['entity' => 'user', 'id' => $id], 1)['rows'],
            'limitRequest' => PropertyLimitService::pendingRequest($id),
        ]));
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->params['id'];
        [$ok, $errors] = AdminUserService::updateProfile($id, $request->all(), (int) Auth::id());
        if (!$ok) {
            return back_with_errors('/admin/users/' . $id, $errors, $request->all());
        }
        Session::flash('success', 'Profile saved.');
        return Response::redirect('/admin/users/' . $id);
    }

    public function status(Request $request): Response
    {
        $id = (int) $request->params['id'];
        [$ok, $message] = AdminUserService::setStatus($id, $request->str('status'), $request->str('reason'), (int) Auth::id());
        Session::flash($ok ? 'success' : 'error', $message);
        return Response::redirect('/admin/users/' . $id);
    }

    public function limit(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $error = PropertyLimitService::setLimit($id, (int) $request->int('property_limit', -1), $request->str('unlimited') === '1', (int) Auth::id());
        Session::flash($error ? 'error' : 'success', $error ?? 'Posting limit saved.');
        return Response::redirect('/admin/users/' . $id);
    }

    public function signout(Request $request): Response
    {
        $id = (int) $request->params['id'];
        [$ok, $message] = AdminUserService::signOutEverywhere($id, (int) Auth::id());
        Session::flash($ok ? 'success' : 'error', $message);
        return Response::redirect('/admin/users/' . $id);
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->params['id'];
        [$ok, $message] = AdminUserService::delete($id, $request->str('reason'), (int) Auth::id());
        Session::flash($ok ? 'success' : 'error', $message);
        return Response::redirect('/admin/users/' . $id);
    }

    public function restore(Request $request): Response
    {
        $id = (int) $request->params['id'];
        [$ok, $message] = AdminUserService::restore($id, (int) Auth::id());
        Session::flash($ok ? 'success' : 'error', $message);
        return Response::redirect('/admin/users/' . $id);
    }
}
