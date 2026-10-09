<?php
/** Date range buttons (Today / This week / This month / Custom, India dates). @var string $range @var string $fromDate @var string $toDate @var string $rangeAction */
?>
<form class="d-flex flex-wrap gap-2 align-items-end" method="get" action="<?= e(url($rangeAction)) ?>">
    <div class="btn-group btn-group-sm" role="group" aria-label="Date range">
        <?php foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month'] as $k => $l): ?>
            <a class="btn btn-outline-primary<?= $range === $k ? ' active' : '' ?>" href="<?= e(url($rangeAction . '?range=' . $k)) ?>"><?= $l ?></a>
        <?php endforeach; ?>
    </div>
    <input type="hidden" name="range" value="custom">
    <input class="form-control form-control-sm" type="date" name="from" value="<?= e($fromDate) ?>" aria-label="From" style="width:9.5rem">
    <input class="form-control form-control-sm" type="date" name="to" value="<?= e($toDate) ?>" aria-label="To" style="width:9.5rem">
    <button class="btn btn-sm btn-<?= $range === 'custom' ? '' : 'outline-' ?>secondary" type="submit">Custom</button>
</form>
