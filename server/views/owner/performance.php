<?php
/** @var array $p @var int $days @var array $periods @var array $charts @var array $inRange @var array $rentChanges @var array $statusHistory */
$this->layout('layouts/owner', ['section' => 'properties']);
$pid = (int) $p['id'];
$actor = ['owner' => 'You', 'admin' => 'Admin team', 'system' => 'Automatic'];
$periodLabel = [30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'];
?>
<nav class="small mb-2"><a href="<?= e(url('/owner/properties')) ?>">← My Properties</a></nav>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div class="min-w-0">
        <h1 class="h4 mb-1">Performance</h1>
        <div class="d-flex flex-wrap gap-2 align-items-center small">
            <?= status_badge($p['status']) ?>
            <span class="text-truncate"><?= e($p['title'] !== '' ? $p['title'] : 'Untitled draft') ?> <span class="text-muted">#<?= $pid ?></span></span>
            <?php if ($p['status'] === 'active'): ?><a href="<?= e(url(property_url($p))) ?>">View listing</a><?php endif; ?>
        </div>
    </div>
    <div class="btn-group btn-group-sm" role="group" aria-label="Period">
        <?php foreach ($periods as $d): ?>
            <a class="btn btn-outline-primary<?= $days === $d ? ' active' : '' ?>" href="<?= e(url('/owner/properties/' . $pid . '/performance?days=' . $d)) ?>"><?= e($periodLabel[$d]) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([['Views', 'views', 'views_count'], ['Shortlists', 'shortlists', 'shortlists_count'], ['Enquiries', 'enquiries', 'enquiries_count'], ['Visit requests', 'bookings', 'bookings_count']] as [$l, $k, $col]): ?>
        <div class="col-6 col-lg-3">
            <div class="card stat-card border-0 shadow-sm h-100"><div class="card-body">
                <div class="text-muted small"><?= e($l) ?> · <?= e(strtolower($periodLabel[$days])) ?></div>
                <div class="fs-2 fw-semibold"><?= e(number_format((int) $inRange[$k])) ?></div>
                <div class="small text-muted"><?= e(number_format((int) $p[$col])) ?> since posted</div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($charts as $chart): ?>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100"><div class="card-body"><?php require APP_ROOT . '/views/partials/column-chart.php'; ?></div></div>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($p['status'] !== 'active'): ?>
    <p class="small text-muted">Only live listings are seen by visitors, so views come in while the listing is active.</p>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Rent history</div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($rentChanges as $r): ?>
                    <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                        <span><?= e(money_inr((int) $r['old_rent'])) ?> → <strong><?= e(money_inr((int) $r['new_rent'])) ?></strong>
                            <?php if ($r['review_note']): ?><span class="d-block text-muted">Admin: <?= e($r['review_note']) ?></span><?php endif; ?></span>
                        <span class="text-nowrap"><?= status_badge($r['status']) ?> <span class="text-muted"><?= e(format_ist($r['created_at'], 'd M Y')) ?></span></span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$rentChanges): ?><li class="list-group-item text-muted">The rent has not been changed since posting. It is <?= e(money_inr($p['rent'] !== null ? (int) $p['rent'] : null)) ?> a month.</li><?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Listing history</div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($statusHistory as $h): ?>
                    <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                        <span><?= status_badge($h['new_status']) ?> <span class="text-muted">by <?= e($actor[$h['actor_role']] ?? 'Admin team') ?></span>
                            <?php if ($h['reason']): ?><span class="d-block text-muted"><?= e($h['reason']) ?></span><?php endif; ?></span>
                        <span class="text-muted text-nowrap"><?= e(format_ist($h['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$statusHistory): ?><li class="list-group-item text-muted">Posted on <?= e(format_ist($p['created_at'], 'd M Y')) ?>. No status changes yet.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
