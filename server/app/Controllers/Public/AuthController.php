<?php
declare(strict_types=1);

namespace App\Controllers\Public;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\ActivityLog;
use App\Services\AuthService;
use App\Services\OtpService;

final class AuthController
{
    // ------------------------------------------------------------------
    // Login / Register (one page, two tabs)
    // ------------------------------------------------------------------

    public function showLogin(Request $request): Response
    {
        return $this->authPage($request, 'login');
    }

    public function showRegister(Request $request): Response
    {
        return $this->authPage($request, 'register');
    }

    private function authPage(Request $request, string $tab): Response
    {
        return Response::html(View::render('auth/login', [
            'title'      => $tab === 'register' ? 'Create your account' : 'Login',
            'tab'        => $tab,
            'next'       => safe_next($request->str('next'), ''),
            'otpEnabled' => Settings::bool('auth_otp_enabled'),
        ]));
    }

    public function login(Request $request): Response
    {
        $next = safe_next($request->str('next'));
        $back = '/login' . ($request->str('next') !== '' ? '?next=' . rawurlencode($next) : '');
        $v = new Validator($request->all(), ['identifier' => 'required|max:191', 'password' => 'required|max:128'],
            ['identifier' => 'Mobile or email']);
        if ($v->fails()) {
            return back_with_errors($back, $v->errors(), $request->all());
        }

        $result = AuthService::attemptPassword($request->str('identifier'), (string) $request->input('password'), $request);
        if (!$result['ok']) {
            return back_with_errors($back, ['identifier' => $result['error']], $request->all());
        }
        return $this->afterLogin($result['user'], $next);
    }

    public function register(Request $request): Response
    {
        $data = $request->all();
        // Honeypot: real people never fill the hidden "website" field.
        if (($data['website'] ?? '') !== '') {
            return Response::redirect('/register');
        }
        if (!RateLimiter::hit('register_ip', $request->ip())) {
            return back_with_errors('/register', ['first_name' => 'Too many sign-ups from this network. Please try again later.'], $data);
        }

        $v = new Validator($data, [
            'first_name'            => 'required|name|max:80',
            'last_name'             => 'required|name|max:80',
            'mobile'                => 'required|mobile',
            'email'                 => 'required|email',
            'password'              => 'required|password',
            'password_confirmation' => 'required|same:password',
            'terms'                 => 'accepted',
        ], ['terms' => 'Terms of Use and Privacy Policy', 'password_confirmation' => 'Confirm password']);

        if (!$v->fails()) {
            if (UserRepository::mobileTaken(normalize_mobile($data['mobile']))) {
                $v->addError('mobile', 'This mobile number is already registered. Please log in instead.');
            }
            if (UserRepository::emailTaken($data['email'])) {
                $v->addError('email', 'This email is already registered. Please log in instead.');
            }
        }
        $next = safe_next($request->str('next'));
        if ($v->fails()) {
            return back_with_errors('/register' . ($request->str('next') !== '' ? '?next=' . rawurlencode($next) : ''), $v->errors(), $data);
        }

        try {
            AuthService::register($data, $request);
        } catch (\PDOException $e) {
            // Two sign-ups racing for the same mobile/email: the UNIQUE key wins.
            if ($e->getCode() === '23000') {
                return back_with_errors('/register', ['mobile' => 'This mobile number or email is already registered.'], $data);
            }
            throw $e;
        }

        if (Settings::bool('auth_otp_enabled')) {
            OtpService::send(normalize_mobile($data['mobile']), 'verify_mobile', $request->ip());
            Session::flash('success', 'Account created. Enter the code we sent to your mobile to finish.');
            Session::set('after_verify', $next);
            return Response::redirect('/verify-mobile');
        }
        Session::flash('success', 'Welcome! Your account is ready. We sent a verification link to your email.');
        return Response::redirect($next);
    }

    public function logout(Request $request): Response
    {
        $uid = Auth::id();
        Auth::logout();
        if ($uid) {
            ActivityLog::login($uid, 'logout', 'site', null, $request->ip(), $request->userAgent());
        }
        Session::flash('success', 'You have been logged out.');
        return Response::redirect('/');
    }

