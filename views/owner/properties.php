<?php
/** @var ?string $status @var array $rows @var int $total @var int $page @var int $perPage @var array $counts @var array $limit @var bool $allowPause */
$this->layout('layouts/owner', ['section' => 'properties', 'scripts' => ['js/property-wizard.js']]);
$tabs = [
    '' => ['All', $counts['total']], 'active' => ['Active', $counts['active']], 'pending' => ['Pending', $counts['pending']],
    'draft' => ['Drafts', $counts['draft']], 'paused' => ['Paused', $counts['paused']],
    'attention' => ['Needs attention', $counts['needs_attention']], 'expired' => ['Expired', $counts['expired']],
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">My Properties <span class="text-muted fw-normal fs-6"><?= e($limit['unlimited'] ? $limit['used'] . ' / Unlimited' : $limit['used'] . ' / ' . $limit['limit'] . ' used') ?></span></h1>
    <a class="btn btn-primary" href="<?= e(url('/owner/properties/new')) ?>">+ Post new property</a>
</div>
<?php $current = $status; require APP_ROOT . '/views/partials/tabs.php'; ?>

<?php if (!$rows): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5">
        <p class="mb-3 text-muted"><?= $status ? 'No listings with this status.' : 'You have not posted any property yet.' ?></p>
        <?php if (!$status): ?><a class="btn btn-primary" href="<?= e(url('/owner/properties/new')) ?>">Post your first property</a><?php endif; ?>
    </div></div>
<?php else: ?>
    <div class="vstack gap-3">
        <?php foreach ($rows as $p): ?>
            <div class="card border-0 shadow-sm owner-property">
                <div class="card-body d-flex flex-column flex-md-row gap-3">
                    <div class="owner-thumb flex-shrink-0<?= $p['cover_key'] ? '' : ' no-photo' ?>">
                        <?php if ($p['cover_key']): ?>
                            <img src="<?= e(url('uploads/' . $p['cover_dir'] . '/' . $p['cover_key'] . '_thumb.' . ($p['cover_webp'] ? 'webp' : 'jpg'))) ?>" alt="" width="160" height="110" loading="lazy">
                        <?php else: ?>
                            <span class="small text-muted">No photo</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                            <?= status_badge($p['status']) ?>
                            <span class="small text-muted">#<?= (int) $p['id'] ?> · <?= e(purpose_label($p['purpose'])) ?> · <?= e($p['category_name'] . ' › ' . $p['type_name']) ?></span>
                        </div>
                        <h2 class="h6 mb-1 text-truncate"><?= e($p['title'] !== '' ? $p['title'] : 'Untitled draft') ?></h2>
                        <div class="small text-muted mb-2"><?= e(implode(', ', array_filter([$p['locality_name'], $p['city_name']])) ?: 'Location not set') ?></div>
                        <div class="d-flex flex-wrap gap-3 small">
                            <span><strong><?= e(money_inr($p['rent'] !== null ? (int) $p['rent'] : null)) ?></strong><?= $p['rent'] !== null ? ' / month' : '' ?></span>
                            <?php if ($p['pending_rent'] !== null): ?>
                                <span class="text-warning-emphasis"><?= e(money_inr((int) $p['pending_rent'])) ?> waiting for approval</span>
                            <?php endif; ?>
                            <span class="text-muted"><?= e(plural((int) $p['views_count'], 'view', 'views')) ?></span>
                            <span class="text-muted"><?= e(plural((int) $p['enquiries_count'], 'enquiry', 'enquiries')) ?></span>
                            <span class="text-muted"><?= e(plural((int) $p['shortlists_count'], 'shortlist', 'shortlists')) ?></span>
                            <span class="text-muted">Updated <?= e(format_ist($p['updated_at'], 'd M Y')) ?></span>
                        </div>
                        <?php if (in_array($p['status'], ['rejected', 'changes_requested'], true) && $p['rejection_reason']): ?>
                            <div class="alert alert-warning small py-2 mt-2 mb-0">Admin note: <?= e($p['rejection_reason']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex flex-wrap flex-md-column gap-2 align-items-start">
                        <?php $pid = (int) $p['id']; ?>
                        <?php if ($p['status'] === 'draft'): ?>
                            <a class="btn btn-sm btn-primary" href="<?= e(url('/owner/properties/' . $pid . '/edit')) ?>">Continue draft</a>
                            <span class="small text-muted">Step <?= min((int) $p['draft_step'] + 1, 8) ?> of 8</span>
                            <form method="post" action="<?= e(url('/owner/properties/' . $pid . '/delete')) ?>" data-confirm="Delete this draft? This cannot be undone.">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete draft</button>
                            </form>
                        <?php else: ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/owner/properties/' . $pid . '/step/2')) ?>">Edit</a>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/owner/properties/' . $pid . '/step/8')) ?>">Preview</a>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/owner/properties/' . $pid . '/performance')) ?>">Performance</a>
                        <?php endif; ?>
                        <?php if ($allowPause && in_array($p['status'], ['active', 'paused'], true)): ?>
                            <form method="post" action="<?= e(url('/owner/properties/' . (int) $p['id'] . '/pause')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $p['status'] === 'active' ? 'Pause listing' : 'Resume listing' ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
<?php endif; ?>
