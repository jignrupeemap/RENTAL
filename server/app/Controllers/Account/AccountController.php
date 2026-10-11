<?php
declare(strict_types=1);

namespace App\Controllers\Account;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\ViewerRepository;
use App\Services\ActivityLog;
use App\Services\BookingService;
use App\Services\ProfileService;

/**
 * Viewer dashboard (spec 23-25, 28): My Dashboard, Shortlisted Properties,
 * Contacted Properties, Booking / Visit Requests, Profile. Every query is
 * scoped to Auth::id(); nothing here takes a user id from the request.
 */
final class AccountController
{
    public function index(Request $request): Response
    {
        $uid = (int) Auth::id();
        return Response::html(View::render('account/index', [
            'title'         => 'My Dashboard',
            'section'       => 'dashboard',
            'user'          => Auth::user(),
            'otpEnabled'    => Settings::bool('auth_otp_enabled'),
            'counts'        => ViewerRepository::counts($uid),
            'shortlist'     => ViewerRepository::shortlist($uid, 1, 3)['rows'],
            'enquiries'     => ViewerRepository::enquiries($uid, 1, 3)['rows'],
            'visits'        => ViewerRepository::bookings($uid, 'open', 1, 3)['rows'],
            'notifications' => ViewerRepository::notifications($uid),
        ]));
    }

    public function shortlist(Request $request): Response
    {
        $page = current_page();
        $result = ViewerRepository::shortlist((int) Auth::id(), $page);
        return Response::html(View::render('account/shortlist', [
            'title' => 'Shortlisted Properties', 'section' => 'shortlist',
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => ViewerRepository::PER_PAGE,
        ]));
    }

    public function contacted(Request $request): Response
    {
        $page = current_page();
        $result = ViewerRepository::enquiries((int) Auth::id(), $page);
        return Response::html(View::render('account/contacted', [
            'title' => 'Contacted Properties', 'section' => 'contacted',
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => ViewerRepository::PER_PAGE,
        ]));
    }

    public function bookings(Request $request): Response
    {
        $uid = (int) Auth::id();
        $status = in_array($request->str('status'), ['open', 'past'], true) ? $request->str('status') : null;
        $page = current_page();
        $result = ViewerRepository::bookings($uid, $status, $page);
        $all = ViewerRepository::bookings($uid, null, 1, 1)['total'];
        $open = ViewerRepository::bookings($uid, 'open', 1, 1)['total'];
        return Response::html(View::render('account/bookings', [
            'title' => 'Booking / Visit Requests', 'section' => 'bookings', 'status' => $status,
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => ViewerRepository::PER_PAGE,
            'tabs' => ['' => ['All', $all], 'open' => ['Open', $open], 'past' => ['Past', $all - $open]],
            'history' => ViewerRepository::bookingHistory(array_map(static fn ($b) => (int) $b['id'], $result['rows'])),
        ]));
    }

    public function bookingAction(Request $request): Response
    {
        $error = BookingService::viewerAction((int) $request->params['id'], (int) Auth::id(), $request->str('action'));
        Session::flash($error ? 'error' : 'success', $error ?? ($request->str('action') === 'accept_time' ? 'New time accepted. The owner has been told.' : 'Visit request cancelled.'));
        $tab = in_array($request->str('tab'), ['open', 'past'], true) ? '?status=' . $request->str('tab') : '';
        return Response::redirect('/account/bookings' . $tab);
    }

    public function profile(Request $request): Response
    {
        return Response::html(View::render('account/profile', [
            'title' => 'Profile', 'section' => 'profile', 'user' => Auth::user(),
        ]));
    }

    public function updateProfile(Request $request): Response
    {
        [$errors, $emailChanged] = ProfileService::update(Auth::user(), $request->all(), $request->ip());
        if ($errors) {
            return back_with_errors('/account/profile', $errors, $request->all());
        }
        Session::flash('success', $emailChanged ? 'Profile saved. Please confirm your new email address using the link we sent.' : 'Profile saved.');
        return Response::redirect('/account/profile');
    }

    public function markNotificationsRead(Request $request): Response
    {
        Database::run(
            "UPDATE notifications SET read_at = UTC_TIMESTAMP(), read_by = ? WHERE audience = 'user' AND user_id = ? AND read_at IS NULL",
            [Auth::id(), Auth::id()]
        );
        Session::flash('success', 'All notifications marked as read.');
        return Response::redirect('/account');
    }

    public function changePassword(Request $request): Response
    {
        $user = Auth::user();
        $hash = (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        $v = new Validator($request->all(), [
            'current_password'      => 'required',
            'password'              => 'required|password',
            'password_confirmation' => 'required|same:password',
        ], ['current_password' => 'Current password', 'password_confirmation' => 'Confirm password']);
        if (!$v->fails() && ($hash === '' || !password_verify((string) $request->input('current_password'), $hash))) {
            $v->addError('current_password', 'Your current password is incorrect.');
        }
        if ($v->fails()) {
            return back_with_errors('/account/profile#password', $v->errors());
        }

        Database::run('UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP() WHERE id = ?',
            [password_hash((string) $request->input('password'), PASSWORD_DEFAULT), $user['id']]);
        // Sign out other devices, keep this one.
        Auth::revokeAllSessions((int) $user['id'], (string) Session::get('login_token'));
        ActivityLog::event((int) $user['id'], 'user.password_changed', 'user', (int) $user['id'], [], $request->ip());
        Session::flash('success', 'Password changed. Other devices have been signed out.');
        return Response::redirect('/account/profile');
    }
}
