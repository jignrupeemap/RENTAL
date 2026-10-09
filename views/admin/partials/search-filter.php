<?php
/**
 * Search box + status + date range for list pages.
 * @var string $action @var array $f @var array $statuses value => label @var string $placeholder
 */
?>
<form class="card border-0 shadow-sm mb-3" method="get" action="<?= e(url($action)) ?>">
    <div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="<?= e((string) ($f['q'] ?? '')) ?>" placeholder="<?= e($placeholder) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>"<?= ($f['status'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="from">From</label>
            <input class="form-control" type="date" id="from" name="from" value="<?= e((string) ($f['from'] ?? '')) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="<?= e((string) ($f['to'] ?? '')) ?>">
        </div>
        <div class="col-6 col-md-2 d-flex gap-2">
            <?php foreach (['user', 'owner', 'property'] as $k): if (!empty($f[$k])): ?><input type="hidden" name="<?= $k ?>" value="<?= (int) $f[$k] ?>"><?php endif; endforeach; ?>
            <?php foreach ($f['hidden'] ?? [] as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-link px-1" href="<?= e(url($action)) ?>">Clear</a>
        </div>
        <?php foreach (['user' => 'Enquirer / viewer', 'owner' => 'Owner', 'property' => 'Property'] as $k => $l): if (!empty($f[$k])): ?>
            <div class="col-12 small"><span class="badge text-bg-light border"><?= e($l) ?> #<?= (int) $f[$k] ?> <a href="<?= e(url_with([$k => null, 'page' => null])) ?>" aria-label="Remove filter">×</a></span></div>
        <?php endif; endforeach; ?>
    </div>
</form>
