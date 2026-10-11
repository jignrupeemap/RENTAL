<?php
/**
 * Step 3: address + map pin. With a Google Maps key the owner searches and
 * drags a pin (assets/js/property-wizard.js keeps the "coordinates" box in
 * sync). Without a key, or if Maps fails to load, they paste coordinates
 * or use their phone's location.
 * @var array $cities @var array $localities @var ?array $location @var string $mapsKey @var string $defaultPrivacy
 */
$text = static function (string $f, string $label, string $attrs = '') use ($val, $labelFor, $err): string {
    return '<label class="form-label" for="f_' . e($f) . '">' . $labelFor($f, $label) . '</label>'
        . '<input class="form-control' . invalid($f) . '" id="f_' . e($f) . '" name="' . e($f) . '" value="' . e($val($f)) . '" ' . $attrs . '>' . $err($f);
};
$cityId = $val('city_id');
$locId = old('locality_id', "\x00");
if ($locId === "\x00") {
    $locId = $p['locality_id'] !== null ? (string) $p['locality_id'] : ($p['locality_name'] !== null ? 'other' : '');
}
$coords = old('coordinates', $location ? rtrim(rtrim($location['latitude'], '0'), '.') . ', ' . rtrim(rtrim($location['longitude'], '0'), '.') : '');
$privacy = old('location_privacy', $location['privacy'] ?? $defaultPrivacy);
?>
<form method="post" action="<?= e($stepUrl(3)) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
        <?php if (isset($fields['unit_no'])): ?><div class="col-sm-6 col-md-4"><?= $text('unit_no', $type['unit_label'], 'maxlength="40"') ?></div><?php endif; ?>
        <?php if (isset($fields['building_name'])): ?><div class="col-sm-6 col-md-8"><?= $text('building_name', 'Building name', 'maxlength="120"') ?></div><?php endif; ?>
        <?php if (isset($fields['project_name'])): ?><div class="col-md-6"><?= $text('project_name', 'Project / society name', 'maxlength="120"') ?></div><?php endif; ?>
        <?php if (isset($fields['landmark'])): ?><div class="col-md-6"><?= $text('landmark', 'Landmark', 'maxlength="150" placeholder="e.g. Near VIP Circle"') ?></div><?php endif; ?>
        <div class="col-12"><?= $text('address_line', 'Address', 'maxlength="255" placeholder="Street / road and area"') ?></div>

        <div class="col-sm-6 col-md-4">
            <label class="form-label" for="f_city_id"><?= $labelFor('city_id', 'City') ?></label>
            <select class="form-select<?= invalid('city_id') ?>" id="f_city_id" name="city_id">
                <option value="">Choose city</option>
                <?php foreach ($cities as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= $cityId === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $err('city_id') ?>
        </div>
        <div class="col-sm-6 col-md-4">
            <label class="form-label" for="f_locality_id"><?= $labelFor('locality_id', 'Locality / area') ?></label>
            <select class="form-select<?= invalid('locality_id') ?>" id="f_locality_id" name="locality_id">
                <option value="">Choose locality</option>
                <?php foreach ($cities as $c): ?>
                    <optgroup label="<?= e($c['name']) ?>" data-city="<?= (int) $c['id'] ?>">
                        <?php foreach ($localities as $l): if ((int) $l['city_id'] !== (int) $c['id']) continue; ?>
                            <option value="<?= (int) $l['id'] ?>" data-pincode="<?= e((string) $l['pincode']) ?>"<?= $locId === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
                <option value="other"<?= $locId === 'other' ? ' selected' : '' ?>>My area is not listed</option>
            </select>
            <?= $err('locality_id') ?>
        </div>
        <div class="col-sm-6 col-md-4">
            <?= $text('pincode', 'Pincode', 'inputmode="numeric" maxlength="6" pattern="[1-9][0-9]{5}"') ?>
        </div>
        <div class="col-sm-6 col-md-8" data-locality-other<?= $locId === 'other' ? '' : ' hidden' ?>>
            <label class="form-label" for="f_locality_name">Your area name</label>
            <input class="form-control" id="f_locality_name" name="locality_name" maxlength="100" value="<?= e($val('locality_name')) ?>" placeholder="e.g. Bhatar">
        </div>
    </div>

    <h2 class="h6 mt-4 mb-2"><?= $labelFor('map', 'Location on map') ?></h2>
    <div class="map-block" data-maps-key="<?= e($mapsKey) ?>">
        <?php if ($mapsKey !== ''): ?>
            <div class="mb-2" data-map-search hidden>
                <label class="form-label small mb-1" for="placeSearch">Search for the building, street or area</label>
                <div id="placeSearch"></div>
            </div>
            <div id="propertyMap" class="property-map mb-2" hidden aria-label="Map. Drag the pin to the exact spot."></div>
            <p class="small text-muted mb-2" data-map-help hidden>Drag the pin to the exact spot of the property.</p>
        <?php endif; ?>
        <details class="mb-2"<?= $mapsKey === '' || field_error('map') ? ' open' : '' ?> data-coords-box>
            <summary class="small"><?= $mapsKey === '' ? 'Location coordinates' : 'Enter coordinates instead' ?></summary>
            <div class="pt-2">
                <div class="input-group coords-group">
                    <input class="form-control<?= invalid('map') ?>" id="f_coordinates" name="coordinates" value="<?= e($coords) ?>" placeholder="e.g. 21.1702, 72.8311" inputmode="decimal" autocomplete="off">
                    <button class="btn btn-outline-secondary" type="button" data-use-my-location>Use my current location</button>
                </div>
                <div class="form-text">On Google Maps, long-press (phone) or right-click (computer) the property, then copy the numbers shown at the top, like 21.1702, 72.8311.</div>
            </div>
        </details>
        <?= $err('map') ?>
        <input type="hidden" name="formatted_address" value="<?= e(old('formatted_address', (string) ($location['formatted_address'] ?? ''))) ?>" data-formatted-address>
        <input type="hidden" name="place_id" value="<?= e(old('place_id', (string) ($location['google_place_id'] ?? ''))) ?>" data-place-id>

        <div class="mt-3">
            <div class="form-label mb-1">Show on the public map</div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="location_privacy" id="priv_approx" value="approximate"<?= $privacy !== 'exact' ? ' checked' : '' ?>>
                <label class="form-check-label" for="priv_approx">Approximate area <span class="text-muted small">(recommended; the pin is moved a few hundred metres)</span></label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="location_privacy" id="priv_exact" value="exact"<?= $privacy === 'exact' ? ' checked' : '' ?>>
                <label class="form-check-label" for="priv_exact">Exact location</label>
            </div>
        </div>
    </div>
    <?php require __DIR__ . '/actions.php'; ?>
</form>
