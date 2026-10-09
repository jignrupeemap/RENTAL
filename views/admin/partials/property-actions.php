<?php
/**
 * Action buttons for one listing. Approve/Pause/Activate/Delete/Restore/Publish
 * are one-click forms; Reject and Request changes open a note box (spec 14).
 * @var array $p @var array $allowed @var string $return path to come back to
 * @var bool $compact smaller buttons for table rows
 */
$compact ??= false;
$return ??= '';
$btn = $compact ? 'btn btn-sm' : 'btn';
$simple = [
    'publish'  => ['Publish now', 'btn-success', null],
    'approve'  => ['Approve', 'btn-success', null],
    'activate' => ['Activate', 'btn-success', null],
    'pause'    => ['Pause', 'btn-outline-secondary', 'Pause this listing? It will be hidden from search.'],
    'restore'  => ['Restore', 'btn-outline-primary', null],
    'delete'   => ['Delete', 'btn-outline-danger', 'Delete this listing? It is hidden everywhere but kept in the database, and can be restored.'],
];
$withNote = [
    'changes' => ['Request changes', 'btn-outline-warning', 'What should the owner change?'],
    'reject'  => ['Reject', 'btn-outline-danger', 'Reason for rejecting (the owner sees this)'],
];
$pid = (int) $p['id'];
?>
<div class="d-flex flex-wrap gap-2 align-items-start">
    <?php foreach ($simple as $action => [$label, $class, $confirm]): if (!in_array($action, $allowed, true)) continue; ?>
        <form method="post" action="<?= e(url('/admin/properties/' . $pid . '/action')) ?>"<?= $confirm ? ' data-confirm="' . e($confirm) . '"' : '' ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= e($action) ?>">
            <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
            <button class="<?= $btn ?> <?= $class ?>" type="submit"><?= e($label) ?></button>
        </form>
    <?php endforeach; ?>
    <?php foreach ($withNote as $action => [$label, $class, $placeholder]): if (!in_array($action, $allowed, true)) continue; ?>
        <details class="note-action">
            <summary class="<?= $btn ?> <?= $class ?>"><?= e($label) ?></summary>
            <form method="post" action="<?= e(url('/admin/properties/' . $pid . '/action')) ?>" class="card card-body p-2 mt-1 shadow-sm" style="min-width:16rem">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= e($action) ?>">
                <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
                <label class="form-label small mb-1" for="reason_<?= $action . '_' . $pid ?>"><?= e($placeholder) ?></label>
                <textarea class="form-control form-control-sm mb-2" id="reason_<?= $action . '_' . $pid ?>" name="reason" rows="3" maxlength="500" required minlength="5"></textarea>
                <button class="btn btn-sm <?= $action === 'reject' ? 'btn-danger' : 'btn-warning' ?>" type="submit"><?= e($label) ?></button>
            </form>
        </details>
    <?php endforeach; ?>
</div>
