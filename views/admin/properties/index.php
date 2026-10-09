<?php
/**
 * @var array $filters @var array $rows @var int $total @var int $page @var int $perPage @var array $counts
 * @var array $categories @var array $cities @var array $localities @var ?array $ownerRow
 */
use App\Services\Admin\AdminPropertyService;
$this->layout('layouts/admin');
$all = array_sum(array_diff_key($counts, ['deleted' => 0]));
$tabs = [
    '' => ['All', $all], 'pending' => ['Pending', $counts['pending'] ?? 0], 'active' => ['Active', $counts['active'] ?? 0],
    'paused' => ['Paused', $counts['paused'] ?? 0], 'attention' => ['Rejected / changes', ($counts['rejected'] ?? 0) + ($counts['changes_requested'] ?? 0)],
    'draft' => ['Drafts', $counts['draft'] ?? 0], 'expired' => ['Expired', $counts['expired'] ?? 0], 'deleted' => ['Deleted', $counts['deleted'] ?? 0],
];
$current = $filters['status'];
$return = $_SERVER['REQUEST_URI'] ?? '/admin/properties';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><?= e($title) ?></h1>
    <a class="btn btn-primary" href="<?= e(url('/admin/properties/new')) ?>">+ Add Property</a>
</div>

<form class="card border-0 shadow-sm mb-3" method="get" action="<?= e(url('/admin/properties')) ?>">
    <div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="ID, title, city, locality, owner or mobile">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="purpose">Purpose</label>
            <select class="form-select" id="purpose" name="purpose">
                <option value="">All</option>
                <?php foreach (['rent' => 'Rent', 'pg' => 'PG', 'lease' => 'Lease'] as $k => $l): ?><option value="<?= $k ?>"<?= $filters['purpose'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="category">Category</label>
            <select class="form-select" id="category" name="category">
                <option value="">All</option>
                <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (string) $filters['category'] === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="city">City</label>
            <select class="form-select" id="city" name="city">
                <option value="">All</option>
                <?php foreach ($cities as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (string) $filters['city'] === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="locality">Locality</label>
            <select class="form-select" id="locality" name="locality">
                <option value="">All</option>
                <?php foreach ($cities as $c): ?>
                    <optgroup label="<?= e($c['name']) ?>">
                        <?php foreach ($localities as $l): if ((int) $l['city_id'] !== (int) $c['id']) continue; ?>
                            <option value="<?= (int) $l['id'] ?>"<?= (string) $filters['locality'] === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
            <?php if ($current !== ''): ?><input type="hidden" name="status" value="<?= e($current) ?>"><?php endif; ?>
            <?php if ($filters['owner'] !== ''): ?>
                <input type="hidden" name="owner" value="<?= e((string) $filters['owner']) ?>">
                <span class="badge text-bg-light border">Owner: <?= e($filters['owner'] === 'system' ? 'Admin / system' : ($ownerRow ? $ownerRow['first_name'] . ' ' . $ownerRow['last_name'] : '#' . $filters['owner'])) ?>
                    <a href="<?= e(url_with(['owner' => null, 'page' => null])) ?>" class="ms-1" aria-label="Remove owner filter">×</a></span>
            <?php endif; ?>
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="featured" name="featured" value="1"<?= $filters['featured'] ? ' checked' : '' ?>>
                <label class="form-check-label small" for="featured">Featured only</label>
            </div>
            <button class="btn btn-primary btn-sm" type="submit">Search</button>
            <a class="btn btn-link btn-sm" href="<?= e(url('/admin/properties' . ($current !== '' ? '?status=' . $current : ''))) ?>">Clear</a>
        </div>
    </div>
</form>

<?php $tabs = $tabs; require APP_ROOT . '/views/partials/tabs.php'; ?>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 admin-table small">
            <thead class="table-light">
                <tr><th></th><th>Listing</th><th>Location</th><th class="text-end">Rent</th><th>Owner</th><th>Status</th><th><?= $current === 'pending' ? 'Submitted' : 'Updated' ?></th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td style="width:72px">
                        <?php if ($r['file_key']): ?>
                            <img class="admin-thumb" src="<?= e(media_url($r, 'thumb')) ?>" alt="" loading="lazy" width="64" height="48">
                        <?php else: ?><div class="admin-thumb"></div><?php endif; ?>
                    </td>
                    <td>
                        <a class="fw-semibold" href="<?= e(url('/admin/properties/' . (int) $r['id'])) ?>"><?= e($r['title'] !== '' ? $r['title'] : 'Untitled draft') ?></a>
                        <?php if ($r['is_featured']): ?><span class="badge text-bg-warning ms-1">Featured</span><?php endif; ?>
                        <div class="text-muted">#<?= (int) $r['id'] ?> · <?= e(purpose_label($r['purpose']) . ' · ' . $r['category_name'] . ' › ' . $r['type_name']) ?></div>
                    </td>
                    <td><?= e(trim(($r['locality_name'] ?? '') . ($r['city_name'] ? ', ' . $r['city_name'] : ''), ', ') ?: '—') ?></td>
                    <td class="text-end text-nowrap">
                        <?= $r['rent'] !== null ? e(money_inr((int) $r['rent'])) : '—' ?>
                        <?php if ($r['pending_rent_change_id']): ?><div><a class="badge text-bg-info text-decoration-none" href="<?= e(url('/admin/rent-changes/' . (int) $r['pending_rent_change_id'])) ?>">Rent change</a></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['owner_id']): ?>
                            <a href="<?= e(url('/admin/users/' . (int) $r['owner_id'])) ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></a>
                            <div class="text-muted text-nowrap"><?= e(format_mobile($r['owner_mobile'])) ?><?= $r['owner_status'] !== 'active' ? ' · ' . e($r['owner_status']) : '' ?></div>
                        <?php else: ?><span class="text-muted">Admin / system</span><?php endif; ?>
                    </td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="text-nowrap text-muted"><?= e(format_ist($current === 'pending' ? $r['submitted_at'] : $r['updated_at'], 'd M Y')) ?></td>
                    <td class="text-end">
                        <div class="d-flex flex-wrap gap-1 justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/properties/' . (int) $r['id'])) ?>">View</a>
                            <?php if ($r['status'] !== 'deleted'): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/properties/' . (int) $r['id'] . '/edit')) ?>">Edit</a><?php endif; ?>
                            <?php if ($r['status'] === 'pending'): $p = $r; $allowed = array_intersect(AdminPropertyService::allowed($r), ['approve']); $compact = true; require APP_ROOT . '/views/admin/partials/property-actions.php'; endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No listings match.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
