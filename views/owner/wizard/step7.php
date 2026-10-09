<?php
/** Step 7: contact shown to people who enquire. Pre-filled from the account. @var array $user */
$empty = $p['contact_name'] === null && $p['contact_mobile_1'] === null;
$pre = static function (string $f, string $fromAccount) use ($val, $empty): string {
    $v = $val($f);
    return $v === '' && $empty ? $fromAccount : $v;
};
$mob = static fn (?string $m): string => $m !== null && strlen($m) === 12 ? substr($m, 2) : (string) $m;
$fields_ = [
    ['contact_name', 'Contact name', $pre('contact_name', trim($user['first_name'] . ' ' . $user['last_name'])), 'text', 'maxlength="120" autocomplete="name"'],
    ['contact_mobile_1', 'Mobile number', $mob($pre('contact_mobile_1', (string) $user['mobile'])), 'tel', 'inputmode="numeric" autocomplete="tel"'],
    ['contact_mobile_2', 'Second mobile number', $mob($val('contact_mobile_2')), 'tel', 'inputmode="numeric"'],
    ['contact_email', 'Email', $pre('contact_email', (string) ($user['email'] ?? '')), 'email', 'autocomplete="email"'],
];
?>
<form method="post" action="<?= e($stepUrl(7)) ?>">
    <?= csrf_field() ?>
    <p class="small text-muted">People who enquire about this property will contact you on these details.</p>
    <div class="row g-3">
        <?php foreach ($fields_ as [$f, $label, $value, $inputType, $attrs]): if (!isset($fields[$f])) continue; ?>
            <div class="col-md-6">
                <label class="form-label" for="f_<?= e($f) ?>"><?= $labelFor($f, $label) ?></label>
                <?php if ($inputType === 'tel'): ?>
                    <div class="input-group"><span class="input-group-text">+91</span>
                        <input class="form-control<?= invalid($f) ?>" id="f_<?= e($f) ?>" name="<?= e($f) ?>" type="tel" value="<?= e($value) ?>" <?= $attrs ?>>
                    </div>
                <?php else: ?>
                    <input class="form-control<?= invalid($f) ?>" id="f_<?= e($f) ?>" name="<?= e($f) ?>" type="<?= e($inputType) ?>" value="<?= e($value) ?>" <?= $attrs ?>>
                <?php endif; ?>
                <?= $err($f) ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php require __DIR__ . '/actions.php'; ?>
</form>
