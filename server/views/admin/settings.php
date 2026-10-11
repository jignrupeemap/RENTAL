<?php
/** @var array $grouped @var array $groups @var array $choices @var array $ranges */
$this->layout('layouts/admin');
$oldInput = \App\Core\Session::peekFlash('_old');
$val = static fn (array $r): string => is_array($oldInput) && isset($oldInput['s'][$r['setting_key']]) && is_string($oldInput['s'][$r['setting_key']])
    ? $oldInput['s'][$r['setting_key']] : (string) $r['setting_value'];
?>
<h1 class="h4 mb-1">Settings</h1>
<p class="text-muted small mb-3">Passwords and API keys (database, email, SMS, Google Maps) are not stored here. They are in <code>config/env.php</code> on the server.</p>
<form method="post" action="<?= e(url('/admin/settings')) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
        <?php foreach ($grouped as $group => $rows): ?>
            <div class="col-xl-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold"><?= e($groups[$group] ?? ucfirst($group)) ?></div>
                    <div class="card-body">
                        <?php foreach ($rows as $r): $k = $r['setting_key']; $id = 'set_' . $k; $err = field_error('s.' . $k); ?>
                            <div class="mb-3">
                                <?php if ($r['value_type'] === 'bool'): ?>
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="s[<?= e($k) ?>]" value="0">
                                        <input class="form-check-input" type="checkbox" role="switch" id="<?= e($id) ?>" name="s[<?= e($k) ?>]" value="1"<?= $val($r) === '1' ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="<?= e($id) ?>"><?= e($r['label']) ?></label>
                                    </div>
                                <?php else: ?>
                                    <label class="form-label small mb-1" for="<?= e($id) ?>"><?= e($r['label']) ?></label>
                                    <?php if (isset($choices[$k])): ?>
                                        <select class="form-select form-select-sm<?= $err ? ' is-invalid' : '' ?>" id="<?= e($id) ?>" name="s[<?= e($k) ?>]">
                                            <?php foreach ($choices[$k] as $opt => $label): ?><option value="<?= e($opt) ?>"<?= $val($r) === $opt ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                                        </select>
                                    <?php elseif ($r['value_type'] === 'json'): ?>
                                        <textarea class="form-control form-control-sm font-monospace<?= $err ? ' is-invalid' : '' ?>" id="<?= e($id) ?>" name="s[<?= e($k) ?>]" rows="5"><?= e(json_encode(json_decode($val($r), true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: $val($r)) ?></textarea>
                                    <?php elseif ($r['value_type'] === 'int'): ?>
                                        <input class="form-control form-control-sm<?= $err ? ' is-invalid' : '' ?>" style="max-width:10rem" type="number" id="<?= e($id) ?>" name="s[<?= e($k) ?>]" value="<?= e($val($r)) ?>" min="<?= (int) ($ranges[$k][0] ?? 0) ?>" max="<?= (int) ($ranges[$k][1] ?? 1000000) ?>">
                                    <?php else: ?>
                                        <input class="form-control form-control-sm<?= $err ? ' is-invalid' : '' ?>" id="<?= e($id) ?>" name="s[<?= e($k) ?>]" value="<?= e($val($r)) ?>" maxlength="255"<?= $k === 'support_email' ? ' type="email"' : '' ?>>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($err): ?><div class="invalid-feedback d-block"><?= e($err) ?></div><?php endif; ?>
                                <?php if ($r['description'] && !isset($choices[$k])): ?><div class="form-text"><?= e($r['description']) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="mt-3 text-end"><button class="btn btn-primary" type="submit">Save settings</button></div>
</form>
