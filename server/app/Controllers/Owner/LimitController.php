<?php
declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\PropertyLimitService;

final class LimitController
{
    public function index(Request $request): Response
    {
        $uid = (int) Auth::id();
        return Response::html(View::render('owner/limit', [
            'title'   => 'Property Limit',
            'section' => 'limit',
            'limit'   => PropertyLimitService::usage($uid),
            'pending' => PropertyLimitService::pendingRequest($uid),
            'history' => PropertyLimitService::history($uid),
        ]));
    }

    public function request(Request $request): Response
    {
        $unlimited = $request->str('requested') === 'unlimited';
        $number = $unlimited ? null : $request->int('requested_limit');
        $error = PropertyLimitService::submitRequest(Auth::user(), $number, $unlimited, $request->str('reason'), $request->ip());
        if ($error !== null) {
            return back_with_errors('/owner/limit', ['requested_limit' => $error], $request->all());
        }
        Session::flash('success', 'Your request has been sent to the admin team. You will be notified when it is reviewed.');
        return Response::redirect('/owner/limit');
    }
}
