<?php
/** Viewer area shell (spec 23): menu + content inside the main site layout. @var string $content @var string $section */
$this->layout('layouts/main', ['noindex' => true]);
$menu = [
    'dashboard' => ['My Dashboard', '/account'],
    'shortlist' => ['Shortlisted Properties', '/account/shortlist'],
    'contacted' => ['Contacted Properties', '/account/contacted'],
    'bookings'  => ['Booking / Visit Requests', '/account/bookings'],
    'profile'   => ['Profile', '/account/profile'],
];
$section ??= '';
?>
<div class="container py-4">
    <div class="row g-4">
        <aside class="col-lg-3">
            <nav class="owner-nav" aria-label="My account menu">
                <?php foreach ($menu as $key => [$label, $href]): ?>
                    <a class="owner-nav-link<?= $section === $key ? ' active' : '' ?>" href="<?= e(url($href)) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
                <?php endforeach; ?>
                <a class="owner-nav-link" href="<?= e(url('/owner')) ?>">Owner Dashboard</a>
                <form method="post" action="<?= e(url('/logout')) ?>" class="d-inline d-lg-block">
                    <?= csrf_field() ?>
                    <button class="owner-nav-link btn btn-link text-start" type="submit">Logout</button>
                </form>
            </nav>
        </aside>
        <div class="col-lg-9 min-w-0">
            <?= $content ?>
        </div>
    </div>
</div>
