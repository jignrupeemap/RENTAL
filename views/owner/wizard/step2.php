<?php
/** Step 2: details. Only the fields the map lists for this type are shown. @var array $fields */
$radios = static function (string $name, array $options, string $current): string {
    $h = '<div class="choice-row" role="radiogroup">';
    foreach ($options as $k => $l) {
        $id = $name . '_' . $k;
        $h .= '<input class="btn-check" type="radio" name="' . e($name) . '" id="' . e($id) . '" value="' . e((string) $k) . '"' . ((string) $k === $current ? ' checked' : '') . '>'
            . '<label class="btn btn-outline-secondary choice" for="' . e($id) . '">' . e($l) . '</label>';
    }
    return $h . '</div>';
};
$opts = static fn (string $k): array => \App\Services\PropertyForm::options($k);
$area = $val('area_value');
if (is_numeric($area)) {
    $area = rtrim(rtrim($area, '0'), '.');
}
$suggest = trim(($fields['bedrooms'] ?? null) ? 'e.g. 2 BHK ' . $type['name'] . ' in Vesu' : 'e.g. ' . $type['name'] . ' on Ring Road, 1,200 sq ft');
?>
<form method="post" action="<?= e($stepUrl(2)) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="f_title"><?= $labelFor('title', 'Listing title') ?></label>
        <input class="form-control<?= invalid('title') ?>" id="f_title" name="title" maxlength="150" value="<?= e($val('title')) ?>" placeholder="<?= e($suggest) ?>">
        <?= $err('title') ?>
        <div class="form-text">A short headline buyers see first.</div>
    </div>

    <div class="row g-3">
        <?php foreach (['bedrooms' => 'Bedrooms', 'bathrooms' => 'Bathrooms', 'balconies' => 'Balconies'] as $f => $l): if (!isset($fields[$f])) continue; ?>
            <div class="col-12">
                <div class="form-label mb-1"><?= $labelFor($f, $l) ?></div>
                <?= $radios($f, $opts($f), $val($f)) ?>
                <?= $err($f) ?>
            </div>
        <?php endforeach; ?>

        <?php if (isset($fields['area_value'])): ?>
            <div class="col-md-6">
                <label class="form-label" for="f_area"><?= $labelFor('area_value', 'Area') ?></label>
                <div class="input-group">
                    <input class="form-control<?= invalid('area_value') ?>" id="f_area" name="area_value" inputmode="decimal" value="<?= e($area) ?>" placeholder="e.g. 1200">
                    <select class="form-select flex-grow-0 w-auto" name="area_unit" aria-label="Area unit">
                        <?php foreach ($opts('area_unit') as $k => $l): ?>
                            <option value="<?= e($k) ?>"<?= $val('area_unit', 'sqft') === $k ? ' selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?= $err('area_value') ?>
            </div>
        <?php endif; ?>

        <?php if (isset($fields['furnishing'])): ?>
            <div class="col-12">
                <div class="form-label mb-1"><?= $labelFor('furnishing', 'Furnishing') ?></div>
                <?= $radios('furnishing', $opts('furnishing'), $val('furnishing')) ?>
                <?= $err('furnishing') ?>
            </div>
        <?php endif; ?>

        <?php if (isset($fields['pg_available_for'])): ?>
            <div class="col-md-6">
                <div class="form-label mb-1"><?= $labelFor('pg_available_for', 'Available for') ?></div>
                <?= $radios('pg_available_for', $opts('pg_available_for'), $val('pg_available_for')) ?>
                <?= $err('pg_available_for') ?>
            </div>
            <div class="col-md-6">
                <div class="form-label mb-1"><?= $labelFor('pg_suitable_for', 'Suitable for') ?></div>
                <?= $radios('pg_suitable_for', $opts('pg_suitable_for'), $val('pg_suitable_for')) ?>
                <?= $err('pg_suitable_for') ?>
            </div>
        <?php endif; ?>

        <?php if (isset($fields['available_from'])): ?>
            <div class="col-md-6">
                <label class="form-label" for="f_from"><?= $labelFor('available_from', 'Available from') ?></label>
                <input class="form-control<?= invalid('available_from') ?>" type="date" id="f_from" name="available_from" value="<?= e($val('available_from')) ?>">
                <?= $err('available_from') ?>
            </div>
        <?php endif; ?>

        <div class="col-12">
            <label class="form-label" for="f_desc"><?= $labelFor('description', 'Description') ?></label>
            <textarea class="form-control<?= invalid('description') ?>" id="f_desc" name="description" rows="5" maxlength="5000" placeholder="What makes it a good place: nearby schools, shops, transport, rules, etc."><?= e($val('description')) ?></textarea>
            <?= $err('description') ?>
        </div>
    </div>
    <?php require __DIR__ . '/actions.php'; ?>
</form>
