<?php
declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Repositories\AnalyticsRepository;
use App\Repositories\OwnerRepository;
use App\Services\ActivityLog;
use App\Services\NotificationService;
use App\Services\PropertyLimitService;

final class PropertyController
{
    private const TABS = ['active', 'pending', 'draft', 'paused', 'attention', 'expired'];
    private const PERIODS = [30, 90, 365];

    public function index(Request $request): Response
    {
        $uid = (int) Auth::id();
        $status = in_array($request->query('status'), self::TABS, true) ? $request->query('status') : null;
        $page = current_page();
        $result = OwnerRepository::properties($uid, $status, $page);
        return Response::html(View::render('owner/properties', [
            'title'      => 'My Properties',
            'section'    => 'properties',
            'status'     => $status,
            'rows'       => $result['rows'],
            'total'      => $result['total'],
            'page'       => $page,
            'perPage'    => 20,
            'counts'     => OwnerRepository::propertyCounts($uid),
            'limit'      => PropertyLimitService::usage($uid),
            'allowPause' => Settings::bool('allow_owner_pause', true),
        ]));
    }

    /** Performance of one listing (spec 6): views, shortlists, enquiries and bookings over time, plus its history. */
    public function performance(Request $request): Response
    {
        $uid = (int) Auth::id();
        $property = OwnerRepository::property($uid, (int) $request->params['id']);
        if ($property === null) {
            return Response::html(View::render('errors/404', ['title' => 'Property not found']), 404);
        }
        $days = in_array((int) $request->query('days'), self::PERIODS, true) ? (int) $request->query('days') : 30;
        [$from, $to] = self::lastDays($days);
        $daily = AnalyticsRepository::propertyDaily((int) $property['id'], $from, $to);
        $charts = [];
        foreach (['views' => 'Views', 'shortlists' => 'Shortlists', 'enquiries' => 'Enquiries', 'bookings' => 'Visit requests'] as $k => $label) {
            $charts[$k] = ['key' => $k, 'label' => $label, 'points' => AnalyticsRepository::points($daily[$k])];
        }
        return Response::html(View::render('owner/performance', [
            'title'         => 'Performance · ' . ($property['title'] !== '' ? $property['title'] : 'Untitled draft'),
            'section'       => 'properties',
            'p'             => $property,
            'days'          => $days,
            'periods'       => self::PERIODS,
            'charts'        => $charts,
            'inRange'       => array_map('array_sum', $daily),
            'rentChanges'   => OwnerRepository::propertyRentChanges($uid, (int) $property['id']),
            'statusHistory' => OwnerRepository::propertyStatusHistory((int) $property['id']),
        ]));
    }

    /** @return array{0: string, 1: string} UTC [from, to) covering the last $days India days, today included */
    public static function lastDays(int $days): array
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Kolkata'));
        $utc = new \DateTimeZone('UTC');
        return [$today->modify('-' . ($days - 1) . ' days')->setTimezone($utc)->format('Y-m-d H:i:s'),
                $today->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /** Owner pauses an active listing, or resumes one they paused themselves. */
    public function togglePause(Request $request): Response
    {
        $uid = (int) Auth::id();
        $property = OwnerRepository::property($uid, (int) $request->params['id']);
        if ($property === null) {
            return Response::html(View::render('errors/404', ['title' => 'Property not found']), 404);
        }
        if (!Settings::bool('allow_owner_pause', true)) {
            Session::flash('error', 'Pausing listings is currently managed by the admin team.');
            return Response::redirect('/owner/properties');
        }

        if ($property['status'] === 'active') {
            $new = 'paused';
        } elseif ($property['status'] === 'paused' && OwnerRepository::lastStatusActor((int) $property['id']) === 'owner') {
            $new = 'active';
        } else {
            Session::flash('error', $property['status'] === 'paused'
                ? 'This listing was paused by the admin team. Please contact support to reactivate it.'
                : 'Only active listings can be paused.');
            return Response::redirect('/owner/properties');
        }

        Database::transaction(function () use ($property, $uid, $new): void {
            Database::run('UPDATE properties SET status = ? WHERE id = ? AND owner_id = ? AND status = ?',
                [$new, $property['id'], $uid, $property['status']]);
            Database::run(
                'INSERT INTO property_status_history (property_id, old_status, new_status, changed_by, actor_role, reason) VALUES (?, ?, ?, ?, "owner", ?)',
                [$property['id'], $property['status'], $new, $uid, $new === 'paused' ? 'Paused by owner' : 'Resumed by owner']
            );
        });
        ActivityLog::event($uid, $new === 'paused' ? 'property.paused' : 'property.resumed', 'property', (int) $property['id'], [], $request->ip());
        NotificationService::toAdmins(
            $new === 'paused' ? 'property_paused_by_owner' : 'property_resumed_by_owner',
            ($new === 'paused' ? 'Owner paused: ' : 'Owner resumed: ') . $property['title'],
            'Property #' . $property['id'],
            '/admin/properties/' . $property['id'],
            'property',
            (int) $property['id']
        );
        Session::flash('success', $new === 'paused'
            ? 'Listing paused. It is hidden from search; existing enquiries and shortlists are kept.'
            : 'Listing is live again.');
        return Response::redirect('/owner/properties');
    }
}
