<?php
/**
 * Listing card (spec 49): photo, title, place, rent, three key facts,
 * shortlist and View Details. Kept short on purpose.
 * @var array $r  row from ListingRepository (with 'cover') @var bool $on  shortlisted @var string $return
 * @var bool $eager  first cards on the page load without lazy-loading
 */
use App\Services\PropertyForm;

$opt = static fn (string $k, $v): ?string => $v === null || $v === '' ? null : (PropertyForm::options($k)[$v] ?? null);
$href = url(property_url($r));
$place = implode(', ', array_filter([$r['locality_label'], $r['city_name']]));
$name = $r['title'] !== '' ? $r['title'] : $r['type_name'];
$area = $r['area_value'] !== null ? rtrim(rtrim((string) $r['area_value'], '0'), '.') . ' ' . ($opt('area_unit', $r['area_unit']) ?? '') : null;
$facts = match (true) {
    $r['purpose'] === 'pg' => [$opt('pg_available_for', $r['pg_available_for']) ? 'For ' . $opt('pg_available_for', $r['pg_available_for']) : null,
        $opt('pg_suitable_for', $r['pg_suitable_for']), $opt('furnishing', $r['furnishing'])],
    $r['field_profile'] === 'residential' => [$opt('bedrooms', $r['bedrooms']), $r['bathrooms'] ? $r['bathrooms'] . ' Bath' : null, $opt('furnishing', $r['furnishing'])],
    default => [$area, $opt('furnishing', $r['furnishing'])],
};
$facts = array_values(array_filter($facts));
?>
<article class="card property-card h-100 border-0 shadow-sm">
    <a class="property-card-media" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true">
        <?php if ($r['cover']): ?>
            <?= media_picture($r['cover'], '(max-width: 576px) 100vw, (max-width: 992px) 50vw, 360px', $name, !($eager ?? false), 'w-100 h-100', 'thumb') ?>
        <?php else: ?>
            <span class="property-card-noimg">No photo</span>
        <?php endif; ?>
        <?php if ((int) $r['featured_now']): ?><span class="badge featured-badge">Featured</span><?php endif; ?>
    </a>
    <div class="card-body d-flex flex-column gap-1 p-3">
        <div class="small text-muted"><?= e($r['type_name']) ?> · <?= e(purpose_label($r['purpose'])) ?></div>
        <h3 class="h6 mb-0 property-card-title"><a class="text-reset" href="<?= e($href) ?>"><?= e($name) ?></a></h3>
        <div class="small text-muted"><?= e($place) ?></div>
        <div class="fw-semibold fs-5 mt-1"><?= e(money_inr($r['rent'] !== null ? (int) $r['rent'] : null)) ?> <span class="fs-6 fw-normal text-muted">/ month</span></div>
        <?php if ($facts): ?><div class="small property-card-facts"><?= e(implode(' | ', $facts)) ?></div><?php endif; ?>
        <div class="d-flex gap-2 mt-auto pt-2">
            <?php $sid = (int) $r['id']; $son = $on; $sreturn = $return; $sclass = 'btn btn-sm btn-outline-secondary px-3'; require APP_ROOT . '/views/partials/shortlist-button.php'; ?>
            <a class="btn btn-sm btn-primary ms-auto" href="<?= e($href) ?>">View Details</a>
        </div>
    </div>
</article>
