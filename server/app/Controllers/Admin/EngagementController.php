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
use App\Services\Admin\AdminLog;

/**
 * All enquiries and all booking/visit requests (spec 27, 28, 60), searchable
 * by user, property, mobile and date. Owners and viewers act on bookings
 * from their dashboards; the admin sets enquiry status here, which is how
 * "Post your requirement" enquiries (no owner) are followed up.
 */
final class EngagementController
{
    public function enquiries(Request $request): Response
    {
        $f = self::filters($request);
        $page = current_page();
        $result = AdminRepository::enquiries($f, $page);
        return Response::html(View::render('admin/enquiries', [
            'title' => 'Enquiries', 'section' => 'enquiries', 'f' => $f,
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => AdminRepository::PER_PAGE,
        ]));
    }

    public function bookings(Request $request): Response
    {
        $f = self::filters($request);
        $page = current_page();
        $result = AdminRepository::bookings($f, $page);
        return Response::html(View::render('admin/bookings', [
            'title' => 'Booking / visit requests', 'section' => 'bookings', 'f' => $f,
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => AdminRepository::PER_PAGE,
        ]));
    }

    public function updateEnquiry(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $status = $request->str('status');
        $old = Database::one('SELECT id, status FROM enquiries WHERE id = ?', [$id]);
        if ($old === null || !in_array($status, ['new', 'contacted', 'in_progress', 'closed'], true)) {
            Session::flash('error', 'Enquiry not found or status not valid.');
        } elseif ($old['status'] !== $status) {
            Database::run('UPDATE enquiries SET status = ? WHERE id = ?', [$status, $id]);
            AdminLog::record((int) Auth::id(), 'enquiry.status', 'enquiry', $id, ['status' => $old['status']], ['status' => $status]);
            Session::flash('success', 'Enquiry #' . $id . ' updated.');
        }
        $back = safe_next($request->str('back'), '/admin/enquiries');
        return Response::redirect(str_starts_with($back, '/admin/enquiries') ? $back : '/admin/enquiries');
    }

    private static function filters(Request $request): array
    {
        return ['q' => $request->str('q'), 'status' => $request->str('status'), 'from' => $request->str('from'), 'to' => $request->str('to'),
                'user' => $request->int('user'), 'owner' => $request->int('owner'), 'property' => $request->int('property'),
                'source' => $request->str('source')];
    }
}
