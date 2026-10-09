<?php
/**
 * Step 1: Rent / Lease / PG → category → sub-type. Without JavaScript every
 * type is listed under its category; with it, only matching types show.
 * @var array $categories @var array $types
 */
if ($p && !$isDraft): ?>
    <p class="mb-2"><strong><?= e(purpose_label($p['purpose'])) ?></strong> · <?= e($type['category_name'] . ' › ' . $type['name']) ?></p>
    <p class="text-muted small mb-0">The property type cannot be changed after the listing has been submitted. If it is wrong, please contact support.</p>
    <div class="border-top pt-3 mt-4 text-end"><a class="btn btn-primary" href="<?= e($stepUrl(2)) ?>">Next &rarr;</a></div>
<?php return; endif;

$purpose = $val('purpose', 'rent');
$typeId = (int) $val('type_id', '0');
$catId = (int) $val('category_id', (string) ($p['category_id'] ?? $categories[0]['id']));
?>
<?php if (!$p && isset($limit) && !$limit['unlimited']): ?>
    <p class="small text-muted">You can post <?= e(plural((int) $limit['remaining'], 'more property', 'more properties')) ?> (<?= (int) $limit['used'] ?> / <?= (int) $limit['limit'] ?> used).</p>
<?php endif; ?>
<form method="post" action="<?= e($p ? $stepUrl(1) : url($base . '/new')) ?>" id="typeForm">
    <?= csrf_field() ?>
    <?php if ($isAdmin && !$p): $assign = old('assign', 'system'); ?>
        <fieldset class="mb-4">
            <legend class="form-label fw-semibold fs-6">Listing belongs to <span class="text-danger">*</span></legend>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="assign" id="assign_system" value="system"<?= $assign !== 'user' ? ' checked' : '' ?>>
                <label class="form-check-label" for="assign_system">Admin / system (no owner account)</label>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="assign" id="assign_user" value="user"<?= $assign === 'user' ? ' checked' : '' ?>>
                <label class="form-check-label" for="assign_user">An existing account</label>
            </div>
            <label class="form-label small" for="f_owner">Owner's mobile number or email</label>
            <input class="form-control<?= invalid('owner') ?>" id="f_owner" name="owner" value="<?= e(old('owner', (string) ($ownerPrefill ?? ''))) ?>" maxlength="191" style="max-width:22rem">
            <?= $err('owner') ?>
            <div class="form-text">The owner's posting limit does not apply to listings you add.</div>
        </fieldset>
    <?php endif; ?>
    <fieldset class="mb-4">
        <legend class="form-label fw-semibold fs-6">I want to list my property for <span class="text-danger">*</span></legend>
        <div class="choice-row" role="radiogroup">
            <?php foreach (['rent' => 'Rent', 'lease' => 'Lease', 'pg' => 'PG'] as $k => $l): ?>
                <input class="btn-check" type="radio" name="purpose" id="purpose_<?= $k ?>" value="<?= $k ?>"<?= $purpose === $k ? ' checked' : '' ?>>
                <label class="btn btn-outline-primary choice" for="purpose_<?= $k ?>"><?= $l ?></label>
            <?php endforeach; ?>
        </div>
        <?= $err('purpose') ?>
        <div class="form-text" data-pg-note<?= $purpose === 'pg' ? '' : ' hidden' ?>>PG can be listed for a Flat or a Bungalow.</div>
    </fieldset>

    <fieldset class="mb-4" data-category-group>
        <legend class="form-label fw-semibold fs-6">Category <span class="text-danger">*</span></legend>
        <div class="choice-row" role="radiogroup">
            <?php foreach ($categories as $c): ?>
                <input class="btn-check" type="radio" name="category_id" id="cat_<?= (int) $c['id'] ?>" value="<?= (int) $c['id'] ?>" data-code="<?= e($c['code']) ?>"<?= $catId === (int) $c['id'] ? ' checked' : '' ?>>
                <label class="btn btn-outline-primary choice" for="cat_<?= (int) $c['id'] ?>"><?= e($c['name']) ?></label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <fieldset class="mb-2">
        <legend class="form-label fw-semibold fs-6">Property type <span class="text-danger">*</span></legend>
        <?php foreach ($categories as $c): ?>
            <div class="type-group mb-3" data-category="<?= (int) $c['id'] ?>">
                <div class="small text-muted mb-1 type-group-title"><?= e($c['name']) ?></div>
                <div class="choice-grid">
                    <?php foreach ($types as $t): if ((int) $t['category_id'] !== (int) $c['id']) continue; ?>
                        <input class="btn-check" type="radio" name="type_id" id="type_<?= (int) $t['id'] ?>" value="<?= (int) $t['id'] ?>" data-pg="<?= (int) $t['allows_pg'] ?>"<?= $typeId === (int) $t['id'] ? ' checked' : '' ?>>
                        <label class="btn btn-outline-secondary choice" for="type_<?= (int) $t['id'] ?>"><?= e($t['name']) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?= $err('type_id') ?>
    </fieldset>
    <?php if ($p): ?>
        <p class="small text-muted mb-0">Changing the type clears details that do not apply to the new type.</p>
    <?php endif; ?>
    <?php require __DIR__ . '/actions.php'; ?>
</form>