    // ------------------------------------------------------------------
    // Login with OTP (only when Settings → auth_otp_enabled)
    // ------------------------------------------------------------------

    public function sendLoginOtp(Request $request): Response
    {
        if (!Settings::bool('auth_otp_enabled')) {
            return Response::redirect('/login');
        }
        $mobile = normalize_mobile($request->str('mobile'));
        if ($mobile === null) {
            return back_with_errors('/login?otp=1', ['mobile' => 'Please enter a valid 10-digit Indian mobile number.'], $request->all());
        }
        $user = UserRepository::findByMobile($mobile);
        // Same response whether or not the account exists; codes only go to registered numbers.
        if ($user !== null && $user['status'] !== 'blocked') {
            $error = OtpService::send($mobile, 'login', $request->ip());
            if ($error !== null) {
                return back_with_errors('/login?otp=1', ['mobile' => $error], $request->all());
            }
            ActivityLog::login((int) $user['id'], 'otp_sent', 'site', $mobile, $request->ip(), $request->userAgent());
        }
        Session::set('otp_login_mobile', $mobile);
        Session::set('otp_login_next', safe_next($request->str('next')));
        Session::flash('success', 'If this number is registered, we have sent a 6-digit code.');
        return Response::redirect('/login/otp');
    }

    public function showLoginOtp(Request $request): Response
    {
        $mobile = Session::get('otp_login_mobile');
        if (!is_string($mobile)) {
            return Response::redirect('/login?otp=1');
        }
        return Response::html(View::render('auth/otp', [
            'title' => 'Enter login code', 'heading' => 'Enter the code',
            'mobile' => $mobile, 'action' => '/login/otp', 'resendAction' => null,
        ]));
    }

    public function verifyLoginOtp(Request $request): Response
    {
        $mobile = Session::get('otp_login_mobile');
        if (!is_string($mobile)) {
            return Response::redirect('/login?otp=1');
        }
        if (!RateLimiter::hit('otp_verify', $mobile)) {
            return back_with_errors('/login/otp', ['code' => 'Too many wrong codes. Please request a new code later.']);
        }
        $user = UserRepository::findByMobile($mobile);
        if ($user === null || !OtpService::verify($mobile, 'login', $request->str('code'))) {
            ActivityLog::login($user ? (int) $user['id'] : null, 'otp_failed', 'site', $mobile, $request->ip(), $request->userAgent());
            return back_with_errors('/login/otp', ['code' => 'That code is incorrect or has expired.']);
        }
        if ($user['status'] === 'blocked') {
            return back_with_errors('/login', ['identifier' => 'This account has been blocked. Please contact support.']);
        }
        $next = (string) Session::get('otp_login_next', '/account');
        RateLimiter::clear('otp_verify', $mobile);
        UserRepository::markMobileVerified((int) $user['id']); // receiving the SMS proves ownership
        AuthService::completeLogin($user, $request);
        return $this->afterLogin($user, $next);
    }

    // ------------------------------------------------------------------
    // Mobile verification (OTP)
    // ------------------------------------------------------------------

    public function showVerifyMobile(Request $request): Response
    {
        $user = Auth::user();
        if ($user['mobile_verified_at'] !== null) {
            return Response::redirect('/account');
        }
        return Response::html(View::render('auth/otp', [
            'title' => 'Verify your mobile', 'heading' => 'Verify your mobile number',
            'mobile' => $user['mobile'], 'action' => '/verify-mobile', 'resendAction' => '/verify-mobile/send',
        ]));
    }

    public function sendVerifyMobile(Request $request): Response
    {
        $user = Auth::user();
        $error = OtpService::send($user['mobile'], 'verify_mobile', $request->ip());
        $error ? Session::flash('error', $error) : Session::flash('success', 'We sent a new 6-digit code to ' . format_mobile($user['mobile']) . '.');
        return Response::redirect('/verify-mobile');
    }

