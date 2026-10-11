<?php
/** @var string $content @var string $title @var ?string $section */
use App\Core\Auth;
use App\Core\Settings;
use App\Repositories\AdminRepository;
$siteName = (string) Settings::get('site_name', 'Rental Website');
$admin = Auth::isAdmin() ? Auth::user() : null;
$section ??= '';
$alerts = $admin ? AdminRepository::alerts() : [];
$menu = [
    'dashboard'     => ['Dashboard', '/admin', null],
    'analytics'     => ['Analytics', '/admin/analytics', null],
    'properties'    => ['Properties', '/admin/properties', null],
    'approvals'     => ['Approvals', '/admin/properties?status=pending', $alerts['approvals'] ?? 0],
    'order'         => ['Display Order', '/admin/order', null],
    'rent'          => ['Rental Changes', '/admin/rent-changes', $alerts['rent_changes'] ?? 0],
    'limits'        => ['Limit Requests', '/admin/limit-requests', $alerts['limit_requests'] ?? 0],
    'users'         => ['Users', '/admin/users', null],
    'enquiries'     => ['Enquiries', '/admin/enquiries', $alerts['new_enquiries'] ?? 0],
    'bookings'      => ['Bookings', '/admin/bookings', $alerts['new_bookings'] ?? 0],
    'sliders'       => ['Homepage Sliders', '/admin/sliders', null],
    'notifications' => ['Notifications', '/admin/notifications', $alerts['unread'] ?? 0],
    'settings'      => ['Settings', '/admin/settings', null],
    'activity'      => ['Activity Log', '/admin/activity', null],
];
$scripts ??= [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? 'Admin') . ' | ' . $siteName . ' Admin') ?></title>
    <?php if (\App\Core\Session::isStarted()): ?><meta name="csrf-token" content="<?= e(\App\Core\Csrf::token()) ?>"><?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="admin-body">
<nav class="navbar navbar-dark admin-topbar px-3">
    <div class="d-flex align-items-center gap-2">
        <?php if ($admin): ?>
            <button class="btn btn-sm btn-outline-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminMenu" aria-controls="adminMenu" aria-label="Open menu">&#9776;</button>
        <?php endif; ?>
        <a class="navbar-brand mb-0 h1" href="<?= e(url('/admin')) ?>"><?= e($siteName) ?> <span class="badge text-bg-light ms-1">Admin</span></a>
    </div>
    <?php if ($admin): ?>
        <div class="d-flex align-items-center gap-3">
            <a class="text-white text-decoration-none small" href="<?= e(url('/admin/notifications')) ?>">
                Alerts<?php if (($alerts['unread'] ?? 0) > 0): ?> <span class="badge rounded-pill text-bg-danger"><?= (int) $alerts['unread'] ?></span><?php endif; ?>
            </a>
            <span class="text-white-50 small d-none d-sm-inline"><?= e($admin['first_name'] . ' ' . $admin['last_name']) ?></span>
            <form method="post" action="<?= e(url('/admin/logout')) ?>" class="m-0">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-light" type="submit">Sign out</button>
            </form>
        </div>
    <?php endif; ?>
</nav>
<?php require APP_ROOT . '/views/partials/flash.php'; ?>
<div class="admin-shell">
    <?php if ($admin): ?>
        <aside class="offcanvas-lg offcanvas-start admin-sidebar" tabindex="-1" id="adminMenu" aria-labelledby="adminMenuLabel">
            <div class="offcanvas-header d-lg-none">
                <h2 class="offcanvas-title h6" id="adminMenuLabel">Admin menu</h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#adminMenu" aria-label="Close"></button>
            </div>
            <nav class="offcanvas-body flex-column" aria-label="Admin menu">
                <?php foreach ($menu as $key => [$label, $href, $count]): ?>
                    <a class="admin-nav-link<?= $section === $key ? ' active' : '' ?>" href="<?= e(url($href)) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>>
                        <span><?= e($label) ?></span>
                        <?php if ($count): ?><span class="badge rounded-pill text-bg-warning"><?= (int) $count ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <a class="admin-nav-link mt-2" href="<?= e(url('/admin/properties/new')) ?>"><span>+ Add Property</span></a>
            </nav>
        </aside>
    <?php endif; ?>
    <main class="admin-main py-4 px-3 px-lg-4">
        <?= $content ?>
    </main>
</div>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>" defer></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?php foreach ($scripts as $s): ?><script src="<?= e(asset($s)) ?>" defer></script><?php endforeach; ?>
</body>
</html>
