<?php
/** @var string $content @var string $title */
use App\Core\Auth;
use App\Core\Settings;
$siteName = (string) Settings::get('site_name', 'Rental Website');
$user = Auth::user();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$active = static fn (string $p): string => ($p === '/' ? $path === '/' : str_starts_with($path, $p)) ? ' active" aria-current="page' : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($title ?? '') !== '' && ($title ?? '') !== $siteName ? $title . ' | ' . $siteName : $siteName) ?></title>
    <?php if (!empty($metaDescription)): ?><meta name="description" content="<?= e($metaDescription) ?>"><?php endif; ?>
    <?php if (!empty($noindex)): ?><meta name="robots" content="noindex, follow"><?php endif; ?>
    <?php if (!empty($canonical)): ?>
        <link rel="canonical" href="<?= e($canonical) ?>">
        <meta property="og:url" content="<?= e($canonical) ?>">
        <meta property="og:title" content="<?= e($title ?? $siteName) ?>">
        <meta property="og:site_name" content="<?= e($siteName) ?>">
        <meta property="og:type" content="<?= e($og['type'] ?? 'website') ?>">
        <?php if (!empty($metaDescription)): ?><meta property="og:description" content="<?= e($metaDescription) ?>"><?php endif; ?>
        <?php if (!empty($og['image'])): ?><meta property="og:image" content="<?= e($og['image']) ?>"><meta name="twitter:card" content="summary_large_image"><?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($jsonLd)): ?><script type="application/ld+json"><?= $jsonLd /* json_encode with JSON_HEX_TAG, safe inside <script> */ ?></script><?php endif; ?>
    <?php if (\App\Core\Session::isStarted()): ?><meta name="csrf-token" content="<?= e(\App\Core\Csrf::token()) ?>"><?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="d-flex flex-column min-vh-100">
<header class="site-header border-bottom bg-white sticky-top">
    <nav class="navbar navbar-expand-md py-2">
        <div class="container">
        <a class="navbar-brand brand" href="<?= e(url('/')) ?>"><?= e($siteName) ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto align-items-md-center gap-md-1">
                <li class="nav-item"><a class="nav-link<?= $active('/') ?>" href="<?= e(url('/')) ?>">Home</a></li>
                <li class="nav-item"><a class="nav-link<?= $active('/rent') ?>" href="<?= e(url('/rent')) ?>">Rent</a></li>
                <li class="nav-item"><a class="nav-link<?= $active('/pg') ?>" href="<?= e(url('/pg')) ?>">PG</a></li>
                <li class="nav-item"><a class="nav-link<?= $active('/lease') ?>" href="<?= e(url('/lease')) ?>">Lease</a></li>
                <?php if ($user): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle<?= $active('/account') ?>" href="<?= e(url('/account')) ?>" role="button" data-bs-toggle="dropdown" aria-expanded="false">My Account</a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-item-text small text-muted">Hi, <?= e($user['first_name']) ?></span></li>
                            <li><a class="dropdown-item" href="<?= e(url('/account')) ?>">My Dashboard</a></li>
                            <li><a class="dropdown-item" href="<?= e(url('/account/shortlist')) ?>">Shortlisted</a></li>
                            <li><a class="dropdown-item" href="<?= e(url('/account/contacted')) ?>">Contacted</a></li>
                            <li><a class="dropdown-item" href="<?= e(url('/account/bookings')) ?>">Visit Requests</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= e(url('/owner')) ?>">Owner Dashboard</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="<?= e(url('/logout')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="dropdown-item" type="submit">Logout</button>
                                </form>
                            </li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item ms-md-2"><a class="btn btn-primary btn-sm px-3" href="<?= e(url('/login')) ?>">Login</a></li>
                <?php endif; ?>
            </ul>
        </div>
        </div>
    </nav>
</header>

<main class="flex-grow-1">
    <?php require APP_ROOT . '/views/partials/flash.php'; ?>
    <?= $content ?>
</main>

<footer class="site-footer border-top bg-white mt-5">
    <div class="container py-4 d-flex flex-column flex-md-row justify-content-between gap-2 small text-muted">
        <span>&copy; <?= gmdate('Y') ?> <?= e($siteName) ?></span>
        <span class="d-flex gap-3">
            <a class="link-secondary" href="<?= e(url('/post-requirement')) ?>">Post your requirement</a>
            <a class="link-secondary" href="<?= e(url('/page/about')) ?>">About</a>
            <a class="link-secondary" href="<?= e(url('/page/terms')) ?>">Terms</a>
            <a class="link-secondary" href="<?= e(url('/page/privacy')) ?>">Privacy</a>
        </span>
    </div>
</footer>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>" defer></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?php foreach ($scripts ?? [] as $script): ?>
<script src="<?= e(asset($script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
