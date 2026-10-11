<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;

/**
 * For restricted actions (shortlist, contact, booking, posting, editing,
 * rent changes). Inactive/suspended accounts and, when OTP is on,
 * unverified mobiles are stopped here. Use after Authenticate.
 */
final class RequireActiveAccount
{
    public function handle(Request $request): ?Response
    {
        if (Auth::canAct()) {
            return null;
        }
        $user = Auth::user();
        $needsOtp = $user !== null && $user['status'] === 'active'
            && Settings::bool('auth_otp_enabled') && $user['mobile_verified_at'] === null;
        $message = $needsOtp
            ? 'Please verify your mobile number to continue.'
            : 'Your account is ' . ($user['status'] ?? 'not active') . '. Please contact support to restore access.';

        if ($request->isAjax()) {
            return Response::json(['ok' => false, 'error' => $message], 403);
        }
        Session::flash('error', $message);
        return Response::redirect($needsOtp ? '/verify-mobile' : '/account');
    }
}
