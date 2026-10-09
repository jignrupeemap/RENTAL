<?php
/** Owner area shell: section menu + content, inside the main site layout. @var string $content @var string $section */
$this->layout('layouts/main', ['noindex' => true]);
$menu = [
    'dashboard'  => ['Dashboard', '/owner'],
    'properties' => ['My Properties', '/owner/properties'],
    'add'        => ['Add Property', '/owner/properties/new'],
    'enquiries'  => ['Enquiries', '/owner/enquiries'],
    'bookings'   => ['Booking / Visit Requests', '/owner/bookings'],
    'rent'       => ['Rental Change History', '/owner/rent-changes'],
    'limit'      => ['Property Limit', '/owner/limit'],
    'profile'    => ['Profile', '/owner/profile'],
];
$section ??= '';
?>
<div class="container py-4">
    <div class="row g-4">
        <aside class="col-lg-3">
            <nav class="owner-nav" aria-label="Owner menu">
                <?php foreach ($menu as $key => [$label, $href]): ?>
                    <a class="owner-nav-link<?= $section === $key ? ' active' : '' ?>" href="<?= e(url($href)) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
                <?php endforeach; ?>
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
