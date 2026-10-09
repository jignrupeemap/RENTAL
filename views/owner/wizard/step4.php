<?php
/** Step 4: facilities for this type, plus PG extra-cost services. @var array $amenities @var array $selectedAmenities @var array $services */
$picked = $selectedAmenities;
$groups = ['feature' => 'Features', 'facility' => 'Facilities'];
?>
<form method="post" action="<?= e($stepUrl(4)) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="amenities[]" value="0">
    <?php foreach ($groups as $g => $heading):
        $list = array_filter($amenities, static fn ($a) => $a['amenity_group'] === $g);
        if (!$list) continue; ?>
        <h2 class="h6 mb-2"><?= e($heading) ?> <span class="text-muted small fw-normal">(tick all that apply)</span></h2>
        <div class="check-grid mb-4">
            <?php foreach ($list as $a): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="amenities[]" id="am_<?= (int) $a['id'] ?>" value="<?= (int) $a['id'] ?>"<?= in_array((int) $a['id'], $picked, true) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="am_<?= (int) $a['id'] ?>"><?= e($a['name']) ?></label>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <?php if (isset($fields['pg_services'])): ?>
        <h2 class="h6 mb-1">Other services with extra cost</h2>
        <p class="small text-muted">Tick what you offer. Add the monthly price if you want to show it.</p>
        <input type="hidden" name="pg_services[]" value="">
        <div class="vstack gap-2 mb-2">
            <?php foreach (\App\Services\PropertyForm::options('pg_services') as $code => $label): ?>
                <div class="d-flex flex-wrap align-items-center gap-2 service-row">
                    <div class="form-check mb-0 service-name">
                        <input class="form-check-input" type="checkbox" name="pg_services[]" id="sv_<?= e($code) ?>" value="<?= e($code) ?>"<?= array_key_exists($code, $services) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="sv_<?= e($code) ?>"><?= e($label) ?></label>
                    </div>
                    <div class="input-group input-group-sm service-price">
                        <span class="input-group-text">₹</span>
                        <input class="form-control" name="pg_service_price[<?= e($code) ?>]" inputmode="numeric" value="<?= e(isset($services[$code]) ? (string) $services[$code] : '') ?>" placeholder="per month" aria-label="<?= e($label) ?> price per month">
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?= $err('pg_services') ?>
    <?php endif; ?>
    <?php require __DIR__ . '/actions.php'; ?>
</form>
