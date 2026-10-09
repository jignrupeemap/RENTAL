<?php
declare(strict_types=1);

namespace App\Controllers\Owner;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\ProfileService;

/**
 * Name and email. Mobile is the login identity and is changed only through
 * support (it would need OTP re-verification and listing contact updates).
 */
final class ProfileController
{
    public function show(Request $request): Response
    {
        return Response::html(View::render('owner/profile', [
            'title' => 'Profile', 'section' => 'profile', 'user' => Auth::user(),
        ]));
    }

    public function update(Request $request): Response
    {
        [$errors, $emailChanged] = ProfileService::update(Auth::user(), $request->all(), $request->ip());
        if ($errors) {
            return back_with_errors('/owner/profile', $errors, $request->all());
        }
        Session::flash('success', $emailChanged ? 'Profile saved. Please confirm your new email address using the link we sent.' : 'Profile saved.');
        return Response::redirect('/owner/profile');
    }
}
