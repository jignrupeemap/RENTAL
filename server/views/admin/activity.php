<?php
/** @var string $tab @var array $f @var array $rows @var int $total @var int $page @var int $perPage @var array $admins */
use App\Services\Admin\AdminLog;
$this->layout('layouts/admin');
?>
<h1 class="h4 mb-3">Activity log</h1>
<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link<?= $tab === 'admin' ? ' active' : '' ?>" href="<?= e(url('/admin/activity')) ?>">Admin actions</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'users' ? ' active' : '' ?>" href="<?= e(url('/admin/activity?tab=users')) ?>">User events</a></li>
</ul>
<form class="card border-0 shadow-sm mb-3" method="get" action="<?= e(url('/admin/activity')) ?>">
    <div class="card-body row g-2 align-items-end">
        <?php if ($tab === 'users'): ?>
            <input type="hidden" name="tab" value="users">
            <div class="col-12 col-md-4"><label class="form-label small mb-1" for="event">Event starts with</label><input class="form-control" id="event" name="event" value="<?= e($f['event']) ?>" placeholder="e.g. property., rent., user."></div>
        <?php else: ?>
            <div class="col-6 col-md-3"><label class="form-label small mb-1" for="admin">Admin</label>
                <select class="form-select" id="admin" name="admin"><option value="">All</option>
                    <?php foreach ($admins as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (int) $f['admin'] === (int) $a['id'] ? ' selected' : '' ?>><?= e($a['first_name'] . ' ' . $a['last_name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-6 col-md-3"><label class="form-label small mb-1" for="action">Action starts with</label><input class="form-control" id="action" name="action" value="<?= e($f['action']) ?>" placeholder="e.g. property., user."></div>
        <?php endif; ?>
        <div class="col-6 col-md-2"><label class="form-label small mb-1" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($f['from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($f['to']) ?>"></div>
        <div class="col-12 col-md-2"><button class="btn btn-primary" type="submit">Filter</button></div>
    </div>
</form>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 admin-table small">
            <thead class="table-light"><tr><th>When (IST)</th><th><?= $tab === 'admin' ? 'Admin' : 'User' ?></th><th><?= $tab === 'admin' ? 'Action' : 'Event' ?></th><th>On</th><th>Details</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $who = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                $entityUrl = match ($r['entity_type']) {
                    'property' => '/admin/properties/' . (int) $r['entity_id'], 'user' => '/admin/users/' . (int) $r['entity_id'],
                    'rent_change' => '/admin/rent-changes/' . (int) $r['entity_id'], 'limit_request' => '/admin/limit-requests/' . (int) $r['entity_id'],
                    default => null,
                };
                $details = $tab === 'admin' ? AdminLog::describe($r) : (string) $r['meta']; ?>
                <tr>
                    <td class="text-nowrap"><?= e(format_ist($r['created_at'])) ?></td>
                    <td><?= e($who !== '' ? $who : '—') ?></td>
                    <td><code><?= e($tab === 'admin' ? $r['action'] : $r['event']) ?></code></td>
                    <td class="text-nowrap"><?= $r['entity_type'] ? ($entityUrl && $r['entity_id'] ? '<a href="' . e(url($entityUrl)) . '">' . e($r['entity_type']) . ' #' . (int) $r['entity_id'] . '</a>' : e($r['entity_type'])) : '—' ?></td>
                    <td style="max-width:28rem"><?= e(mb_strimwidth($details, 0, 300, '…')) ?></td>
                    <td class="text-muted"><?= e((string) $r['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">Nothing logged yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
