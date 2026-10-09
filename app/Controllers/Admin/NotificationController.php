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

/**
 * Admin notifications (spec 52). Admin rows are shared by every admin; the
 * first admin to open one marks it read.
 */
final class NotificationController
{
    public function index(Request $request): Response
    {
        $unread = $request->str('show') === 'unread';
        $page = current_page();
        $result = AdminRepository::notificationPage($unread, $page);
        return Response::html(View::render('admin/notifications', [
            'title' => 'Notifications', 'section' => 'notifications', 'unread' => $unread,
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => AdminRepository::PER_PAGE,
        ]));
    }

    /** Open one: mark read, then go to its link (site-relative admin paths only). */
    public function open(Request $request): Response
    {
        $n = Database::one("SELECT * FROM notifications WHERE id = ? AND audience = 'admin'", [(int) $request->params['id']]);
        if ($n === null) {
            return Response::redirect('/admin/notifications');
        }
        if ($n['read_at'] === null) {
            Database::run('UPDATE notifications SET read_at = UTC_TIMESTAMP(), read_by = ? WHERE id = ? AND read_at IS NULL', [Auth::id(), $n['id']]);
        }
        $link = safe_next((string) $n['link_url'], '/admin/notifications');
        return Response::redirect(str_starts_with($link, '/admin') ? $link : '/admin/notifications');
    }

    public function readAll(Request $request): Response
    {
        $n = Database::run("UPDATE notifications SET read_at = UTC_TIMESTAMP(), read_by = ? WHERE audience = 'admin' AND read_at IS NULL", [Auth::id()])->rowCount();
        Session::flash('success', $n ? plural($n, 'notification', 'notifications') . ' marked as read.' : 'Nothing unread.');
        return Response::redirect('/admin/notifications');
    }
}
