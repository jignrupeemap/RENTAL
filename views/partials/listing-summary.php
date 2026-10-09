<?php
/**
 * Small card naming the listing a form is about (Contact Owner, Request Visit).
 * @var array $p @var ?array $cover
 */
use App\Controllers\Public\ListingController;
$name = $p['title'] !== '' ? $p['title'] : ListingController::headline($p);
?>
<a class="card border-0 shadow-sm text-reset text-decoration-none mb-3 listing-summary" href="<?= e(url(property_url($p))) ?>">
    <div class="card-body d-flex gap-3 align-items-center">
        <div class="shortlist-thumb rounded-2 overflow-hidden flex-shrink-0">
            <?php if ($cover): ?><?= media_picture($cover, '160px', $name, true, 'w-100 h-100', 'thumb') ?><?php else: ?><span class="property-card-noimg">No photo</span><?php endif; ?>
        </div>
        <div class="min-w-0">
            <div class="small text-muted"><?= e(ListingController::headline($p)) ?></div>
            <div class="fw-semibold text-truncate"><?= e($name) ?></div>
            <div class="small text-muted"><?= e(implode(', ', array_filter([$p['locality_label'], $p['city_name']]))) ?> · <?= e(money_inr($p['rent'] !== null ? (int) $p['rent'] : null)) ?> / month</div>
        </div>
    </div>
</a>
