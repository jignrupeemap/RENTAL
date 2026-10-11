<?php
/** Step 8: preview with the listing template, what is missing, and submit. @var array $missing @var array $preview */
$canSubmit = in_array($p['status'], \App\Services\PropertyService::SUBMITTABLE, true);
?>
<?php if ($missing): ?>
    <div class="alert alert-warning">
        <div class="fw-semibold mb-1">Please complete these before submitting:</div>
        <ul class="mb-0 ps-3">
            <?php foreach ($missing as $n => $labels): ?>
                <li><a href="<?= e($stepUrl($n)) ?>"><?= e($steps[$n]) ?></a>: <?= e(implode(', ', $labels)) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php elseif ($p['status'] === 'pending'): ?>
    <div class="alert alert-info small">This listing is waiting for admin approval. You can still correct details below.</div>
<?php elseif (in_array($p['status'], ['active', 'approved'], true)): ?>
    <div class="alert alert-success small">This listing is live. Use the Edit links to change any section.</div>
<?php endif; ?>
<?php if (in_array($p['status'], ['rejected', 'changes_requested'], true) && $p['rejection_reason']): ?>
    <div class="alert alert-warning small">Admin note: <?= e($p['rejection_reason']) ?></div>
<?php endif; ?>

<p class="small text-muted">This is how your listing will look to visitors. Your contact details are shown only to people who enquire.</p>
<div class="preview-frame">
    <?php $editBase = $stepUrl(1); $editBase = substr($editBase, 0, -1); require APP_ROOT . '/views/partials/property-detail.php'; ?>
</div>

<div class="wizard-actions d-flex flex-wrap gap-2 justify-content-between align-items-center border-top pt-3 mt-4">
    <a class="btn btn-outline-secondary" href="<?= e($stepUrl(7)) ?>">&larr; Previous</a>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($isAdmin): ?>
            <a class="btn btn-outline-primary" href="<?= e(url($base . '/' . (int) $p['id'])) ?>"><?= $p['status'] === 'draft' ? 'Save Draft &amp; exit' : 'Back to listing' ?></a>
            <?php if (in_array('publish', \App\Services\Admin\AdminPropertyService::allowed($p), true)): ?>
                <form method="post" action="<?= e(url($base . '/' . (int) $p['id'] . '/action')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="publish">
                    <button class="btn btn-primary" type="submit"<?= $missing ? ' disabled' : '' ?>>Publish now</button>
                </form>
            <?php endif; ?>
        <?php elseif ($p['status'] === 'draft'): ?>
            <form method="post" action="<?= e(url('/owner/properties/' . (int) $p['id'] . '/delete')) ?>" data-confirm="Delete this draft? This cannot be undone.">
                <?= csrf_field() ?>
                <button class="btn btn-outline-danger" type="submit">Delete draft</button>
            </form>
            <a class="btn btn-outline-primary" href="<?= e(url('/owner/properties?status=draft')) ?>">Save Draft &amp; exit</a>
        <?php else: ?>
            <a class="btn btn-outline-primary" href="<?= e(url('/owner/properties')) ?>">Back to My Properties</a>
        <?php endif; ?>
        <?php if ($canSubmit && !$isAdmin): ?>
            <form method="post" action="<?= e(url('/owner/properties/' . (int) $p['id'] . '/submit')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-primary" type="submit"<?= $missing ? ' disabled' : '' ?>><?= $p['status'] === 'draft' ? 'Submit for Admin Approval' : 'Resubmit for Approval' ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>