    public function verifyMobile(Request $request): Response
    {
        $user = Auth::user();
        if (!RateLimiter::hit('otp_verify', $user['mobile'])) {
            return back_with_errors('/verify-mobile', ['code' => 'Too many wrong codes. Please request a new code later.']);
        }
        if (!OtpService::verify($user['mobile'], 'verify_mobile', $request->str('code'))) {
            return back_with_errors('/verify-mobile', ['code' => 'That code is incorrect or has expired.']);
        }
        RateLimiter::clear('otp_verify', $user['mobile']);
        UserRepository::markMobileVerified((int) $user['id']);
        ActivityLog::event((int) $user['id'], 'user.mobile_verified', 'user', (int) $user['id'], [], $request->ip());
        Session::flash('success', 'Your mobile number is verified.');
        $next = (string) Session::get('after_verify', '/account');
        Session::forget('after_verify');
        return Response::redirect(safe_next($next));
    }

    // ------------------------------------------------------------------
    // Email verification
    // ------------------------------------------------------------------

    public function verifyEmail(Request $request): Response
    {
        $userId = AuthService::verifyEmailToken((string) $request->query('token', ''));
        if ($userId === null) {
            Session::flash('error', 'This verification link is invalid or has expired. Log in to request a new one.');
        } else {
            ActivityLog::event($userId, 'user.email_verified', 'user', $userId, [], $request->ip());
            Session::flash('success', 'Thank you, your email address is verified.');
        }
        return Response::redirect(Auth::check() ? '/account' : '/login');
    }

    public function resendEmailVerification(Request $request): Response
    {
        $user = Auth::user();
        if (RateLimiter::hit('password_reset', 'verify:' . $user['id'])) {
            AuthService::sendEmailVerification((int) $user['id']);
            Session::flash('success', 'We sent a new verification link to ' . $user['email'] . '.');
        } else {
            Session::flash('error', 'Please wait a while before requesting another link.');
        }
        return Response::redirect('/account');
    }

    // ------------------------------------------------------------------
    // Forgot / reset password
    // ------------------------------------------------------------------

    public function showForgot(Request $request): Response
    {
        return Response::html(View::render('auth/forgot', [
            'title' => 'Forgot password', 'otpEnabled' => Settings::bool('auth_otp_enabled'),
        ]));
    }

    public function forgot(Request $request): Response
    {
        $identifier = $request->str('identifier');
        if ($identifier === '') {
            return back_with_errors('/forgot-password', ['identifier' => 'Please enter your email or mobile number.']);
        }
        if (!RateLimiter::hit('password_reset', $request->ip()) || !RateLimiter::hit('password_reset', AuthService::identifierKey($identifier))) {
            return back_with_errors('/forgot-password', ['identifier' => 'Too many reset requests. Please try again in an hour.'], $request->all());
        }

        if (str_contains($identifier, '@')) {
            if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                return back_with_errors('/forgot-password', ['identifier' => 'Please enter a valid email address.'], $request->all());
            }
            AuthService::sendPasswordResetLink($identifier, $request);
            Session::flash('success', 'If an account uses that email, a reset link is on its way. It is valid for 60 minutes.');
            return Response::redirect('/forgot-password');
        }

