<?php
/**
 * Listing card (spec 49): photo, title, place, rent, three key facts,
 * shortlist heart and Rent/PG/Lease tag on the photo, View Details. Kept short.
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
    // Homes: the title already says the BHK, so no details line.
    $r['field_profile'] === 'residential' => [],
    default => [$area, $opt('furnishing', $r['furnishing'])],
};
$facts = array_values(array_filter($facts));
?>
<article class="card property-card h-100 border-0 shadow-sm">
    <div class="property-card-top">
    <a class="property-card-media" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true">
        <?php if ($r['cover']): ?>
            <?= media_picture($r['cover'], '(max-width: 576px) 100vw, (max-width: 992px) 50vw, 360px', $name, !($eager ?? false), 'w-100 h-100', 'thumb') ?>
        <?php else: ?>
            <span class="property-card-noimg">No photo</span>
        <?php endif; ?>
        <span class="property-card-tags">
            <span class="badge purpose-badge purpose-<?= e($r['purpose']) ?>"><?= e(purpose_label($r['purpose'])) ?></span>
            <?php if ((int) $r['featured_now']): ?><span class="badge featured-badge">Featured</span><?php endif; ?>
        </span>
    </a>
    <?php /* Outside the photo link: a form cannot sit inside <a>. */ ?>
    <div class="property-card-like">
        <?php $sid = (int) $r['id']; $son = $on; $sreturn = $return; $sclass = 'btn btn-sm btn-light'; require APP_ROOT . '/views/partials/shortlist-button.php'; ?>
    </div>
    </div>
    <div class="card-body d-flex flex-column gap-1 p-3">
        <div class="small property-card-type"><?= e($r['type_name']) ?> · <?= e(purpose_label($r['purpose'])) ?></div>
        <h3 class="h6 mb-0 property-card-title"><a class="text-reset" href="<?= e($href) ?>"><?= e($name) ?></a></h3>
        <div class="small property-card-place"><?= e($place) ?></div>
        <div class="property-card-price mt-1"><?= e(money_inr($r['rent'] !== null ? (int) $r['rent'] : null)) ?> <span class="per">/ month</span></div>
        <?php if ($facts): ?><div class="small property-card-facts"><?= e(implode(' | ', $facts)) ?></div><?php endif; ?>
        <div class="mt-auto pt-2">
            <a class="btn btn-sm btn-primary w-100" href="<?= e($href) ?>">View Details</a>
        </div>
    </div>
</article>
