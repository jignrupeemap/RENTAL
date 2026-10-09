<?php
/**
 * @var array $alerts @var array $rentAlerts @var array $recentLogins @var array $users @var array $props @var array $engage @var array $latest
 * @var string $range @var string $rangeLabel @var string $fromDate @var string $toDate
 */
$this->layout('layouts/admin');
$alertCards = [
    ['Pending approvals', $alerts['approvals'], '/admin/properties?status=pending'],
    ['Rental changes', $alerts['rent_changes'], '/admin/rent-changes'],
    ['Limit requests', $alerts['limit_requests'], '/admin/limit-requests'],
    ['New enquiries', $alerts['new_enquiries'], '/admin/enquiries?status=new'],
    ['New booking requests', $alerts['new_bookings'], '/admin/bookings?status=pending'],
];
$card = static function (string $label, int $value, ?string $href = null, string $extra = ''): string {
    $inner = '<div class="text-muted small">' . e($label) . '</div><div class="fs-4 fw-semibold">' . e(number_format($value)) . '</div>' . $extra;
    return '<div class="card border-0 shadow-sm stat-card h-100"><div class="card-body py-2 px-3">'
        . ($href ? '<a class="stretched-link text-reset text-decoration-none" href="' . e(url($href)) . '">' . $inner . '</a>' : $inner)
        . '</div></div>';
};
$eventBadge = ['login_success' => 'success', 'login_failed' => 'danger', 'locked_out' => 'danger', 'logout' => 'secondary',
    'otp_sent' => 'info', 'otp_failed' => 'warning', 'password_reset' => 'primary'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <h1 class="h4 mb-0">Dashboard</h1>
    <?php $rangeAction = '/admin'; require __DIR__ . '/partials/range-filter.php'; ?>
</div>

<h2 class="h6 text-uppercase text-muted mb-2">Alerts</h2>
<div class="row g-2 mb-4">
    <?php foreach ($alertCards as [$label, $value, $href]): ?>
        <div class="col-6 col-md-4 col-xl"><?= $card($label, (int) $value, $href, (int) $value > 0 ? '<span class="badge text-bg-warning">Needs action</span>' : '') ?></div>
    <?php endforeach; ?>
</div>

<?php if ($rentAlerts): ?>
    <div class="d-flex justify-content-between align-items-baseline mb-2">
        <h2 class="h6 text-uppercase text-muted mb-0">Rental change alerts</h2>
        <a class="small" href="<?= e(url('/admin/rent-changes?status=')) ?>">All rental changes</a>
    </div>
    <div class="row g-3 mb-4">
        <?php foreach ($rentAlerts as $c): ?>
            <div class="col-md-6 col-xxl-3"><?php require __DIR__ . '/partials/rent-alert.php'; ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="h6 text-uppercase text-muted mb-2">Users</h2>
<div class="row g-2 mb-4">
    <?php foreach ([['Total users', $users['total'], '/admin/users'], ['Active', $users['active'], '/admin/users?status=active'],
        ['Inactive', $users['inactive'], '/admin/users?status=inactive'], ['Suspended', $users['suspended'], '/admin/users?status=suspended'],
        ['Blocked', $users['blocked'], '/admin/users?status=blocked'], ['New registrations · ' . $rangeLabel, $users['new_in_range'], null]] as [$l, $v, $h]): ?>
        <div class="col-6 col-md-4 col-xl-2"><?= $card($l, (int) $v, $h) ?></div>
    <?php endforeach; ?>
</div>

<h2 class="h6 text-uppercase text-muted mb-2">Properties</h2>
<div class="row g-2 mb-2">
    <?php foreach ([['Total properties', $props['total'], '/admin/properties'], ['Rent', $props['rent'], '/admin/properties?purpose=rent'],
        ['PG', $props['pg'], '/admin/properties?purpose=pg'], ['Lease', $props['lease'], '/admin/properties?purpose=lease'],
        ['Residential', $props['residential'], null], ['Commercial', $props['commercial'], null], ['Industrial', $props['industrial'], null]] as [$l, $v, $h]): ?>
        <div class="col-6 col-md-3 col-xl"><?= $card($l, (int) $v, $h) ?></div>
    <?php endforeach; ?>
</div>
<div class="row g-2 mb-4">
    <?php foreach ([['Pending', $props['pending'], '/admin/properties?status=pending'], ['Active', $props['active'], '/admin/properties?status=active'],
        ['Paused', $props['paused'], '/admin/properties?status=paused'], ['Rejected / changes', $props['attention'], '/admin/properties?status=attention'],
        ['Drafts', $props['draft'], '/admin/properties?status=draft'], ['Posted · ' . $rangeLabel, $props['posted_in_range'], null]] as [$l, $v, $h]): ?>
        <div class="col-6 col-md-4 col-xl-2"><?= $card($l, (int) $v, $h) ?></div>
    <?php endforeach; ?>
</div>

<div class="d-flex justify-content-between align-items-baseline mb-2">
    <h2 class="h6 text-uppercase text-muted mb-0">Engagement · <?= e($rangeLabel) ?></h2>
    <a class="small" href="<?= e(url('/admin/analytics' . ($range === 'custom' ? '?range=custom&from=' . $fromDate . '&to=' . $toDate : '?range=' . $range))) ?>">Charts and trends</a>
</div>
<div class="row g-2 mb-4">
    <?php foreach ([['Logins', $engage['logins']], ['Property views', $engage['views']], ['Shortlists', $engage['shortlists']],
        ['Contact owner requests', $engage['contacts']], ['Enquiries', $engage['enquiries']], ['Booking / visit requests', $engage['bookings']]] as [$l, $v]): ?>
        <div class="col-6 col-md-4 col-xl-2"><?= $card($l, (int) $v) ?></div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Latest alerts</span>
                <a class="small" href="<?= e(url('/admin/notifications')) ?>">All notifications</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($latest as $n): ?>
                    <li class="list-group-item small<?= $n['read_at'] === null ? ' fw-semibold' : '' ?>">
                        <a class="text-reset" href="<?= e(url('/admin/notifications/' . (int) $n['id'])) ?>"><?= e($n['title']) ?></a>
                        <?php if ($n['body']): ?><div class="text-muted fw-normal"><?= e($n['body']) ?></div><?php endif; ?>
                        <div class="text-muted fw-normal"><?= e(format_ist($n['created_at'])) ?></div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$latest): ?><li class="list-group-item text-muted small">No alerts yet.</li><?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Recent login activity</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle small">
                    <thead class="table-light"><tr><th>When (IST)</th><th>User</th><th>Event</th><th>Area</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentLogins as $row): ?>
                        <tr>
                            <td class="text-nowrap"><?= e(format_ist($row['created_at'])) ?></td>
                            <td><?php if ($row['uid']): ?><a href="<?= e(url('/admin/users/' . (int) $row['uid'])) ?>"><?= e(trim($row['first_name'] . ' ' . $row['last_name'])) ?></a><?php else: ?><?= e((string) $row['identifier']) ?><?php endif; ?></td>
                            <td><span class="badge text-bg-<?= e($eventBadge[$row['event']] ?? 'secondary') ?>"><?= e(str_replace('_', ' ', $row['event'])) ?></span></td>
                            <td><?= e($row['context']) ?></td>
                            <td class="text-muted"><?= e((string) $row['ip_address']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentLogins): ?><tr><td colspan="5" class="text-muted text-center py-3">No activity yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
