<?php
declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AnalyticsRepository;
use App\Repositories\OwnerRepository;
use App\Services\PropertyLimitService;

final class DashboardController
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $uid = (int) $user['id'];
        [$from, $to] = PropertyController::lastDays(30);
        return Response::html(View::render('owner/dashboard', [
            'title'         => 'Owner dashboard',
            'user'          => $user,
            'section'       => 'dashboard',
            'props'         => OwnerRepository::propertyCounts($uid),
            'enquiries'     => OwnerRepository::enquiryCounts($uid),
            'bookings'      => OwnerRepository::bookingCounts($uid),
            'pendingRent'   => OwnerRepository::pendingRentChanges($uid),
            'limit'         => PropertyLimitService::usage($uid),
            'limitRequest'  => PropertyLimitService::pendingRequest($uid),
            'recentEnquiries' => OwnerRepository::enquiries($uid, null, '', 1, 5)['rows'],
            'upcoming'      => OwnerRepository::bookings($uid, 'pending', 1, 5)['rows'],
            'notifications' => OwnerRepository::notifications($uid, 6),
            'unread'        => OwnerRepository::unreadNotifications($uid),
            'viewsChart'    => ['key' => 'owner-views', 'label' => 'Views of your listings · last 30 days',
                                'points' => AnalyticsRepository::points(AnalyticsRepository::ownerViews($uid, $from, $to))],
        ]));
    }

    public function markNotificationsRead(Request $request): Response
    {
        Database::run(
            "UPDATE notifications SET read_at = UTC_TIMESTAMP(), read_by = ? WHERE audience = 'user' AND user_id = ? AND read_at IS NULL",
            [Auth::id(), Auth::id()]
        );
        Session::flash('success', 'All notifications marked as read.');
        return Response::redirect('/owner');
    }
}
