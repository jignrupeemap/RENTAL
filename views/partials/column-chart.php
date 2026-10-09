<?php
/**
 * Column chart drawn with plain HTML and CSS (no chart library, nothing to
 * download). Hovering a column shows its value; "Show numbers" lists every
 * value for touch screens and screen readers.
 *
 * @var array $chart ['label' => string, 'points' => list<array{0: string, 1: int, 2: string}>] [short label, value, full label]
 */
$pts = $chart['points'];
$values = array_column($pts, 1);
$max = max(1, ...($values ?: [0]));
$sum = array_sum($values);
$step = max(1, (int) ceil(count($pts) / 6));
?>
<figure class="col-chart mb-0" data-chart="<?= e($chart['key'] ?? '') ?>">
    <div class="d-flex justify-content-between align-items-baseline">
        <figcaption class="fw-semibold small"><?= e($chart['label']) ?></figcaption>
        <span class="small text-muted"><?= e(number_format($sum)) ?> total</span>
    </div>
    <div class="col-chart-scale small text-muted"><?= e(number_format($max)) ?></div>
    <div class="col-chart-bars" role="img" aria-label="<?= e($chart['label'] . ', ' . number_format($sum) . ' in total, highest ' . number_format($max)) ?>">
        <?php foreach ($pts as [$short, $v, $full]): ?>
            <span class="col-chart-bar<?= $v === 0 ? ' is-zero' : '' ?>" style="height: <?= $v === 0 ? 0 : max(2, round($v / $max * 100, 1)) ?>%" title="<?= e($full . ': ' . number_format($v)) ?>"></span>
        <?php endforeach; ?>
    </div>
    <div class="col-chart-axis text-muted" aria-hidden="true">
        <?php foreach ($pts as $i => [$short]): ?><span><?= $i % $step === 0 ? e($short) : '' ?></span><?php endforeach; ?>
    </div>
    <details class="small mt-1">
        <summary class="text-muted">Show numbers</summary>
        <table class="table table-sm small mb-0 mt-1">
            <tbody>
            <?php foreach ($pts as [$short, $v, $full]): ?>
                <tr><td><?= e($full) ?></td><td class="text-end"><?= e(number_format($v)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
</figure>
