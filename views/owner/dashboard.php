<?php
/** @var array $user @var array $props @var array $enquiries @var array $bookings @var int $pendingRent @var array $limit
 *  @var ?array $limitRequest @var array $recentEnquiries @var array $upcoming @var array $notifications @var int $unread @var array $viewsChart */
$this->layout('layouts/owner', ['section' => 'dashboard']);
$limitText = $limit['unlimited'] ? $limit['used'] . ' / Unlimited' : $limit['used'] . ' / ' . $limit['limit'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-0">Welcome, <?= e($user['first_name'] . ' ' . $user['last_name']) ?></h1>
        <div class="text-muted small">Here is how your listings are doing.</div>
    </div>
    <a class="btn btn-primary btn-lg" href="<?= e(url('/owner/properties/new')) ?>">+ Post new property</a>
</div>

<?php if ($props['needs_attention'] > 0): ?>
    <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><?= (int) $props['needs_attention'] ?> listing<?= $props['needs_attention'] === 1 ? ' needs' : 's need' ?> your attention (rejected or changes requested).</span>
        <a class="btn btn-sm btn-outline-dark" href="<?= e(url('/owner/properties?status=attention')) ?>">Review</a>
    </div>
<?php endif; ?>
<?php if ($pendingRent > 0): ?>
    <div class="alert alert-info"><?= $pendingRent ?> rent change<?= $pendingRent === 1 ? ' is' : 's are' ?> waiting for admin approval. <a href="<?= e(url('/owner/rent-changes')) ?>">View</a></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-3">
        <a class="card stat-card border-0 shadow-sm h-100 text-reset text-decoration-none" href="<?= e(url('/owner/limit')) ?>">
            <div class="card-body">
                <div class="text-muted small">Properties</div>
                <div class="fs-2 fw-semibold"><?= e($limitText) ?></div>
                <div class="small <?= $limit['reached'] ? 'text-danger' : 'text-muted' ?>"><?= $limit['reached'] ? 'Limit reached' : ($limit['unlimited'] ? 'No limit' : $limit['remaining'] . ' left') ?></div>
            </div>
        </a>
    </div>
    <?php foreach ([
        ['Active', $props['active'], '/owner/properties?status=active'],
        ['Pending', $props['pending'], '/owner/properties?status=pending'],
        ['Paused', $props['paused'], '/owner/properties?status=paused'],
        ['Drafts', $props['draft'], '/owner/properties?status=draft'],
        ['Enquiries', $enquiries['total'], '/owner/enquiries', $enquiries['new'] ? $enquiries['new'] . ' new' : null],
        ['Bookings', $bookings['total'], '/owner/bookings', $bookings['pending'] ? $bookings['pending'] . ' waiting' : null],
        ['Shortlisted', $props['shortlists'], '/owner/properties'],
    ] as $card): ?>
        <div class="col-6 col-md-4 col-xl-3">
            <a class="card stat-card border-0 shadow-sm h-100 text-reset text-decoration-none" href="<?= e(url($card[2])) ?>">
                <div class="card-body">
                    <div class="text-muted small"><?= e($card[0]) ?></div>
                    <div class="fs-2 fw-semibold"><?= number_format((int) $card[1]) ?></div>
                    <?php if (!empty($card[3])): ?><div class="small text-primary fw-semibold"><?= e($card[3]) ?></div><?php endif; ?>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($props['total'] > 0): ?>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body">
        <?php $chart = $viewsChart; require APP_ROOT . '/views/partials/column-chart.php'; ?>
        <div class="small mt-2"><a href="<?= e(url('/owner/properties')) ?>">See each listing's performance</a></div>
    </div></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Latest enquiries</span>
                <a class="small" href="<?= e(url('/owner/enquiries')) ?>">View all</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($recentEnquiries as $q): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between gap-2">
                            <strong><?= e($q['first_name'] . ' ' . $q['last_name']) ?></strong>
                            <?= status_badge($q['status']) ?>
                        </div>
                        <div class="small text-muted text-truncate"><?= e((string) $q['property_title']) ?> · <?= e(format_ist($q['created_at'])) ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$recentEnquiries): ?><li class="list-group-item text-muted small py-4 text-center">No enquiries yet. They appear here as soon as someone contacts you.</li><?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Visit requests waiting</span>
                <a class="small" href="<?= e(url('/owner/bookings')) ?>">View all</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($upcoming as $b): ?>
                    <li class="list-group-item small">
                        <strong><?= e($b['first_name'] . ' ' . $b['last_name']) ?></strong> · <?= e(date('d M', strtotime($b['preferred_date']))) ?>, <?= e(date('h:i A', strtotime($b['preferred_time']))) ?>
                        <div class="text-muted text-truncate"><?= e($b['property_title']) ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$upcoming): ?><li class="list-group-item text-muted small py-3 text-center">Nothing waiting.</li><?php endif; ?>
            </ul>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Notifications <?php if ($unread): ?><span class="badge text-bg-primary"><?= $unread ?></span><?php endif; ?></span>
                <?php if ($unread): ?>
                    <form method="post" action="<?= e(url('/owner/notifications/read')) ?>" class="m-0">
                        <?= csrf_field() ?><button class="btn btn-link btn-sm p-0" type="submit">Mark all read</button>
                    </form>
                <?php endif; ?>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($notifications as $n): ?>
                    <li class="list-group-item small<?= $n['read_at'] ? ' text-muted' : '' ?>">
                        <div class="<?= $n['read_at'] ? '' : 'fw-semibold' ?>"><?= e($n['title']) ?></div>
                        <?php if ($n['body']): ?><div class="text-truncate"><?= e($n['body']) ?></div><?php endif; ?>
                        <div class="text-muted"><?= e(format_ist($n['created_at'])) ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$notifications): ?><li class="list-group-item text-muted small py-3 text-center">No notifications.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
