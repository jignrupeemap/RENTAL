<?php
/**
 * Shortlisted Properties (spec 24): photo, title, rent, location, date
 * shortlisted, current status; View property and Remove.
 * @var array $rows @var int $total @var int $page @var int $perPage
 */
$this->layout('layouts/account', ['section' => 'shortlist']);
$here = '/account/shortlist' . ($page > 1 ? '?page=' . $page : '');
?>
<h1 class="h4 mb-1">Shortlisted Properties</h1>
<p class="text-muted small mb-3"><?= e(plural($total, 'property', 'properties')) ?> saved</p>

<?php if (!$rows): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5">
        <p class="text-muted mb-3">Nothing shortlisted yet. Tap ♡ Shortlist on any property to keep it here.</p>
        <a class="btn btn-primary" href="<?= e(url('/rent')) ?>">Browse properties</a>
    </div></div>
<?php else: ?>
    <div class="vstack gap-3">
        <?php foreach ($rows as $s): $live = (int) $s['is_live']; $name = $s['title'] !== '' ? $s['title'] : $s['type_name']; ?>
            <div class="card border-0 shadow-sm shortlist-row<?= $live ? '' : ' is-unavailable' ?>">
                <div class="card-body d-flex flex-wrap flex-sm-nowrap gap-3">
                    <div class="shortlist-thumb rounded-2 overflow-hidden flex-shrink-0">
                        <?php if ($s['cover']): ?><?= media_picture($s['cover'], '160px', $name, true, 'w-100 h-100', 'thumb') ?><?php else: ?><span class="property-card-noimg">No photo</span><?php endif; ?>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div class="min-w-0">
                                <div class="small text-muted"><?= e($s['type_name']) ?> · <?= e(purpose_label($s['purpose'])) ?></div>
                                <div class="fw-semibold"><?= e($name) ?></div>
                                <div class="small text-muted"><?= e(implode(', ', array_filter([$s['locality_label'], $s['city_name']]))) ?></div>
                            </div>
                            <div class="text-sm-end">
                                <div class="fw-semibold"><?= e(money_inr($s['rent'] !== null ? (int) $s['rent'] : null)) ?> <span class="small fw-normal text-muted">/ month</span></div>
                                <div class="small text-muted">Shortlisted <?= e(format_ist($s['shortlisted_at'], 'd M Y')) ?></div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                            <?php $pstatus = $s['status']; $plink = $s; require APP_ROOT . '/views/partials/listing-state.php'; ?>
                            <form method="post" action="<?= e(url('/shortlist/' . (int) $s['id'])) ?>" class="m-0 ms-auto">
                                <?= csrf_field() ?>
                                <input type="hidden" name="state" value="off">
                                <input type="hidden" name="return" value="<?= e($here) ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
<?php endif; ?>
