<?php
/** RENTAL CHANGE ALERT card (spec 30). @var array $c @var bool $waiting */
$this->layout('layouts/admin');
$d = (int) $c['difference'];
?>
<nav class="small mb-2"><a href="<?= e(url('/admin/rent-changes')) ?>">&larr; Rental changes</a></nav>
<div class="card border-0 shadow-sm alert-card" style="max-width:40rem">
    <div class="card-header <?= $waiting ? 'bg-warning-subtle' : 'bg-white' ?> d-flex justify-content-between align-items-center">
        <span class="fw-bold text-uppercase">Rental change alert</span><?= status_badge($c['status']) ?>
    </div>
    <div class="card-body">
        <h1 class="h5">Rental changed</h1>
        <dl class="row mb-3">
            <dt class="col-sm-4">Owner</dt><dd class="col-sm-8"><?= e((string) ($c['owner_name'] ?: 'Admin / system')) ?></dd>
            <dt class="col-sm-4">Mobile</dt><dd class="col-sm-8"><?= $c['owner_mobile'] ? '<a href="tel:+' . e($c['owner_mobile']) . '">' . e(format_mobile($c['owner_mobile'])) . '</a>' : '—' ?></dd>
            <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= $c['owner_email'] ? e($c['owner_email']) : '—' ?></dd>
            <dt class="col-sm-4">Property</dt><dd class="col-sm-8">#<?= (int) $c['property_id'] ?> <?= e($c['property_title']) ?></dd>
            <dt class="col-sm-4">Old rent</dt><dd class="col-sm-8"><?= e(money_inr((int) $c['old_rent'])) ?></dd>
            <dt class="col-sm-4">New rent</dt><dd class="col-sm-8 fw-semibold"><?= e(money_inr((int) $c['new_rent'])) ?></dd>
            <dt class="col-sm-4"><?= $d > 0 ? 'Increase' : 'Decrease' ?></dt><dd class="col-sm-8 <?= $d > 0 ? 'text-danger' : 'text-success' ?>"><?= e(money_inr(abs($d))) ?></dd>
            <dt class="col-sm-4">Changed</dt><dd class="col-sm-8"><?= e(format_ist($c['created_at'])) ?><?= $c['changer_first'] ? ' by ' . e($c['changer_first'] . ' ' . $c['changer_last']) : '' ?><?= $c['ip_address'] ? ' · IP ' . e($c['ip_address']) : '' ?></dd>
            <dt class="col-sm-4">Live rent now</dt><dd class="col-sm-8"><?= e(money_inr((int) $c['live_rent'])) ?></dd>
            <?php if ($c['reviewed_at']): ?>
                <dt class="col-sm-4">Reviewed</dt><dd class="col-sm-8"><?= e(format_ist($c['reviewed_at'])) ?><?= $c['reviewer_first'] ? ' by ' . e($c['reviewer_first'] . ' ' . $c['reviewer_last']) : '' ?><?= $c['review_note'] ? '<br>' . e($c['review_note']) : '' ?></dd>
            <?php elseif ($c['review_note']): ?>
                <dt class="col-sm-4">Note</dt><dd class="col-sm-8"><?= e($c['review_note']) ?></dd>
            <?php endif; ?>
        </dl>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a class="btn btn-outline-primary" href="<?= e(url('/admin/properties/' . (int) $c['property_id'])) ?>">VIEW PROPERTY</a>
            <?php if ($c['owner_id']): ?><a class="btn btn-outline-primary" href="<?= e(url('/admin/users/' . (int) $c['owner_id'])) ?>">VIEW OWNER</a><?php endif; ?>
            <a class="btn btn-outline-primary" href="<?= e(url('/admin/rent-changes?property=' . (int) $c['property_id'])) ?>">VIEW CHANGE HISTORY</a>
        </div>
        <?php if ($waiting): ?>
            <div class="border-top pt-3">
                <form method="post" action="<?= e(url('/admin/rent-changes/' . (int) $c['id'])) ?>">
                    <?= csrf_field() ?>
                    <label class="form-label small" for="note">Note for the owner (required to reject)</label>
                    <textarea class="form-control mb-2" id="note" name="note" rows="2" maxlength="500"></textarea>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-success" type="submit" name="decision" value="approve">Approve <?= e(money_inr((int) $c['new_rent'])) ?></button>
                        <button class="btn btn-outline-danger" type="submit" name="decision" value="reject">Reject (keep <?= e(money_inr((int) $c['live_rent'])) ?>)</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
