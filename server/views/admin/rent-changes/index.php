<?php
/** @var string $status @var ?array $property @var array $rows @var int $total @var int $page @var int $perPage @var int $pendingCount */
$this->layout('layouts/admin');
$tabs = ['pending' => ['Waiting for approval', $pendingCount], '' => ['All changes', null]];
?>
<h1 class="h4 mb-3">Rental changes<?= $property ? ' · #' . (int) $property['id'] . ' ' . e($property['title']) : '' ?></h1>
<?php if ($property): ?>
    <p class="small"><a href="<?= e(url('/admin/properties/' . (int) $property['id'])) ?>">&larr; Back to the listing</a> · <a href="<?= e(url('/admin/rent-changes')) ?>">All listings</a></p>
<?php else: ?>
    <ul class="nav nav-pills mb-3">
        <?php foreach ($tabs as $k => [$label, $count]): ?>
            <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/rent-changes' . ($k === '' ? '?status=' : '?status=' . $k))) ?>"><?= e($label) ?><?= $count !== null ? ' <span class="badge rounded-pill tab-count">' . (int) $count . '</span>' : '' ?></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 admin-table small">
            <thead class="table-light"><tr><th>Changed (IST)</th><th>Property</th><th>Owner</th><th class="text-end">Old rent</th><th class="text-end">New rent</th><th class="text-end">Difference</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $d = (int) $r['difference']; ?>
                <tr>
                    <td class="text-nowrap"><?= e(format_ist($r['created_at'])) ?></td>
                    <td><a href="<?= e(url('/admin/properties/' . (int) $r['property_id'])) ?>">#<?= (int) $r['property_id'] ?> <?= e($r['property_title']) ?></a></td>
                    <td><?php if ($r['owner_id']): ?><a href="<?= e(url('/admin/users/' . (int) $r['owner_id'])) ?>"><?= e((string) $r['owner_name']) ?></a><div class="text-muted"><?= e(format_mobile($r['owner_mobile'])) ?></div><?php else: ?>Admin / system<?php endif; ?></td>
                    <td class="text-end"><?= e(money_inr((int) $r['old_rent'])) ?></td>
                    <td class="text-end fw-semibold"><?= e(money_inr((int) $r['new_rent'])) ?></td>
                    <td class="text-end <?= $d > 0 ? 'text-danger' : 'text-success' ?>"><?= $d > 0 ? '+' : '−' ?><?= e(money_inr(abs($d))) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="text-end"><a class="btn btn-sm <?= $r['status'] === 'pending' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('/admin/rent-changes/' . (int) $r['id'])) ?>"><?= $r['status'] === 'pending' ? 'Review' : 'View' ?></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4"><?= $status === 'pending' ? 'No rent changes waiting.' : 'No rent changes yet.' ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
