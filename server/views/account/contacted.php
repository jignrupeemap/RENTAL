<?php
/**
 * Contacted Properties (spec 25). Shows what the viewer saw when they
 * enquired (title, owner, rent), kept even if the listing changes later.
 * @var array $rows @var int $total @var int $page @var int $perPage
 */
$this->layout('layouts/account', ['section' => 'contacted']);
?>
<h1 class="h4 mb-1">Contacted Properties</h1>
<p class="text-muted small mb-3"><?= e(plural($total, 'enquiry', 'enquiries')) ?></p>

<?php if (!$rows): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5 text-muted">You have not contacted any owners yet.</div></div>
<?php else: ?>
    <div class="vstack gap-3">
        <?php foreach ($rows as $q): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                        <div class="fw-semibold"><?= e((string) ($q['property_title'] ?? 'General enquiry')) ?> <span class="ms-1"><?= status_badge($q['status']) ?></span></div>
                        <span class="small text-muted"><?= e(format_ist($q['created_at'])) ?></span>
                    </div>
                    <dl class="row small mb-2 g-1">
                        <?php if ($q['owner_name']): ?><dt class="col-5 col-sm-3 text-muted fw-normal">Owner</dt><dd class="col-7 col-sm-9 mb-0"><?= e($q['owner_name']) ?></dd><?php endif; ?>
                        <?php if (!$q['property_id'] && $q['interested_in']): ?>
                            <dt class="col-5 col-sm-3 text-muted fw-normal">Looking for</dt>
                            <dd class="col-7 col-sm-9 mb-0"><?= e(purpose_label($q['interested_in'])) ?><?= $q['budget'] !== null ? ' · up to ' . e(money_inr((int) $q['budget'])) : '' ?></dd>
                        <?php else: ?>
                        <dt class="col-5 col-sm-3 text-muted fw-normal">Rent at enquiry</dt>
                        <dd class="col-7 col-sm-9 mb-0"><?= e($q['rent_at_enquiry'] !== null ? money_inr((int) $q['rent_at_enquiry']) . ' / month' : '—') ?>
                            <?php if ($q['rent_now'] !== null && $q['rent_at_enquiry'] !== null && (int) $q['rent_now'] !== (int) $q['rent_at_enquiry'] && (int) $q['is_live']): ?>
                                <span class="text-muted">(now <?= e(money_inr((int) $q['rent_now'])) ?>)</span>
                            <?php endif; ?>
                        </dd>
                        <?php endif; ?>
                    </dl>
                    <?php if ($q['message']): ?><p class="small bg-light rounded p-2 mb-2 text-break"><?= nl2br(e((string) $q['message'])) ?></p><?php endif; ?>
                    <?php if ($q['property_id']): $live = $q['is_live']; $pstatus = $q['property_status']; $plink = ['id' => $q['property_id'], 'purpose' => $q['purpose'], 'slug' => $q['slug']]; require APP_ROOT . '/views/partials/listing-state.php'; endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
<?php endif; ?>
