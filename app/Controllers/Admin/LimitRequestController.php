<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AdminRepository;
use App\Services\PropertyLimitService;

/**
 * Listing-limit requests (spec 5, 53):
 *   GET  /admin/limit-requests          waiting / all
 *   GET  /admin/limit-requests/{id}     same list, the request highlighted
 *   POST /admin/limit-requests/{id}     decision=approve (limit or unlimited) | reject (+ note)
 */
final class LimitRequestController
{
    public function index(Request $request): Response
    {
        $highlight = isset($request->params['id']) ? (int) $request->params['id'] : null;
        $status = $request->str('status', $highlight ? '' : 'pending');
        $status = in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : '';
        $page = current_page();
        $result = AdminRepository::limitRequests($status !== '' ? $status : null, $page);
        if ($highlight && !in_array($highlight, array_map('intval', array_column($result['rows'], 'id')), true)) {
            $one = Database::one(
                "SELECT r.*, u.first_name, u.last_name, u.mobile, u.email, u.property_limit, u.property_limit_unlimited,
                        (SELECT COUNT(*) FROM properties p WHERE p.owner_id = u.id AND p.status <> 'deleted') AS used_now
                   FROM property_limit_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ?",
                [$highlight]
            );
            if ($one === null) {
                return Response::html(View::render('errors/404', ['title' => 'Not found']), 404);
            }
            array_unshift($result['rows'], $one);
        }
        return Response::html(View::render('admin/limits/index', [
            'title'     => 'Limit requests',
            'section'   => 'limits',
            'status'    => $status,
            'highlight' => $highlight,
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $page,
            'perPage'   => AdminRepository::PER_PAGE,
            'pendingCount' => (int) Database::value("SELECT COUNT(*) FROM property_limit_requests WHERE status = 'pending'"),
            'max'       => PropertyLimitService::MAX_REQUESTABLE,
        ]));
    }

    public function review(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $decision = $request->str('decision');
        if (!in_array($decision, ['approve', 'reject'], true)) {
            return Response::redirect('/admin/limit-requests');
        }
        [$ok, $message] = PropertyLimitService::review($id, $decision === 'approve', (int) Auth::id(),
            $request->int('approved_limit'), $request->str('unlimited') === '1', $request->str('note'));
        Session::flash($ok ? 'success' : 'error', $message);
        return Response::redirect($ok ? '/admin/limit-requests' : '/admin/limit-requests/' . $id);
    }
}
