<?php
/** @var string $purpose @var array $purposes @var array $rows */
$this->layout('layouts/admin');
?>
<h1 class="h4 mb-1">Display order</h1>
<p class="text-muted small mb-3">Priority 1 appears first on the <?= e($purposes[$purpose]) ?> page. Listings without a priority follow, newest first. Featured listings are shown above the rest.</p>

<ul class="nav nav-pills mb-3">
    <?php foreach ($purposes as $k => $label): ?>
        <li class="nav-item"><a class="nav-link<?= $k === $purpose ? ' active' : '' ?>" href="<?= e(url('/admin/order?purpose=' . $k)) ?>"><?= e($label) ?> properties</a></li>
    <?php endforeach; ?>
</ul>

<form method="post" action="<?= e(url('/admin/order')) ?>" class="card border-0 shadow-sm">
    <?= csrf_field() ?>
    <input type="hidden" name="purpose" value="<?= e($purpose) ?>">
    <?php /* Enter in a priority box saves the order instead of pressing the first arrow. */ ?>
    <button class="visually-hidden" type="submit" name="save" value="1" tabindex="-1" aria-hidden="true">Save</button>
    <div class="table-responsive">
        <table class="table mb-0 admin-table small">
            <thead class="table-light"><tr><th style="width:3rem">#</th><th>Listing</th><th class="text-end">Rent</th><th>Priority</th><th class="text-end">Move</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): $ranked = (int) $r['display_rank'] < 1000000; ?>
                <tr class="order-row">
                    <td class="text-muted"><?= $i + 1 ?></td>
                    <td>
                        <a href="<?= e(url('/admin/properties/' . (int) $r['id'])) ?>"><?= e($r['title']) ?></a>
                        <?php if ($r['is_featured']): ?><span class="badge text-bg-warning ms-1">Featured<?= $r['featured_until'] ? ' until ' . e(format_ist($r['featured_until'], 'd M')) : '' ?></span><?php endif; ?>
                        <div class="text-muted">#<?= (int) $r['id'] ?> · <?= e($r['type_name']) ?> · <?= e(trim(($r['locality_name'] ?? '') . ', ' . ($r['city_name'] ?? ''), ', ')) ?></div>
                    </td>
                    <td class="text-end text-nowrap"><?= e(money_inr((int) $r['rent'])) ?></td>
                    <td><input class="form-control form-control-sm rank-input" type="number" min="1" step="1" name="rank[<?= (int) $r['id'] ?>]" value="<?= $ranked ? (int) $r['display_rank'] : '' ?>" aria-label="Priority for #<?= (int) $r['id'] ?>" placeholder="—"></td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-outline-secondary" type="submit" name="move" value="<?= (int) $r['id'] ?>:up"<?= $i === 0 ? ' disabled' : '' ?> aria-label="Move #<?= (int) $r['id'] ?> up">&uarr;</button>
                        <button class="btn btn-sm btn-outline-secondary" type="submit" name="move" value="<?= (int) $r['id'] ?>:down"<?= $i === count($rows) - 1 ? ' disabled' : '' ?> aria-label="Move #<?= (int) $r['id'] ?> down">&darr;</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No active <?= e(strtolower($purposes[$purpose])) ?> listings yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($rows): ?>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="small text-muted">Type priority numbers and save, or use the arrows. Clear a number to remove its priority.</span>
            <button class="btn btn-primary" type="submit" name="save" value="1">Save Order</button>
        </div>
    <?php endif; ?>
</form>
