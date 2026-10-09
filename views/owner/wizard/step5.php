<?php
/** Step 5: rent and charges. @var ?array $pendingRent @var string $rentMode */
$published = $p['published_at'] !== null;
// While an increase waits for approval, the box shows the requested rent.
// Admins see the live rent: what they save goes live at once.
$values = ['rent' => old('rent', "\x00") === "\x00" && $pendingRent && !$isAdmin ? (string) $pendingRent['new_rent'] : $val('rent')];
$money = static function (string $f, string $label, string $help = '') use ($val, $labelFor, $err, $values): string {
    return '<label class="form-label" for="f_' . e($f) . '">' . $labelFor($f, $label) . '</label>'
        . '<div class="input-group"><span class="input-group-text">₹</span>'
        . '<input class="form-control' . invalid($f) . '" id="f_' . e($f) . '" name="' . e($f) . '" inputmode="numeric" value="' . e($values[$f] ?? $val($f)) . '"></div>'
        . $err($f) . ($help !== '' ? '<div class="form-text">' . e($help) . '</div>' : '');
};
?>
<form method="post" action="<?= e($stepUrl(5)) ?>">
    <?= csrf_field() ?>
    <?php if ($isAdmin): ?>
        <div class="alert alert-light border small">
            Rent you save here goes live at once and is recorded in the rental change history.
            <?php if ($pendingRent): ?>
                The owner has asked for <strong><?= e(money_inr((int) $pendingRent['new_rent'])) ?></strong>:
                <a href="<?= e(url('/admin/rent-changes/' . (int) $pendingRent['id'])) ?>">review that request</a>.
            <?php endif; ?>
        </div>
    <?php elseif ($pendingRent): ?>
        <div class="alert alert-warning small">
            Rent change to <strong><?= e(money_inr((int) $pendingRent['new_rent'])) ?></strong> is waiting for admin approval
            (sent <?= e(format_ist($pendingRent['created_at'])) ?>). <?= e(money_inr((int) $p['rent'])) ?> stays live until then.
            Enter the current rent again to withdraw the change.
        </div>
    <?php elseif ($published && $rentMode !== 'none'): ?>
        <div class="alert alert-light border small">
            <?= $rentMode === 'all' ? 'Rent changes go live after the admin approves them.' : 'A rent increase goes live after the admin approves it. A decrease goes live at once.' ?>
        </div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-md-6"><?= $money('rent', $p['purpose'] === 'lease' ? 'Monthly rent / lease amount' : 'Monthly rent', '') ?></div>
        <?php if (isset($fields['security_deposit'])): ?><div class="col-md-6"><?= $money('security_deposit', 'Security deposit') ?></div><?php endif; ?>
        <?php if (isset($fields['maintenance'])): ?><div class="col-md-6"><?= $money('maintenance', 'Maintenance per month') ?></div><?php endif; ?>
        <?php if (isset($fields['other_charges'])): ?>
            <div class="col-md-6">
                <label class="form-label" for="f_other_charges"><?= $labelFor('other_charges', 'Other charges') ?></label>
                <input class="form-control<?= invalid('other_charges') ?>" id="f_other_charges" name="other_charges" maxlength="255" value="<?= e($val('other_charges')) ?>" placeholder="e.g. Electricity as per meter">
                <?= $err('other_charges') ?>
            </div>
        <?php endif; ?>
    </div>
    <?php require __DIR__ . '/actions.php'; ?>
</form>
