<?php
/** @var array $rows @var int $total @var int $page @var int $perPage @var string $mode */
$this->layout('layouts/owner', ['section' => 'rent']);
$modeText = ['none' => 'Rent changes go live immediately.', 'increase_only' => 'Rent increases go live after admin approval; decreases go live immediately.',
    'all' => 'Every rent change goes live after admin approval.'][$mode] ?? '';
?>
<h1 class="h4 mb-1">Rental Change History</h1>
<p class="text-muted small mb-3"><?= e($modeText) ?> Until a change is approved, the old rent stays on your listing.</p>
<?php if (!$rows): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">No rent changes yet. When you edit the rent of a listing, it is recorded here.</div></div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light small"><tr><th>Property</th><th class="text-end">Old rent</th><th class="text-end">New rent</th><th class="text-end">Change</th><th>Status</th><th>Date (IST)</th></tr></thead>
                <tbody class="small">
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['property_title']) ?> <span class="text-muted">#<?= (int) $r['property_id'] ?></span>
                            <?php if ($r['review_note']): ?><div class="text-muted">Admin: <?= e($r['review_note']) ?></div><?php endif; ?></td>
                        <td class="text-end"><?= e(money_inr((int) $r['old_rent'])) ?></td>
                        <td class="text-end"><?= e(money_inr((int) $r['new_rent'])) ?></td>
                        <td class="text-end <?= (int) $r['difference'] > 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $r['difference'] > 0 ? '+' : '' ?><?= e(money_inr((int) $r['difference'])) ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td class="text-nowrap"><?= e(format_ist($r['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
<?php endif; ?>
