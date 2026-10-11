<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\AdminRepository;

/** Activity log: every admin action with old/new values, and user events (spec 42, 59). */
final class ActivityController
{
    public function index(Request $request): Response
    {
        $tab = $request->str('tab') === 'users' ? 'users' : 'admin';
        $f = ['admin' => $request->int('admin'), 'action' => $request->str('action'), 'event' => $request->str('event'),
              'from' => $request->str('from'), 'to' => $request->str('to')];
        $page = current_page();
        $result = $tab === 'admin' ? AdminRepository::adminLog($f, $page) : AdminRepository::userEvents($f, $page);
        return Response::html(View::render('admin/activity', [
            'title' => 'Activity log', 'section' => 'activity', 'tab' => $tab, 'f' => $f,
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => AdminRepository::PER_PAGE,
            'admins' => AdminRepository::admins(),
        ]));
    }
}
