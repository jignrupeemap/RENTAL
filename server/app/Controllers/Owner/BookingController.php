<?php
declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\OwnerRepository;
use App\Services\BookingService;

final class BookingController
{
    private const TABS = ['pending', 'accepted', 'rescheduled', 'closed'];

    public function index(Request $request): Response
    {
        $uid = (int) Auth::id();
        $status = in_array($request->query('status'), self::TABS, true) ? $request->query('status') : null;
        $page = current_page();
        $result = OwnerRepository::bookings($uid, $status, $page);
        return Response::html(View::render('owner/bookings', [
            'title'   => 'Booking / Visit Requests',
            'section' => 'bookings',
            'status'  => $status,
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $page,
            'perPage' => 20,
            'counts'  => OwnerRepository::bookingCounts($uid),
            'history' => OwnerRepository::bookingHistory(array_column($result['rows'], 'id')),
        ]));
    }

    public function act(Request $request): Response
    {
        $uid = (int) Auth::id();
        $booking = OwnerRepository::booking($uid, (int) $request->params['id']);
        if ($booking === null) {
            return Response::html(View::render('errors/404', ['title' => 'Request not found']), 404);
        }
        try {
            $error = BookingService::ownerAction($booking, $uid, $request->str('action'),
                $request->str('date') ?: null, $request->str('time') ?: null, $request->str('note'));
        } catch (\RuntimeException) {
            $error = 'This request was just updated. Please check its latest status.';
        }
        $error ? Session::flash('error', $error) : Session::flash('success', 'Visit request updated. The visitor has been notified.');
        return Response::redirect('/owner/bookings' . ($request->str('tab') !== '' && in_array($request->str('tab'), self::TABS, true) ? '?status=' . $request->str('tab') : ''));
    }
}
