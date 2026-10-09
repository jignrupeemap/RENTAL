<?php
declare(strict_types=1);

/**
 * Route map. Grows phase by phase:
 *   Phase 2  auth (below), /account, /admin/login
 *   Phase 3  /owner/*        (owner dashboard)
 *   Phase 4  /owner/properties/* (posting wizard)
 *   Phase 6  /admin/*        (admin panel)
 *   Phase 7  /rent /pg /lease and /{purpose}/{slug}-p{id}
 *   Phase 8  /account/*      (viewer dashboard)
 *   Phase 9  /property/{id}/contact, /property/{id}/visit, /post-requirement
 *   Phase 11 /admin/sliders (homepage slider)
 *   Phase 12 /admin/analytics, /owner/properties/{id}/performance
 *   /api/*   JSON endpoints used by fetch()
 *
 * CSRF is checked on every POST (global middleware in public/index.php).
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Account\AccountController;
use App\Controllers\Admin;
use App\Controllers\Admin\AuthController as AdminAuthController;
use App\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Controllers\HomeController;
use App\Controllers\Owner;
use App\Controllers\Public\AuthController;
use App\Controllers\Public\ContactController;
use App\Controllers\Public\ListingController;
use App\Controllers\SeoController;
use App\Controllers\SetupController;
use App\Middleware\Authenticate;
use App\Middleware\RedirectIfAuthenticated;
use App\Middleware\RequireActiveAccount;
use App\Middleware\RequireAdmin;

$router->get('/', [HomeController::class, 'index']);
$router->get('/search', [ListingController::class, 'search']);
$router->get('/{purpose:rent|pg|lease}', [ListingController::class, 'index']);
$router->get('/{purpose:rent|pg|lease}/{slug:[a-z0-9-]+}-p{id:\d+}', [ListingController::class, 'show']);
$router->get('/robots.txt', [SeoController::class, 'robots']);
$router->get('/sitemap.xml', [SeoController::class, 'sitemap']);
$router->get('/page/{slug:[a-z0-9-]+}', [HomeController::class, 'page']);
$router->get('/health', [HomeController::class, 'health']);
$router->get('/post-requirement', [ContactController::class, 'requirementForm']);
$router->post('/post-requirement', [ContactController::class, 'requirement']);

// ---- Authentication (guests only) ----
$router->get('/login', [AuthController::class, 'showLogin'], [RedirectIfAuthenticated::class]);
$router->post('/login', [AuthController::class, 'login'], [RedirectIfAuthenticated::class]);
$router->get('/register', [AuthController::class, 'showRegister'], [RedirectIfAuthenticated::class]);
$router->post('/register', [AuthController::class, 'register'], [RedirectIfAuthenticated::class]);
$router->post('/login/otp/send', [AuthController::class, 'sendLoginOtp'], [RedirectIfAuthenticated::class]);
$router->get('/login/otp', [AuthController::class, 'showLoginOtp'], [RedirectIfAuthenticated::class]);
$router->post('/login/otp', [AuthController::class, 'verifyLoginOtp'], [RedirectIfAuthenticated::class]);
$router->get('/forgot-password', [AuthController::class, 'showForgot']);
$router->post('/forgot-password', [AuthController::class, 'forgot']);
$router->get('/reset-password', [AuthController::class, 'showReset']);
$router->post('/reset-password', [AuthController::class, 'reset']);
$router->get('/reset-password/otp', [AuthController::class, 'showResetOtp']);
$router->post('/reset-password/otp', [AuthController::class, 'resetOtp']);
$router->get('/verify-email', [AuthController::class, 'verifyEmail']);

// ---- Logged-in users ----
$router->group('', [Authenticate::class], function ($router): void {
    $router->post('/logout', [AuthController::class, 'logout']);
    $router->get('/verify-mobile', [AuthController::class, 'showVerifyMobile']);
    $router->post('/verify-mobile/send', [AuthController::class, 'sendVerifyMobile']);
    $router->post('/verify-mobile', [AuthController::class, 'verifyMobile']);
    $router->post('/verify-email/resend', [AuthController::class, 'resendEmailVerification']);
    // Viewer dashboard (Phase 8). Reading works for any status; changes need an active account.
    $router->get('/account', [AccountController::class, 'index']);
    $router->get('/account/shortlist', [AccountController::class, 'shortlist']);
    $router->get('/account/contacted', [AccountController::class, 'contacted']);
    $router->get('/account/bookings', [AccountController::class, 'bookings']);
    $router->post('/account/bookings/{id:\d+}', [AccountController::class, 'bookingAction'], [RequireActiveAccount::class]);
    $router->get('/account/profile', [AccountController::class, 'profile']);
    $router->post('/account/profile', [AccountController::class, 'updateProfile'], [RequireActiveAccount::class]);
    $router->post('/account/notifications/read', [AccountController::class, 'markNotificationsRead']);
    $router->post('/account/password', [AccountController::class, 'changePassword']);
    $router->post('/shortlist/{id:\d+}', [ListingController::class, 'shortlist'], [RequireActiveAccount::class]);
    // Contact Owner and Request Visit (Phase 9).
    $router->get('/property/{id:\d+}/contact', [ContactController::class, 'contactForm']);
    $router->post('/property/{id:\d+}/contact', [ContactController::class, 'contact'], [RequireActiveAccount::class]);
    $router->get('/property/{id:\d+}/visit', [ContactController::class, 'visitForm']);
    $router->post('/property/{id:\d+}/visit', [ContactController::class, 'visit'], [RequireActiveAccount::class]);
});

// ---- Owner dashboard ----
// Any logged-in account can open it (the owner role is added with the first
// listing). Reading is allowed for inactive accounts; every change needs an
// active account (RequireActiveAccount). All queries are scoped to Auth::id().
$router->group('/owner', [Authenticate::class], function ($router): void {
    $router->get('', [Owner\DashboardController::class, 'index']);
    $router->post('/notifications/read', [Owner\DashboardController::class, 'markNotificationsRead']);
    $router->get('/properties', [Owner\PropertyController::class, 'index']);
    // Posting form (Phase 4). Opening or saving the form needs an active account.
    $router->get('/properties/new', [Owner\PropertyWizardController::class, 'start'], [RequireActiveAccount::class]);
    $router->post('/properties/new', [Owner\PropertyWizardController::class, 'create'], [RequireActiveAccount::class]);
    $router->get('/properties/{id:\d+}/edit', [Owner\PropertyWizardController::class, 'edit'], [RequireActiveAccount::class]);
    $router->get('/properties/{id:\d+}/step/{step:[1-8]}', [Owner\PropertyWizardController::class, 'show'], [RequireActiveAccount::class]);
    $router->post('/properties/{id:\d+}/step/{step:[1-7]}', [Owner\PropertyWizardController::class, 'save'], [RequireActiveAccount::class]);
    $router->post('/properties/{id:\d+}/submit', [Owner\PropertyWizardController::class, 'submit'], [RequireActiveAccount::class]);
    $router->post('/properties/{id:\d+}/delete', [Owner\PropertyWizardController::class, 'delete'], [RequireActiveAccount::class]);
    // Photos & video (Phase 5)
    $router->post('/properties/{id:\d+}/photos', [Owner\MediaController::class, 'uploadPhotos'], [RequireActiveAccount::class]);
    $router->post('/properties/{id:\d+}/video', [Owner\MediaController::class, 'uploadVideo'], [RequireActiveAccount::class]);
    $router->post('/properties/{id:\d+}/media/{mid:\d+}/cover', [Owner\MediaController::class, 'cover'], [RequireActiveAccount::class]);
    $router->post('/properties/{id:\d+}/media/{mid:\d+}/move', [Owner\MediaController::class, 'move'], [RequireActiveAccount::class]);
    $router->post('/properties/{id:\d+}/media/{mid:\d+}/delete', [Owner\MediaController::class, 'delete'], [RequireActiveAccount::class]);
    $router->post('/properties/{id:\d+}/pause', [Owner\PropertyController::class, 'togglePause'], [RequireActiveAccount::class]);
    $router->get('/properties/{id:\d+}/performance', [Owner\PropertyController::class, 'performance']);
    $router->get('/enquiries', [Owner\EnquiryController::class, 'index']);
    $router->post('/enquiries/{id:\d+}', [Owner\EnquiryController::class, 'updateStatus'], [RequireActiveAccount::class]);
    $router->get('/bookings', [Owner\BookingController::class, 'index']);
    $router->post('/bookings/{id:\d+}', [Owner\BookingController::class, 'act'], [RequireActiveAccount::class]);
    $router->get('/rent-changes', [Owner\RentChangeController::class, 'index']);
    $router->get('/limit', [Owner\LimitController::class, 'index']);
    $router->post('/limit', [Owner\LimitController::class, 'request'], [RequireActiveAccount::class]);
    $router->get('/profile', [Owner\ProfileController::class, 'show']);
    $router->post('/profile', [Owner\ProfileController::class, 'update'], [RequireActiveAccount::class]);
});

// ---- Admin (separate session cookie) ----
$router->get('/admin/login', [AdminAuthController::class, 'showLogin']);
$router->post('/admin/login', [AdminAuthController::class, 'login']);
$router->group('/admin', [RequireAdmin::class], function ($router): void {
    $router->get('', [AdminDashboardController::class, 'index']);
    $router->get('/analytics', [Admin\AnalyticsController::class, 'index']);
    $router->get('/analytics/export', [Admin\AnalyticsController::class, 'export']);
    $router->post('/logout', [AdminAuthController::class, 'logout']);
    // Properties (Phase 6): list, view, status actions, featured, owner, note
    $router->get('/properties', [Admin\PropertyController::class, 'index']);
    $router->get('/properties/{id:\d+}', [Admin\PropertyController::class, 'show']);
    $router->post('/properties/{id:\d+}/action', [Admin\PropertyController::class, 'action']);
    $router->post('/properties/{id:\d+}/featured', [Admin\PropertyController::class, 'featured']);
    $router->post('/properties/{id:\d+}/owner', [Admin\PropertyController::class, 'owner']);
    $router->post('/properties/{id:\d+}/note', [Admin\PropertyController::class, 'note']);
    // Add / Edit with the posting form
    $router->get('/properties/new', [Admin\PropertyWizardController::class, 'start']);
    $router->post('/properties/new', [Admin\PropertyWizardController::class, 'create']);
    $router->get('/properties/{id:\d+}/edit', [Admin\PropertyWizardController::class, 'edit']);
    $router->get('/properties/{id:\d+}/step/{step:[1-8]}', [Admin\PropertyWizardController::class, 'show']);
    $router->post('/properties/{id:\d+}/step/{step:[1-7]}', [Admin\PropertyWizardController::class, 'save']);
    $router->post('/properties/{id:\d+}/photos', [Admin\MediaController::class, 'uploadPhotos']);
    $router->post('/properties/{id:\d+}/video', [Admin\MediaController::class, 'uploadVideo']);
    $router->post('/properties/{id:\d+}/media/{mid:\d+}/cover', [Admin\MediaController::class, 'cover']);
    $router->post('/properties/{id:\d+}/media/{mid:\d+}/move', [Admin\MediaController::class, 'move']);
    $router->post('/properties/{id:\d+}/media/{mid:\d+}/delete', [Admin\MediaController::class, 'delete']);
    $router->get('/order', [Admin\OrderController::class, 'index']);
    $router->post('/order', [Admin\OrderController::class, 'save']);
    $router->get('/rent-changes', [Admin\RentChangeController::class, 'index']);
    $router->get('/rent-changes/{id:\d+}', [Admin\RentChangeController::class, 'show']);
    $router->post('/rent-changes/{id:\d+}', [Admin\RentChangeController::class, 'review']);
    $router->get('/limit-requests', [Admin\LimitRequestController::class, 'index']);
    $router->get('/limit-requests/{id:\d+}', [Admin\LimitRequestController::class, 'index']);
    $router->post('/limit-requests/{id:\d+}', [Admin\LimitRequestController::class, 'review']);
    $router->get('/users', [Admin\UserController::class, 'index']);
    $router->get('/users/{id:\d+}', [Admin\UserController::class, 'show']);
    $router->post('/users/{id:\d+}', [Admin\UserController::class, 'update']);
    $router->post('/users/{id:\d+}/status', [Admin\UserController::class, 'status']);
    $router->post('/users/{id:\d+}/limit', [Admin\UserController::class, 'limit']);
    $router->post('/users/{id:\d+}/signout', [Admin\UserController::class, 'signout']);
    $router->post('/users/{id:\d+}/delete', [Admin\UserController::class, 'delete']);
    $router->post('/users/{id:\d+}/restore', [Admin\UserController::class, 'restore']);
    $router->get('/sliders', [Admin\SliderController::class, 'index']);
    $router->get('/sliders/new', [Admin\SliderController::class, 'create']);
    $router->post('/sliders', [Admin\SliderController::class, 'store']);
    $router->get('/sliders/{id:\d+}/edit', [Admin\SliderController::class, 'edit']);
    $router->post('/sliders/{id:\d+}', [Admin\SliderController::class, 'update']);
    $router->post('/sliders/{id:\d+}/status', [Admin\SliderController::class, 'status']);
    $router->post('/sliders/{id:\d+}/move', [Admin\SliderController::class, 'move']);
    $router->post('/sliders/{id:\d+}/delete', [Admin\SliderController::class, 'delete']);
    $router->get('/enquiries', [Admin\EngagementController::class, 'enquiries']);
    $router->post('/enquiries/{id:\d+}', [Admin\EngagementController::class, 'updateEnquiry']);
    $router->get('/bookings', [Admin\EngagementController::class, 'bookings']);
    $router->get('/notifications', [Admin\NotificationController::class, 'index']);
    $router->get('/notifications/{id:\d+}', [Admin\NotificationController::class, 'open']);
    $router->post('/notifications/read', [Admin\NotificationController::class, 'readAll']);
    $router->get('/settings', [Admin\SettingsController::class, 'index']);
    $router->post('/settings', [Admin\SettingsController::class, 'save']);
    $router->get('/activity', [Admin\ActivityController::class, 'index']);
});

// Setup page for Hostinger plans without SSH: readiness check, migrations,
// first admin. Every /setup address is a 404 unless SETUP_TOKEN is set in
// config/env.php (see docs/DEPLOYMENT.md).
$router->get('/setup', [SetupController::class, 'index']);
$router->post('/setup', [SetupController::class, 'unlock']);
$router->post('/setup/run', [SetupController::class, 'runMigrations']);
$router->post('/setup/admin', [SetupController::class, 'createAdmin']);
$router->post('/setup/lock', [SetupController::class, 'lock']);
$router->get('/setup/migrate', [SetupController::class, 'form']);
$router->post('/setup/migrate', [SetupController::class, 'migrate']);
