<?php
/** Bottom buttons for steps 1-7. Uses $step, $p, $isDraft, $stepUrl from wizard.php. */
?>
<div class="wizard-actions d-flex flex-wrap gap-2 justify-content-between align-items-center border-top pt-3 mt-4">
    <div>
        <?php if ($step > 1 && $p): ?>
            <a class="btn btn-outline-secondary" href="<?= e($stepUrl($step - 1)) ?>">&larr; Previous</a>
        <?php elseif (!$p): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url($isAdmin ? $base : '/owner/properties')) ?>">Cancel</a>
        <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($p): ?>
            <button class="btn btn-outline-primary" type="submit" name="action" value="draft"><?= $isDraft ? 'Save Draft' : 'Save &amp; exit' ?></button>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit" name="action" value="continue"><?= $isDraft ? 'Save &amp; Continue &rarr;' : 'Save &amp; review' ?></button>
    </div>
</div>
