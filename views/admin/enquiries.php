<?php
/** @var array $f @var array $rows @var int $total @var int $page @var int $perPage */
$this->layout('layouts/admin');
?>
<h1 class="h4 mb-3">Enquiries</h1>
<?php $back = $_SERVER['REQUEST_URI'] ?? '/admin/enquiries'; $src = $f['source'] ?? ''; ?>
<ul class="nav nav-pills small mb-3">
    <?php foreach (['' => 'All', 'property_page' => 'Property enquiries', 'general_form' => 'Requirements'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $src === $k ? ' active' : '' ?>" href="<?= e(url('/admin/enquiries' . ($k !== '' ? '?source=' . $k : ''))) ?>"><?= e($l) ?></a></li>
    <?php endforeach; ?>
</ul>
<?php $action = '/admin/enquiries'; $statuses = ['new' => 'New', 'contacted' => 'Contacted', 'in_progress' => 'In progress', 'closed' => 'Closed'];
$placeholder = 'Name, mobile, email, property title or ID'; if ($src !== '') { $f['hidden'] = ['source' => $src]; } require __DIR__ . '/partials/search-filter.php'; ?>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 admin-table small">
            <thead class="table-light"><tr><th>Date (IST)</th><th>Enquirer</th><th>Property</th><th>Owner</th><th class="text-end">Rent then</th><th>Message</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="text-nowrap"><?= e(format_ist($r['created_at'])) ?></td>
                    <td><?= $r['user_id'] ? '<a href="' . e(url('/admin/users/' . (int) $r['user_id'])) . '">' . e($r['first_name'] . ' ' . $r['last_name']) . '</a>' : e($r['first_name'] . ' ' . $r['last_name']) ?>
                        <div class="text-muted text-nowrap"><?= e(format_mobile($r['mobile_1'])) ?><?= $r['mobile_2'] ? ' · ' . e(format_mobile($r['mobile_2'])) : '' ?></div><?php if ($r['email']): ?><div class="text-muted text-break"><?= e($r['email']) ?></div><?php endif; ?></td>
                    <td><?php if ($r['property_id']): ?><a href="<?= e(url('/admin/properties/' . (int) $r['property_id'])) ?>">#<?= (int) $r['property_id'] ?> <?= e((string) $r['property_title']) ?></a>
                        <?php else: ?><span class="badge text-bg-light border">Requirement</span>
                            <div><?= e(implode(' · ', array_filter([$r['interested_in'] ? purpose_label($r['interested_in']) : null, $r['category_name'],
                                $r['budget'] !== null ? 'up to ' . money_inr((int) $r['budget']) : null, implode(', ', array_filter([$r['locality_name'], $r['city_name']]))]))) ?></div>
                        <?php endif; ?></td>
                    <td><?= $r['owner_id'] ? '<a href="' . e(url('/admin/users/' . (int) $r['owner_id'])) . '">' . e($r['owner_first'] . ' ' . $r['owner_last']) . '</a>' : '—' ?></td>
                    <td class="text-end"><?= $r['rent_at_enquiry'] !== null ? e(money_inr((int) $r['rent_at_enquiry'])) : '—' ?></td>
                    <td style="max-width:18rem"><?= e(mb_strimwidth((string) $r['message'], 0, 140, '…')) ?></td>
                    <td>
                        <form method="post" action="<?= e(url('/admin/enquiries/' . (int) $r['id'])) ?>" class="d-flex gap-1">
                            <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                            <select class="form-select form-select-sm" name="status" aria-label="Status of enquiry <?= (int) $r['id'] ?>">
                                <?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>"<?= $r['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No enquiries match.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
