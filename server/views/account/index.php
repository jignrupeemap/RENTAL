<?php
/**
 * My Dashboard (spec 23): counts, latest shortlist, enquiries and open visit
 * requests, notifications, account notices.
 * @var array $user @var bool $otpEnabled @var array $counts @var array $shortlist @var array $enquiries @var array $visits @var array $notifications
 */
$this->layout('layouts/account', ['section' => 'dashboard']);
$statusNotice = match ($user['status']) {
    'inactive'  => 'Your account is inactive. You can browse, but shortlisting, contacting owners and posting properties are paused. Please contact support.',
    'suspended' => 'Your account is suspended. You can browse, but shortlisting, contacting owners and posting properties are paused. Please contact support.',
    default     => null,
};
$unread = count(array_filter($notifications, static fn ($n) => $n['read_at'] === null));
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">My Dashboard</h1>
        <div class="text-muted small">Welcome, <?= e($user['first_name'] . ' ' . $user['last_name']) ?></div>
    </div>
    <a class="btn btn-primary" href="<?= e(url('/rent')) ?>">Browse properties</a>
</div>

<?php if ($statusNotice): ?>
    <div class="alert alert-warning"><?= e($statusNotice) ?><?php if (!empty($user['status_reason'])): ?><div class="small mt-1">Reason: <?= e($user['status_reason']) ?></div><?php endif; ?></div>
<?php endif; ?>
<?php if ($otpEnabled && $user['mobile_verified_at'] === null && $user['status'] === 'active'): ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span>Verify your mobile number to shortlist, contact owners and post properties.</span>
        <a class="btn btn-sm btn-primary" href="<?= e(url('/verify-mobile')) ?>">Verify now</a>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ([['Shortlisted', $counts['shortlisted'], '/account/shortlist', null],
        ['Contacted', $counts['contacted'], '/account/contacted', null],
        ['Visit requests', $counts['visits'], '/account/bookings', (int) $counts['visits_waiting'] > 0 ? $counts['visits_waiting'] . ' waiting for the owner' : null],
        ['Upcoming visits', $counts['visits_upcoming'], '/account/bookings?status=open', null]] as [$label, $value, $href, $sub]): ?>
        <div class="col-6 col-md-3">
            <a class="card border-0 shadow-sm h-100 text-reset text-decoration-none stat-card" href="<?= e(url($href)) ?>">
                <div class="card-body">
                    <div class="text-muted small"><?= e($label) ?></div>
                    <div class="fs-3 fw-semibold"><?= (int) $value ?></div>
                    <?php if ($sub): ?><div class="small text-primary"><?= e($sub) ?></div><?php endif; ?>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Recently shortlisted</span>
                <a class="small" href="<?= e(url('/account/shortlist')) ?>">View all</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($shortlist as $s): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate"><?= e($s['title'] !== '' ? $s['title'] : $s['type_name']) ?></div>
                            <div class="small text-muted"><?= e(implode(', ', array_filter([$s['locality_label'], $s['city_name']]))) ?> · <?= e(money_inr($s['rent'] !== null ? (int) $s['rent'] : null)) ?></div>
                        </div>
                        <?php $live = $s['is_live']; $pstatus = $s['status']; $plink = $s; require APP_ROOT . '/views/partials/listing-state.php'; ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$shortlist): ?><li class="list-group-item small text-muted">Nothing shortlisted yet. Tap ♡ Shortlist on any property to save it here.</li><?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Open visit requests</span>
                <a class="small" href="<?= e(url('/account/bookings')) ?>">View all</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($visits as $b): ?>
                    <li class="list-group-item small">
                        <div class="d-flex justify-content-between gap-2"><span class="fw-semibold"><?= e($b['property_title']) ?></span><?= status_badge($b['status']) ?></div>
                        <div class="text-muted"><?= e(date('d M Y', strtotime($b['preferred_date']))) ?>, <?= e(date('h:i A', strtotime($b['preferred_time']))) ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$visits): ?><li class="list-group-item small text-muted">No open visit requests.</li><?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Latest enquiries</span>
                <a class="small" href="<?= e(url('/account/contacted')) ?>">View all</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($enquiries as $q): ?>
                    <li class="list-group-item small">
                        <div class="d-flex justify-content-between gap-2"><span class="fw-semibold"><?= e((string) ($q['property_title'] ?? 'General enquiry')) ?></span><?= status_badge($q['status']) ?></div>
                        <div class="text-muted"><?= e(format_ist($q['created_at'])) ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$enquiries): ?><li class="list-group-item small text-muted">You have not contacted any owners yet.</li><?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Notifications<?php if ($unread): ?> <span class="badge rounded-pill text-bg-danger"><?= $unread ?></span><?php endif; ?></span>
                <?php if ($unread): ?>
                    <form method="post" action="<?= e(url('/account/notifications/read')) ?>" class="m-0">
                        <?= csrf_field() ?><button class="btn btn-link btn-sm p-0" type="submit">Mark all read</button>
                    </form>
                <?php endif; ?>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($notifications as $n): ?>
                    <li class="list-group-item small<?= $n['read_at'] === null ? ' fw-semibold' : '' ?>">
                        <?php if ($n['link_url'] && safe_next((string) $n['link_url'], '') !== ''): ?><a class="text-reset" href="<?= e(url($n['link_url'])) ?>"><?= e($n['title']) ?></a><?php else: ?><?= e($n['title']) ?><?php endif; ?>
                        <?php if ($n['body']): ?><div class="text-muted fw-normal"><?= e($n['body']) ?></div><?php endif; ?>
                        <div class="text-muted fw-normal"><?= e(format_ist($n['created_at'])) ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$notifications): ?><li class="list-group-item small text-muted">No notifications.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4">
    <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h2 class="h5 mb-1">Own a property?</h2>
            <div class="text-muted small">Post Rent, PG or Lease listings and manage enquiries and visits from your owner dashboard.</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-primary" href="<?= e(url('/owner')) ?>">Owner dashboard</a>
            <a class="btn btn-primary" href="<?= e(url('/owner/properties/new')) ?>">+ Post property</a>
        </div>
    </div>
</div>
