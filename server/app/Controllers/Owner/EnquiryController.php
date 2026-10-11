<?php
declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\OwnerRepository;
use App\Services\ActivityLog;

final class EnquiryController
{
    public const STATUSES = ['new', 'contacted', 'in_progress', 'closed'];

    public function index(Request $request): Response
    {
        $uid = (int) Auth::id();
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        $page = current_page();
        $result = OwnerRepository::enquiries($uid, $status, $search, $page);
        return Response::html(View::render('owner/enquiries', [
            'title'   => 'Enquiries',
            'section' => 'enquiries',
            'status'  => $status,
            'search'  => $search,
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $page,
            'perPage' => 20,
            'counts'  => OwnerRepository::enquiryCounts($uid),
        ]));
    }

    public function updateStatus(Request $request): Response
    {
        $uid = (int) Auth::id();
        $enquiry = OwnerRepository::enquiry($uid, (int) $request->params['id']);
        if ($enquiry === null) {
            return Response::html(View::render('errors/404', ['title' => 'Enquiry not found']), 404);
        }
        $status = $request->str('status');
        if (!in_array($status, self::STATUSES, true)) {
            Session::flash('error', 'Please choose a valid status.');
            return Response::redirect('/owner/enquiries');
        }
        $note = mb_substr($request->str('owner_note'), 0, 500);
        Database::run('UPDATE enquiries SET status = ?, owner_note = ? WHERE id = ? AND owner_id = ?',
            [$status, $note !== '' ? $note : null, $enquiry['id'], $uid]);
        if ($status !== $enquiry['status']) {
            ActivityLog::event($uid, 'enquiry.status_changed', 'enquiry', (int) $enquiry['id'], ['from' => $enquiry['status'], 'to' => $status], $request->ip());
        }
        Session::flash('success', 'Enquiry updated.');
        $back = safe_next($request->str('back'), '/owner/enquiries');
        return Response::redirect(str_starts_with($back, '/owner/enquiries') ? $back : '/owner/enquiries');
    }
}
