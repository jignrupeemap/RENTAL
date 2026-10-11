<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\AdminRepository;
use App\Repositories\AnalyticsRepository;

/**
 * Admin analytics (spec 33, 58): totals, daily or monthly trends and
 * breakdowns for the chosen date range, all from live queries. Charts are
 * plain HTML/CSS, so the page loads no chart library. "Download CSV" gives
 * the same daily counts (numbers only, no personal data).
 */
final class AnalyticsController
{
    /** Longest range accepted (about 3 years) so one request stays cheap. */
    private const MAX_DAYS = 1100;

    public function index(Request $request): Response
    {
        [[$range, $from, $to, $label, $fromDate, $toDate], $notice] = $this->range($request);
        $daily = AnalyticsRepository::daily($from, $to);
        $charts = [];
        foreach (AnalyticsRepository::METRICS as $key => $name) {
            $charts[$key] = ['key' => $key, 'label' => $name, 'points' => AnalyticsRepository::points($daily[$key])];
        }
        return Response::html(View::render('admin/analytics', [
            'title'      => 'Analytics',
            'section'    => 'analytics',
            'admin'      => Auth::user(),
            'notice'     => $notice,
            'range'      => $range,
            'rangeLabel' => $label,
            'fromDate'   => $fromDate,
            'toDate'     => $toDate,
            'monthly'    => count($daily['users']) > AnalyticsRepository::MAX_DAILY_POINTS,
            'totals'     => AnalyticsRepository::totals(),
            'inRange'    => array_map('array_sum', $daily),
            'charts'     => $charts,
            'props'      => AdminRepository::propertyStats($from, $to),
            'mix'        => AnalyticsRepository::statusMix($from, $to),
            'cities'     => AnalyticsRepository::topCities(8),
            'topViewed'  => AnalyticsRepository::topViewed($from, $to, 10),
            'query'      => $range === 'custom' ? 'range=custom&from=' . $fromDate . '&to=' . $toDate : 'range=' . $range,
        ]));
    }

    public function export(Request $request): Response
    {
        [[, $from, $to, , $fromDate, $toDate]] = $this->range($request);
        $daily = AnalyticsRepository::daily($from, $to);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date (India)', ...array_values(AnalyticsRepository::METRICS)], ',', '"', '');
        foreach (array_keys($daily['users']) as $day) {
            fputcsv($out, [$day, ...array_map(static fn ($k) => $daily[$k][$day], array_keys(AnalyticsRepository::METRICS))], ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="analytics-' . $fromDate . '-to-' . $toDate . '.csv"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * The dashboard's range, with custom ranges capped so one page never walks years of days.
     * @return array{0: array, 1: ?string} [DashboardController::range() result, notice]
     */
    private function range(Request $request): array
    {
        $r = DashboardController::range($request);
        $days = (int) round((strtotime($r[2] . ' UTC') - strtotime($r[1] . ' UTC')) / 86400);
        if ($days > self::MAX_DAYS) {
            $month = new Request('GET', $request->path, ['range' => 'month'], [], [], []);
            return [DashboardController::range($month), 'Please choose a range of up to 3 years. Showing this month instead.'];
        }
        return [$r, null];
    }
}
