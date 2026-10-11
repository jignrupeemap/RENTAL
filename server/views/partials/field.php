<?php
/**
 * Form field. Variables: $name, $label, $type='text', $value='', $attrs='', $help=''
 * Password fields get a show/hide button (public/assets/js/app.js).
 */
$type ??= 'text';
$attrs ??= '';
$help ??= '';
$value = $type === 'password' ? '' : ($value ?? old($name));
$err = field_error($name);
$id = 'f_' . $name;
?>
<div class="mb-3">
    <label class="form-label" for="<?= e($id) ?>"><?= e($label) ?></label>
    <?php if ($type === 'password'): ?>
        <div class="input-group has-validation">
            <input class="form-control<?= invalid($name) ?>" type="password" id="<?= e($id) ?>" name="<?= e($name) ?>" <?= $attrs ?>>
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="<?= e($id) ?>" aria-label="Show password">Show</button>
            <?php if ($err): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
        </div>
    <?php else: ?>
        <input class="form-control<?= invalid($name) ?>" type="<?= e($type) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" <?= $attrs ?>>
        <?php if ($err): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
    <?php endif; ?>
    <?php if ($help): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
</div>
