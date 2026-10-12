<?php
/**
 * Listing body, shared by the owner's preview (Phase 4) and the public
 * detail page (Phase 7).
 * @var array $preview  keys: p, type, profile, city, locality, amenities, services, location, pendingRent, photos, video
 * @var ?string $editBase  when set, each section gets an Edit link to {$editBase}{step}
 * $preview['public'] (detail page): no unit number, and the street line only when the owner chose an exact pin.
 * $preview['hideTitle']: the page prints its own H1.
 */
use App\Services\PropertyForm;

$p = $preview['p'];
$t = $preview['type'];
$opt = static fn (string $k, $v): ?string => $v === null ? null : (PropertyForm::options($k)[$v] ?? null);
$edit = static fn (int $step): string => isset($editBase) ? ' <a class="small fw-normal ms-2" href="' . e($editBase . $step) . '">Edit</a>' : '';
$area = $p['area_value'] !== null ? rtrim(rtrim((string) $p['area_value'], '0'), '.') . ' ' . ($opt('area_unit', $p['area_unit']) ?? '') : null;
$facts = array_filter([
    'Bedrooms'       => $opt('bedrooms', $p['bedrooms']),
    'Bathrooms'      => $opt('bathrooms', $p['bathrooms']),
    'Balconies'      => $opt('balconies', $p['balconies']),
    'Area'           => $area,
    'Furnishing'     => $opt('furnishing', $p['furnishing']),
    'Available from' => $p['available_from'] ? date('d M Y', strtotime($p['available_from'])) : null,
    'Available for'  => $opt('pg_available_for', $p['pg_available_for']),
    'Suitable for'   => $opt('pg_suitable_for', $p['pg_suitable_for']),
], static fn ($v) => $v !== null && $v !== '');
$place = implode(', ', array_filter([$preview['locality'], $preview['city']]));
$loc = $preview['location'];
$public = !empty($preview['public']);
$showStreet = !$public || ($loc['privacy'] ?? '') === 'exact';
?>
<article class="listing-detail">
    <?php $photos = $preview['photos'] ?? []; $alt = $p['title'] !== '' ? $p['title'] : $t['name']; ?>
    <?php if ($photos): ?>
        <div class="listing-gallery mb-3">
            <div class="gallery-main rounded-3 overflow-hidden">
                <?= media_picture($photos[0], '(max-width: 992px) 100vw, 800px', $alt, false, 'w-100 h-auto', 'lg') ?>
            </div>
            <?php if (count($photos) > 1): ?>
                <div class="gallery-thumbs mt-2">
                    <?php foreach (array_slice($photos, 1) as $i => $m): ?>
                        <a href="<?= e(media_url($m, 'lg')) ?>" target="_blank" rel="noopener" class="rounded-2 overflow-hidden">
                            <?= media_picture($m, '160px', $alt . ' photo ' . ($i + 2), true, '', 'thumb') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (isset($editBase)): ?><div class="small mt-1"><a href="<?= e($editBase . 6) ?>">Edit photos</a></div><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="listing-photo-placeholder rounded-3 mb-3">
            <span class="small text-muted"><?= $public ? 'No photos' : 'No photos yet' ?><?= isset($editBase) ? ' · <a href="' . e($editBase . 6) . '">Add photos</a>' : '' ?></span>
        </div>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mb-1">
        <span class="badge text-bg-light border">For <?= e(purpose_label($p['purpose'])) ?></span>
        <span class="badge text-bg-light border"><?= e($t['category_name'] . ' › ' . $t['name']) ?></span>
    </div>
    <?php if (empty($preview['hideTitle'])): ?>
        <h2 class="h4 mb-1"><?= e($p['title'] !== '' ? $p['title'] : 'Untitled listing') ?><?= $edit(2) ?></h2>
    <?php endif; ?>
    <div class="text-muted mb-3"><?= e($place !== '' ? $place : 'Location not set') ?></div>

    <?php if (!$public): /* the public page shows rent in its action card */ ?>
    <div class="price-box rounded-3 p-3 mb-4">
        <div class="d-flex flex-wrap align-items-baseline gap-2">
            <span class="fs-4 fw-bold"><?= e(money_inr($p['rent'] !== null ? (int) $p['rent'] : null)) ?></span><span class="text-muted">/ month</span>
            <?= $edit(5) ?>
        </div>
        <?php if ($preview['pendingRent'] !== null): ?>
            <div class="small text-warning-emphasis">New rent <?= e(money_inr($preview['pendingRent'])) ?> waiting for admin approval</div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-3 small mt-1">
            <?php if ($p['security_deposit'] !== null): ?><span>Deposit <strong><?= e(money_inr((int) $p['security_deposit'])) ?></strong></span><?php endif; ?>
            <?php if ($p['maintenance'] !== null): ?><span>Maintenance <strong><?= e(money_inr((int) $p['maintenance'])) ?></strong> / month</span><?php endif; ?>
            <?php if ($p['other_charges'] !== null): ?><span>Other: <?= e($p['other_charges']) ?></span><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <h3 class="h6">Details<?= $edit(2) ?></h3>
    <?php if ($facts): ?>
        <dl class="facts-grid mb-4">
            <?php foreach ($facts as $k => $v): ?>
                <div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    <?php else: ?><p class="small text-muted">Not filled in yet.</p><?php endif; ?>

    <?php if ($p['description']): ?>
        <h3 class="h6">About this property</h3>
        <p class="mb-4 text-break"><?= nl2br(e($p['description'])) ?></p>
    <?php endif; ?>

    <h3 class="h6">Facilities<?= $edit(4) ?></h3>
    <?php if ($preview['amenities']): ?>
        <ul class="chip-list mb-3">
            <?php foreach ($preview['amenities'] as $a): ?><li><?= e($a) ?></li><?php endforeach; ?>
        </ul>
    <?php else: ?><p class="small text-muted">None selected.</p><?php endif; ?>
    <?php if ($preview['profile'] === 'pg' && $preview['services']): ?>
        <div class="small mb-4"><span class="fw-semibold">Extra services:</span>
            <?= e(implode(', ', array_map(static fn ($code, $price) => (PropertyForm::options('pg_services')[$code] ?? $code) . ($price !== null ? ' (' . money_inr((int) $price) . '/month)' : ''), array_keys($preview['services']), $preview['services']))) ?>
        </div>
    <?php endif; ?>

    <h3 class="h6 mt-3">Location<?= $edit(3) ?></h3>
    <address class="mb-2">
        <?= e(implode(', ', array_filter([
            $p['unit_no'] !== null && !$public ? $t['unit_label'] . ' ' . $p['unit_no'] : null,
            $p['building_name'], $p['project_name'], $showStreet ? $p['address_line'] : null,
        ]))) ?>
        <?php if ($p['landmark']): ?><br><span class="text-muted">Landmark: <?= e($p['landmark']) ?></span><?php endif; ?>
        <br><?= e(implode(', ', array_filter([$place, $p['pincode']]))) ?>
    </address>
    <?php if ($loc): ?>
        <?php if (($mapsKey = maps_browser_key()) !== ''): ?>
            <div class="property-map property-map-sm mb-2" data-property-map data-maps-key="<?= e($mapsKey) ?>"
                 data-lat="<?= e((string) $loc['public_latitude']) ?>" data-lng="<?= e((string) $loc['public_longitude']) ?>"
                 data-exact="<?= $loc['privacy'] === 'exact' ? '1' : '0' ?>" role="region" aria-label="Map of the property area"></div>
            <script src="<?= e(asset('js/property-map.js')) ?>" defer></script>
        <?php endif; ?>
        <?php if ($public):
            // Visitors never get a map link: the button shows who to call or
            // WhatsApp for the exact location (settings → support_phone).
            $phone = preg_replace('/\D+/', '', (string) App\Core\Settings::get('support_phone', ''));
            $phone = strlen($phone) === 12 && str_starts_with($phone, '91') ? substr($phone, 2) : $phone;
            $waText = 'Hi, I would like the location and more details of property ID ' . (int) $p['id'] . ' on ' . (string) App\Core\Settings::get('site_name', 'Homeforrent') . '.';
        ?>
            <button class="btn btn-sm btn-outline-primary mb-1" type="button" data-bs-toggle="collapse" data-bs-target="#locationContact" aria-expanded="false" aria-controls="locationContact">VIEW ON GOOGLE MAPS</button>
            <div class="collapse" id="locationContact">
                <div class="alert alert-info small py-2 px-3 mb-2">
                    <?php if ($phone !== ''): ?>
                        Contact/whatsapp on <a class="fw-semibold" href="tel:+91<?= e($phone) ?>"><?= e($phone) ?></a> for location and more detail.
                        <a class="d-inline-block ms-1" href="https://wa.me/91<?= e($phone) ?>?text=<?= e(rawurlencode($waText)) ?>" target="_blank" rel="noopener">Open WhatsApp</a>
                    <?php else: ?>
                        Use <strong>Contact Owner</strong> for the location and more detail.
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <a class="btn btn-sm btn-outline-primary mb-1" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&amp;query=<?= e($loc['public_latitude'] . ',' . $loc['public_longitude']) ?>">VIEW ON GOOGLE MAPS</a>
        <?php endif; ?>
        <div class="small text-muted mb-4"><?= $loc['privacy'] === 'exact' ? 'Exact location shown.' : 'Approximate area shown (a few hundred metres from the property).' ?></div>
    <?php else: ?><p class="small text-muted mb-4">Map pin not set.</p><?php endif; ?>

    <?php if (!empty($preview['video']) || $p['video_url']): ?>
        <h3 class="h6">Video<?= $edit(6) ?></h3>
        <?php if (!empty($preview['video'])): ?>
            <video class="w-100 rounded-3 bg-dark mb-2" controls preload="none" style="max-height:420px"<?= $photos ? ' poster="' . e(media_url($photos[0], 'md')) . '"' : '' ?>>
                <source src="<?= e(video_url($preview['video'])) ?>" type="<?= e($preview['video']['mime_type']) ?>">
            </video>
        <?php endif; ?>
        <?php if ($p['video_url']): ?>
            <p class="mb-4"><a href="<?= e($p['video_url']) ?>" target="_blank" rel="noopener">Watch the video on YouTube</a></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (isset($editBase)): ?>
        <h3 class="h6">Contact details<?= $edit(7) ?></h3>
        <p class="small mb-0">
            <?= e($p['contact_name'] ?? 'Not set') ?>
            <?php foreach (['contact_mobile_1', 'contact_mobile_2'] as $m): if ($p[$m]): ?> · <?= e(format_mobile($p[$m])) ?><?php endif; endforeach; ?>
            <?php if ($p['contact_email']): ?> · <?= e($p['contact_email']) ?><?php endif; ?>
        </p>
    <?php endif; ?>
</article>
