<?php
/**
 * Horizontal bars for a breakdown (plain HTML/CSS). The bar length is the
 * share of the largest value; the number is always printed beside it.
 *
 * @var list<array{0: string, 1: int, 2?: ?string}> $bars [label, value, optional link]
 */
$max = max(1, ...(array_column($bars, 1) ?: [0]));
?>
<ul class="list-unstyled bar-list mb-0">
    <?php foreach ($bars as $b): ?>
        <li class="mb-2">
            <div class="d-flex justify-content-between small">
                <?php if (!empty($b[2])): ?><a href="<?= e(url($b[2])) ?>"><?= e($b[0]) ?></a><?php else: ?><span><?= e($b[0]) ?></span><?php endif; ?>
                <span class="fw-semibold"><?= e(number_format((int) $b[1])) ?></span>
            </div>
            <div class="bar-list-track" aria-hidden="true"><div class="bar-list-fill" style="width: <?= round((int) $b[1] / $max * 100, 1) ?>%"></div></div>
        </li>
    <?php endforeach; ?>
    <?php if (!$bars): ?><li class="text-muted small">Nothing yet.</li><?php endif; ?>
</ul>
