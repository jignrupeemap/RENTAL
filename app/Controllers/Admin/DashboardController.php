<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\AdminRepository;

/**
 * Admin dashboard (spec 32, 58): alerts, users, properties and engagement,
 * every number a live query. Date filter: Today, This week, This month,
 * Custom (India time). Charts and trends are on Admin → Analytics.
 */
final class DashboardController
{
    public function index(Request $request): Response
    {
        [$range, $from, $to, $label, $fromDate, $toDate] = self::range($request);
        return Response::html(View::render('admin/dashboard', [
            'title'      => 'Dashboard',
            'section'    => 'dashboard',
            'admin'      => Auth::user(),
            'range'      => $range,
            'rangeLabel' => $label,
            'fromDate'   => $fromDate,
            'toDate'     => $toDate,
            'alerts'     => AdminRepository::alerts(),
            'users'      => AdminRepository::userStats($from, $to),
            'props'      => AdminRepository::propertyStats($from, $to),
            'engage'     => AdminRepository::engagement($from, $to),
            'rentAlerts' => AdminRepository::rentAlerts(4),
            'latest'     => AdminRepository::notifications(8),
            'recentLogins' => AdminRepository::recentLogins(8),
        ]));
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
     *   [range key, UTC from, UTC to (exclusive), label, India from date, India to date]
     */
    public static function range(Request $request): array
    {
        $tz = new \DateTimeZone('Asia/Kolkata');
        $today = new \DateTimeImmutable('today', $tz);
        $range = $request->str('range', 'month');
        $start = $today->modify('first day of this month');
        $end = $today->modify('+1 day');
        $label = 'This month';
        switch ($range) {
            case 'today':
                $start = $today;
                $label = 'Today';
                break;
            case 'week':
                $start = $today->modify('monday this week');
                $label = 'This week';
                break;
            case 'custom':
                $f = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->str('from'), $tz);
                $t = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->str('to'), $tz);
                if ($f && $t && $f <= $t) {
                    $start = $f;
                    $end = $t->modify('+1 day');
                    $label = $f->format('d M Y') . ' – ' . $t->format('d M Y');
                } else {
                    $range = 'month';
                }
                break;
            default:
                $range = 'month';
        }
        $utc = new \DateTimeZone('UTC');
        return [$range, $start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s'), $label,
            $start->format('Y-m-d'), $end->modify('-1 day')->format('Y-m-d')];
    }
}
