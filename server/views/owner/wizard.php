<?php
/**
 * Posting form shell: progress indicator + the current step.
 * @var ?array $property @var ?array $type @var ?string $profile @var int $step @var array $steps @var int $reachable @var array $fields
 */
$this->layout($layout ?? 'layouts/owner', ['section' => $section]);
$base ??= '/owner/properties';
$isAdmin ??= false;
$p = $property;
$isDraft = $p === null || $p['status'] === 'draft';
$last = count($steps);
$stepUrl = static fn (int $n): string => url($base . '/' . (int) $p['id'] . '/step/' . $n);

// Value for a field: what the owner just typed (after an error), else what is saved.
$val = static function (string $f, string $default = '') use ($p): string {
    $old = old($f, "\x00");
    if ($old !== "\x00") {
        return $old;
    }
    $v = $p[$f] ?? null;
    return $v === null ? $default : (string) $v;
};
$req = static fn (string $f): bool => ($fields[$f] ?? null) === 'required';
$labelFor = static fn (string $f, string $text): string => e($text) . (($fields[$f] ?? null) === 'required' ? ' <span class="text-danger">*</span>' : ' <span class="text-muted small">(optional)</span>');
$err = static fn (string $f): string => ($m = field_error($f)) !== null ? '<div class="invalid-feedback d-block">' . e($m) . '</div>' : '';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><?= e($title) ?></h1>
    <?php if ($p): ?>
        <span class="d-flex align-items-center gap-2 small text-muted"><?= status_badge($p['status']) ?> #<?= (int) $p['id'] ?></span>
    <?php endif; ?>
</div>

<nav aria-label="Posting steps">
    <ol class="wizard-steps">
        <?php foreach ($steps as $n => $label): ?>
            <?php $state = $n === $step ? 'current' : ($n < $reachable || ($p && !$isDraft) ? 'done' : 'todo'); ?>
            <li class="wizard-step <?= $state ?>"<?= $n === $step ? ' aria-current="step"' : '' ?>>
                <?php if ($p && $n <= $reachable && $n !== $step): ?>
                    <a href="<?= e($stepUrl($n)) ?>"><span class="num"><?= $n ?></span><span class="lbl"><?= e($label) ?></span></a>
                <?php else: ?>
                    <span class="num"><?= $n ?></span><span class="lbl"><?= e($label) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
<div class="d-flex justify-content-between align-items-center small mb-2">
    <strong>Step <?= $step ?> of <?= $last ?> · <?= e($steps[$step]) ?></strong>
    <?php if ($type): ?><span class="text-muted text-end"><?= e(purpose_label($p['purpose']) . ' · ' . $type['category_name'] . ' › ' . $type['name']) ?></span><?php endif; ?>
</div>
<div class="progress mb-3" role="progressbar" aria-label="Form progress" aria-valuenow="<?= $step ?>" aria-valuemin="1" aria-valuemax="<?= $last ?>" style="height:6px">
    <div class="progress-bar" style="width: <?= round($step / $last * 100) ?>%"></div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-3 p-md-4">
        <?php require __DIR__ . '/wizard/step' . $step . '.php'; ?>
    </div>
</div>
