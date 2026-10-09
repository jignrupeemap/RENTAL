<?php
/** @var array $filters @var array $rows @var int $total @var int $page @var int $perPage */
$this->layout('layouts/admin');
?>
<h1 class="h4 mb-3">Users</h1>
<form class="card border-0 shadow-sm mb-3" method="get" action="<?= e(url('/admin/users')) ?>">
    <div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-5">
            <label class="form-label small mb-1" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, mobile or email">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended', 'blocked' => 'Blocked', 'deleted' => 'Deleted'] as $k => $l): ?>
                    <option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="role">Role</label>
            <select class="form-select" id="role" name="role">
                <option value="">All</option>
                <?php foreach (['viewer' => 'Viewer', 'owner' => 'Owner', 'admin' => 'Admin'] as $k => $l): ?>
                    <option value="<?= $k ?>"<?= $filters['role'] === $k ? ' selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-3 d-flex gap-2">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-link" href="<?= e(url('/admin/users')) ?>">Clear</a>
        </div>
    </div>
</form>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 admin-table small">
            <thead class="table-light"><tr><th>Name</th><th>Mobile</th><th>Email</th><th>Roles</th><th>Status</th><th class="text-end">Listings</th><th>Last login</th><th>Joined</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $u): ?>
                <tr>
                    <td><a class="fw-semibold" href="<?= e(url('/admin/users/' . (int) $u['id'])) ?>"><?= e($u['first_name'] . ' ' . $u['last_name']) ?></a><?= $u['is_demo'] ? ' <span class="badge text-bg-light border">demo</span>' : '' ?></td>
                    <td class="text-nowrap"><?= e(format_mobile($u['mobile'])) ?></td>
                    <td><?= e((string) $u['email']) ?></td>
                    <td><?= e(str_replace(',', ', ', (string) $u['roles'])) ?></td>
                    <td><?= $u['deleted_at'] ? '<span class="badge text-bg-dark">Deleted</span>' : user_status_badge($u['status']) ?></td>
                    <td class="text-end"><?= (int) $u['listings'] ?> / <?= $u['property_limit_unlimited'] ? '∞' : (int) $u['property_limit'] ?></td>
                    <td class="text-nowrap text-muted"><?= $u['last_login_at'] ? e(format_ist($u['last_login_at'], 'd M Y')) : '—' ?></td>
                    <td class="text-nowrap text-muted"><?= e(format_ist($u['created_at'], 'd M Y')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No users match.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
