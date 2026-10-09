<?php
/**
 * @var array $totals @var array $inRange @var array $charts @var array $props @var array $mix @var array $cities @var array $topViewed
 * @var string $range @var string $rangeLabel @var string $fromDate @var string $toDate @var bool $monthly @var string $query @var ?string $notice
 */
$this->layout('layouts/admin');
$card = static function (string $label, int $total, ?int $recent, string $rangeLabel): string {
    return '<div class="card border-0 shadow-sm stat-card h-100"><div class="card-body py-2 px-3">'
        . '<div class="text-muted small">' . e($label) . '</div><div class="fs-4 fw-semibold">' . e(number_format($total)) . '</div>'
        . ($recent !== null ? '<div class="small ' . ($recent > 0 ? 'text-success' : 'text-muted') . '">+' . e(number_format($recent)) . ' · ' . e($rangeLabel) . '</div>' : '')
        . '</div></div>';
};
$dates = '&from=' . $fromDate . '&to=' . $toDate;
$pct = static fn (int $part, int $whole): string => $whole > 0 ? number_format($part / $whole * 100, 1) . '%' : '–';
$labels = ['new' => 'New', 'contacted' => 'Contacted', 'in_progress' => 'In progress', 'closed' => 'Closed', 'pending' => 'Pending',
    'accepted' => 'Accepted', 'rescheduled' => 'Rescheduled', 'completed' => 'Completed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">Analytics</h1>
        <div class="small text-muted"><?= e($rangeLabel) ?> · India time · live from the database</div>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-end">
        <?php $rangeAction = '/admin/analytics'; require __DIR__ . '/partials/range-filter.php'; ?>
        <a class="btn btn-sm btn-outline-success" href="<?= e(url('/admin/analytics/export?' . $query)) ?>">Download CSV</a>
    </div>
</div>
<?php if ($notice): ?><div class="alert alert-warning py-2 small"><?= e($notice) ?></div><?php endif; ?>

<h2 class="h6 text-uppercase text-muted mb-2">Totals</h2>
<div class="row g-2 mb-4" id="analytics-totals">
    <?php foreach ([
        ['Total properties posted', 'properties'], ['Total users', 'users'], ['Total logins', 'logins'], ['Property views', 'views'],
        ['Total shortlists', 'shortlists'], ['Total contact requests', 'contacts'], ['Total enquiries', 'enquiries'], ['Total booking requests', 'bookings'],
    ] as [$l, $k]): ?>
        <div class="col-6 col-md-3"><?= $card($l, (int) $totals[$k], (int) $inRange[$k], $rangeLabel) ?></div>
    <?php endforeach; ?>
</div>

<div class="d-flex justify-content-between align-items-baseline mb-2">
    <h2 class="h6 text-uppercase text-muted mb-0">Trends · <?= e($rangeLabel) ?></h2>
    <span class="small text-muted"><?= $monthly ? 'One column per month' : 'One column per day' ?></span>
</div>
<div class="row g-3 mb-4">
    <?php foreach ($charts as $chart): ?>
        <div class="col-md-6 col-xxl-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body"><?php require APP_ROOT . '/views/partials/column-chart.php'; ?></div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6 col-xxl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">From views to visits · <?= e($rangeLabel) ?></div>
            <div class="card-body">
                <?php $views = (int) $inRange['views']; ?>
                <?php foreach ([['Property views', $views], ['Shortlists', (int) $inRange['shortlists']], ['Contact requests', (int) $inRange['contacts']], ['Booking requests', (int) $inRange['bookings']]] as $i => [$l, $v]): ?>
                    <div class="funnel-step mb-3">
                        <div class="small text-muted"><?= e($l) ?></div>
                        <div class="fs-5 fw-semibold"><?= e(number_format($v)) ?>
                            <?php if ($i > 0): ?><span class="small fw-normal text-muted">· <?= e($pct($v, $views)) ?> of views</span><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6 col-xxl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Listings by purpose</div>
            <div class="card-body">
                <?php $bars = [['Rent', $props['rent'], '/admin/properties?purpose=rent'], ['PG', $props['pg'], '/admin/properties?purpose=pg'], ['Lease', $props['lease'], '/admin/properties?purpose=lease']];
                require APP_ROOT . '/views/partials/bar-list.php'; ?>
                <div class="fw-semibold small mt-3 mb-2">By category</div>
                <?php $bars = [['Residential', $props['residential']], ['Commercial', $props['commercial']], ['Industrial', $props['industrial']]];
                require APP_ROOT . '/views/partials/bar-list.php'; ?>
                <div class="small text-muted mt-2">All listings except deleted ones.</div>
            </div>
        </div>
    </div>
    <div class="col-lg-6 col-xxl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Live listings by city</div>
            <div class="card-body">
                <?php $bars = array_map(static fn ($c) => [$c['name'], $c['n'], '/admin/properties?status=active&city=' . $c['id']], $cities);
                require APP_ROOT . '/views/partials/bar-list.php'; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6 col-xxl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Enquiries and bookings · <?= e($rangeLabel) ?></div>
            <div class="card-body">
                <?php $bars = array_map(static fn ($k, $v) => [$labels[$k], $v, '/admin/enquiries?status=' . $k . $dates], array_keys($mix['enquiries']), $mix['enquiries']);
                require APP_ROOT . '/views/partials/bar-list.php'; ?>
                <div class="fw-semibold small mt-3 mb-2">Booking / visit requests</div>
                <?php $bars = array_map(static fn ($k, $v) => [$labels[$k], $v, '/admin/bookings?status=' . $k . $dates], array_keys($mix['bookings']), $mix['bookings']);
                require APP_ROOT . '/views/partials/bar-list.php'; ?>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Most viewed listings · <?= e($rangeLabel) ?></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle small">
            <thead class="table-light"><tr><th>Property</th><th>Purpose</th><th>Status</th><th class="text-end">Views</th><th class="text-end">Shortlists</th><th class="text-end">Enquiries</th></tr></thead>
            <tbody>
            <?php foreach ($topViewed as $p): ?>
                <tr>
                    <td><a href="<?= e(url('/admin/properties/' . (int) $p['id'])) ?>">#<?= (int) $p['id'] ?> <?= e($p['title'] !== '' ? $p['title'] : 'Untitled') ?></a></td>
                    <td><?= e(purpose_label($p['purpose'])) ?></td>
                    <td><?= status_badge($p['status']) ?></td>
                    <td class="text-end fw-semibold"><?= e(number_format((int) $p['views'])) ?></td>
                    <td class="text-end"><?= e(number_format((int) $p['shortlists_count'])) ?></td>
                    <td class="text-end"><?= e(number_format((int) $p['enquiries_count'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$topViewed): ?><tr><td colspan="6" class="text-muted text-center py-3">No views in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted">Shortlists and enquiries are all-time totals for each listing.</div>
</div>