        // Mobile number: SMS code when OTP is on, otherwise use the email linked to it.
        $mobile = normalize_mobile($identifier);
        if ($mobile === null) {
            return back_with_errors('/forgot-password', ['identifier' => 'Enter a valid email or 10-digit mobile number.'], $request->all());
        }
        $user = UserRepository::findByMobile($mobile);
        if (Settings::bool('auth_otp_enabled')) {
            if ($user !== null && $user['status'] !== 'blocked') {
                $error = OtpService::send($mobile, 'reset_password', $request->ip());
                if ($error !== null) {
                    return back_with_errors('/forgot-password', ['identifier' => $error], $request->all());
                }
            }
            Session::set('reset_mobile', $mobile);
            Session::flash('success', 'If this number is registered, we have sent a 6-digit code.');
            return Response::redirect('/reset-password/otp');
        }
        if ($user !== null && $user['email']) {
            AuthService::sendPasswordResetLink($user['email'], $request);
        }
        Session::flash('success', 'If this number is registered, a reset link has been sent to the email on that account.');
        return Response::redirect('/forgot-password');
    }

    public function showReset(Request $request): Response
    {
        $token = (string) $request->query('token', '');
        $valid = AuthService::findValidResetToken($token) !== null;
        $response = Response::html(View::render('auth/reset', [
            'title' => 'Choose a new password', 'token' => $token, 'valid' => $valid, 'otp' => false,
        ]));
        $response->headers['Referrer-Policy'] = 'no-referrer'; // keep the token out of Referer headers
        return $response;
    }

    public function reset(Request $request): Response
    {
        $token = $request->str('token');
        $row = AuthService::findValidResetToken($token);
        if ($row === null) {
            Session::flash('error', 'This reset link is invalid or has expired. Please request a new one.');
            return Response::redirect('/forgot-password');
        }
        $v = new Validator($request->all(), ['password' => 'required|password', 'password_confirmation' => 'required|same:password'],
            ['password_confirmation' => 'Confirm password']);
        if ($v->fails()) {
            return back_with_errors('/reset-password?token=' . $token, $v->errors());
        }
        AuthService::resetPassword((int) $row['user_id'], (string) $request->input('password'), $request, (int) $row['id']);
        Auth::logout();
        Session::flash('success', 'Your password has been changed. Please log in with the new password.');
        return Response::redirect('/login');
    }

    public function showResetOtp(Request $request): Response
    {
        $mobile = Session::get('reset_mobile');
        if (!is_string($mobile)) {
            return Response::redirect('/forgot-password');
        }
        return Response::html(View::render('auth/reset', [
            'title' => 'Choose a new password', 'token' => '', 'valid' => true, 'otp' => true, 'mobile' => $mobile,
        ]));
    }

    public function resetOtp(Request $request): Response
    {
        $mobile = Session::get('reset_mobile');
        if (!is_string($mobile)) {
            return Response::redirect('/forgot-password');
        }
        $v = new Validator($request->all(), [
            'code' => 'required|digits:6', 'password' => 'required|password', 'password_confirmation' => 'required|same:password',
        ], ['code' => 'Code', 'password_confirmation' => 'Confirm password']);
        if ($v->fails()) {
            return back_with_errors('/reset-password/otp', $v->errors());
        }
        if (!RateLimiter::hit('otp_verify', $mobile)) {
            return back_with_errors('/reset-password/otp', ['code' => 'Too many wrong codes. Please request a new code later.']);
        }
        $user = UserRepository::findByMobile($mobile);
        if ($user === null || !OtpService::verify($mobile, 'reset_password', $request->str('code'))) {
            return back_with_errors('/reset-password/otp', ['code' => 'That code is incorrect or has expired.']);
        }
        RateLimiter::clear('otp_verify', $mobile);
        UserRepository::markMobileVerified((int) $user['id']);
        AuthService::resetPassword((int) $user['id'], (string) $request->input('password'), $request);
        Session::forget('reset_mobile');
        Session::flash('success', 'Your password has been changed. Please log in with the new password.');
        return Response::redirect('/login');
    }

    // ------------------------------------------------------------------

    private function afterLogin(array $user, string $next): Response
    {
        if ($user['status'] === 'inactive' || $user['status'] === 'suspended') {
            Session::flash('error', 'Your account is ' . $user['status'] . '. You can browse, but shortlisting, contacting owners and posting are paused. Please contact support.');
            return Response::redirect('/account');
        }
        if (Settings::bool('auth_otp_enabled') && $user['mobile_verified_at'] === null) {
            Session::set('after_verify', $next);
            return Response::redirect('/verify-mobile');
        }
        Session::flash('success', 'Welcome back, ' . $user['first_name'] . '!');
        return Response::redirect($next);
    }
}
