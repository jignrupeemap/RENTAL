<?php
/** @var array $f @var array $rows @var int $total @var int $page @var int $perPage */
$this->layout('layouts/admin');
?>
<h1 class="h4 mb-3">Booking / visit requests</h1>
<?php $action = '/admin/bookings'; $statuses = ['pending' => 'Pending', 'accepted' => 'Accepted', 'rescheduled' => 'Rescheduled', 'rejected' => 'Rejected', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$placeholder = 'Viewer name or mobile, property title or ID'; require __DIR__ . '/partials/search-filter.php'; ?>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 admin-table small">
            <thead class="table-light"><tr><th>Requested (IST)</th><th>Viewer</th><th>Property</th><th>Owner</th><th>Preferred visit</th><th>Message</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="text-nowrap"><?= e(format_ist($r['created_at'])) ?></td>
                    <td><a href="<?= e(url('/admin/users/' . (int) $r['user_id'])) ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></a><div class="text-muted"><?= e(format_mobile($r['mobile'])) ?></div></td>
                    <td><a href="<?= e(url('/admin/properties/' . (int) $r['property_id'])) ?>">#<?= (int) $r['property_id'] ?> <?= e($r['property_title']) ?></a></td>
                    <td><?= $r['owner_id'] ? '<a href="' . e(url('/admin/users/' . (int) $r['owner_id'])) . '">' . e($r['owner_first'] . ' ' . $r['owner_last']) . '</a>' : '—' ?></td>
                    <td class="text-nowrap"><?= e(date('d M Y', strtotime($r['preferred_date']))) ?>, <?= e(date('h:i A', strtotime($r['preferred_time']))) ?>
                        <?php if ($r['proposed_date']): ?><div class="text-muted">Proposed <?= e(date('d M Y', strtotime($r['proposed_date']))) ?><?= $r['proposed_time'] ? ', ' . e(date('h:i A', strtotime($r['proposed_time']))) : '' ?></div><?php endif; ?></td>
                    <td style="max-width:16rem"><?= e(mb_strimwidth((string) $r['message'], 0, 120, '…')) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No requests match.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
