<?php
/**
 * Compact RENTAL CHANGE ALERT card (spec 30) for the dashboard.
 * @var array $c rental_change_history row + live_rent, pending_rent_change_id
 */
$d = (int) $c['difference'];
$waiting = $c['status'] === 'pending' && (int) $c['pending_rent_change_id'] === (int) $c['id'];
?>
<div class="card border-0 shadow-sm h-100 rent-alert<?= $waiting ? ' is-waiting' : '' ?>">
    <div class="card-header <?= $waiting ? 'bg-warning-subtle' : 'bg-white' ?> d-flex justify-content-between align-items-center py-2">
        <span class="fw-bold small text-uppercase">Rental changed</span>
        <?= $waiting ? '<span class="badge text-bg-warning">Waiting for approval</span>' : status_badge($c['status']) ?>
    </div>
    <div class="card-body small">
        <dl class="row mb-2 g-0">
            <dt class="col-5 fw-normal text-muted">Owner</dt><dd class="col-7 mb-1"><?= e((string) ($c['owner_name'] ?: 'Admin / system')) ?></dd>
            <dt class="col-5 fw-normal text-muted">Mobile</dt><dd class="col-7 mb-1"><?= $c['owner_mobile'] ? '<a href="tel:+' . e($c['owner_mobile']) . '">' . e(format_mobile($c['owner_mobile'])) . '</a>' : '—' ?></dd>
            <dt class="col-5 fw-normal text-muted">Property</dt><dd class="col-7 mb-1 text-break">#<?= (int) $c['property_id'] ?> <?= e($c['property_title']) ?></dd>
            <dt class="col-5 fw-normal text-muted">Old rent</dt><dd class="col-7 mb-1"><?= e(money_inr((int) $c['old_rent'])) ?></dd>
            <dt class="col-5 fw-normal text-muted">New rent</dt><dd class="col-7 mb-1 fw-semibold"><?= e(money_inr((int) $c['new_rent'])) ?></dd>
            <dt class="col-5 fw-normal text-muted"><?= $d > 0 ? 'Increase' : 'Decrease' ?></dt><dd class="col-7 mb-1 <?= $d > 0 ? 'text-danger' : 'text-success' ?>"><?= e(money_inr(abs($d))) ?></dd>
            <dt class="col-5 fw-normal text-muted">Changed</dt><dd class="col-7 mb-0"><?= e(format_ist($c['created_at'])) ?></dd>
        </dl>
        <div class="d-flex flex-wrap gap-1">
            <?php if ($waiting): ?><a class="btn btn-sm btn-primary" href="<?= e(url('/admin/rent-changes/' . (int) $c['id'])) ?>">Review</a><?php endif; ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/properties/' . (int) $c['property_id'])) ?>">VIEW PROPERTY</a>
            <?php if ($c['owner_id']): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/users/' . (int) $c['owner_id'])) ?>">VIEW OWNER</a><?php endif; ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/rent-changes?property=' . (int) $c['property_id'])) ?>">VIEW CHANGE HISTORY</a>
        </div>
    </div>
</div>
