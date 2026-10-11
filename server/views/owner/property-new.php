<?php
/** @var array $limit @var ?array $limitRequest */
$this->layout('layouts/owner', ['section' => 'add']);
?>
<h1 class="h4 mb-3">Post a property</h1>
<?php if ($limit['reached']): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="fs-1 fw-semibold mb-1"><?= (int) $limit['used'] ?> / <?= (int) $limit['limit'] ?></div>
            <p class="mb-1 fw-semibold">You have reached your current property posting limit of <?= (int) $limit['limit'] ?> properties.</p>
            <p class="text-muted">Please request permission from Admin to list additional properties.</p>
            <?php if ($limitRequest): ?>
                <div class="alert alert-info mb-0">Your request for <?= $limitRequest['requested_unlimited'] ? 'unlimited listings' : (int) $limitRequest['requested_limit'] . ' properties' ?> is waiting for admin review (sent <?= e(format_ist($limitRequest['created_at'])) ?>).</div>
            <?php else: ?>
                <a class="btn btn-primary btn-lg" href="<?= e(url('/owner/limit')) ?>">REQUEST MORE PROPERTY LIMIT</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <p class="mb-1">You can post <strong><?= $limit['unlimited'] ? 'unlimited' : (int) $limit['remaining'] ?></strong> more <?= $limit['remaining'] === 1 ? 'property' : 'properties' ?>.</p>
            <p class="text-muted mb-0">The step-by-step posting form (type, details, location, facilities, rent, photos, owner details, preview) is being added in the next phase and will open from this page.</p>
        </div>
    </div>
<?php endif; ?>
