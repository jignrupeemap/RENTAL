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
use App\Services\RentChangeService;

/**
 * Rental changes (spec 29-31):
 *   GET  /admin/rent-changes              waiting / all, or ?property= for one listing's history
 *   GET  /admin/rent-changes/{id}         the RENTAL CHANGE ALERT card
 *   POST /admin/rent-changes/{id}         decision=approve|reject (+ note)
 */
final class RentChangeController
{
    public function index(Request $request): Response
    {
        $propertyId = $request->int('property') ?: null;
        $status = $request->str('status', $propertyId ? '' : 'pending');
        $status = in_array($status, ['pending', 'approved', 'rejected', 'auto_applied', 'cancelled'], true) ? $status : '';
        $page = current_page();
        $result = AdminRepository::rentChanges($status, $propertyId, $page);
        return Response::html(View::render('admin/rent-changes/index', [
            'title'    => 'Rental changes',
            'section'  => 'rent',
            'status'   => $status,
            'property' => $propertyId ? Database::one('SELECT id, title FROM properties WHERE id = ?', [$propertyId]) : null,
            'rows'     => $result['rows'],
            'total'    => $result['total'],
            'page'     => $page,
            'perPage'  => AdminRepository::PER_PAGE,
            'pendingCount' => (int) Database::value("SELECT COUNT(*) FROM rental_change_history WHERE status = 'pending'"),
        ]));
    }

    public function show(Request $request): Response
    {
        $c = AdminRepository::rentChange((int) $request->params['id']);
        if ($c === null) {
            return Response::html(View::render('errors/404', ['title' => 'Not found']), 404);
        }
        return Response::html(View::render('admin/rent-changes/show', [
            'title'   => 'Rental change alert',
            'section' => 'rent',
            'c'       => $c,
            'waiting' => $c['status'] === 'pending' && (int) $c['pending_rent_change_id'] === (int) $c['id'],
        ]));
    }

    public function review(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $decision = $request->str('decision');
        if (!in_array($decision, ['approve', 'reject'], true)) {
            return Response::redirect('/admin/rent-changes/' . $id);
        }
        [$ok, $message] = RentChangeService::review($id, $decision === 'approve', (int) Auth::id(), $request->str('note'));
        Session::flash($ok ? 'success' : 'error', $message);
        return Response::redirect('/admin/rent-changes/' . $id);
    }
}
