<?php
/** @var ?string $status @var string $search @var array $rows @var int $total @var int $page @var int $perPage @var array $counts */
$this->layout('layouts/owner', ['section' => 'enquiries']);
$tabs = ['' => ['All', $counts['total']], 'new' => ['New', $counts['new']], 'contacted' => ['Contacted', $counts['contacted']],
    'in_progress' => ['In progress', $counts['in_progress']], 'closed' => ['Closed', $counts['closed']]];
$labels = ['new' => 'New', 'contacted' => 'Contacted', 'in_progress' => 'In progress', 'closed' => 'Closed'];
$back = $_SERVER['REQUEST_URI'] ?? '/owner/enquiries';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Enquiries</h1>
    <form class="d-flex gap-2" method="get" action="<?= e(url('/owner/enquiries')) ?>" role="search">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <input class="form-control form-control-sm" type="search" name="q" value="<?= e($search) ?>" placeholder="Name, mobile, email, property" aria-label="Search enquiries">
        <button class="btn btn-sm btn-outline-primary" type="submit">Search</button>
    </form>
</div>
<?php $current = $status; require APP_ROOT . '/views/partials/tabs.php'; ?>

<?php if (!$rows): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5"><?= $search !== '' ? 'No enquiries match your search.' : 'No enquiries here yet.' ?></div></div>
<?php else: ?>
    <div class="vstack gap-3">
        <?php foreach ($rows as $q): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                        <div>
                            <strong><?= e($q['first_name'] . ' ' . $q['last_name']) ?></strong>
                            <span class="ms-2"><?= status_badge($q['status']) ?></span>
                        </div>
                        <span class="small text-muted"><?= e(format_ist($q['created_at'])) ?></span>
                    </div>
                    <div class="d-flex flex-wrap gap-3 small mb-2">
                        <a href="tel:+<?= e($q['mobile_1']) ?>"><?= e(format_mobile($q['mobile_1'])) ?></a>
                        <?php if ($q['mobile_2']): ?><a href="tel:+<?= e($q['mobile_2']) ?>"><?= e(format_mobile($q['mobile_2'])) ?></a><?php endif; ?>
                        <?php if ($q['email']): ?><a class="text-break" href="mailto:<?= e($q['email']) ?>"><?= e($q['email']) ?></a><?php endif; ?>
                    </div>
                    <div class="small mb-2">
                        <span class="text-muted">Property:</span>
                        <?php if ($q['property_id'] && $q['property_status'] === 'active'): ?>
                            <a href="<?= e(url(property_url(['id' => $q['property_id'], 'purpose' => $q['purpose'], 'slug' => $q['slug']]))) ?>" target="_blank" rel="noopener"><?= e((string) $q['property_title']) ?></a>
                        <?php else: ?>
                            <?= e((string) ($q['property_title'] ?: 'General enquiry')) ?>
                        <?php endif; ?>
                        <?php if ($q['rent_at_enquiry'] !== null): ?><span class="text-muted">· rent at enquiry <?= e(money_inr((int) $q['rent_at_enquiry'])) ?></span><?php endif; ?>
                        <?php if ($q['booking_id']): ?> · <a href="<?= e(url('/owner/bookings')) ?>">has a visit request</a><?php endif; ?>
                    </div>
                    <?php if ($q['message']): ?><p class="small bg-light rounded p-2 mb-2 enquiry-message"><?= nl2br(e($q['message'])) ?></p><?php endif; ?>
                    <form class="row g-2 align-items-end" method="post" action="<?= e(url('/owner/enquiries/' . (int) $q['id'])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="back" value="<?= e($back) ?>">
                        <div class="col-sm-4 col-md-3">
                            <label class="form-label small mb-1" for="st<?= (int) $q['id'] ?>">Status</label>
                            <select class="form-select form-select-sm" id="st<?= (int) $q['id'] ?>" name="status">
                                <?php foreach ($labels as $k => $l): ?><option value="<?= e($k) ?>"<?= $q['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-8 col-md-7">
                            <label class="form-label small mb-1" for="nt<?= (int) $q['id'] ?>">Private note</label>
                            <input class="form-control form-control-sm" id="nt<?= (int) $q['id'] ?>" name="owner_note" maxlength="500" value="<?= e((string) $q['owner_note']) ?>" placeholder="Only you can see this">
                        </div>
                        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100" type="submit">Save</button></div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
<?php endif; ?>
