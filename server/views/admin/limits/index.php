<?php
/** @var string $status @var ?int $highlight @var array $rows @var int $total @var int $page @var int $perPage @var int $pendingCount @var int $max */
$this->layout('layouts/admin');
?>
<h1 class="h4 mb-3">Limit requests</h1>
<ul class="nav nav-pills mb-3">
    <?php foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected', '' => 'All'] as $k => $label): ?>
        <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/limit-requests?status=' . $k)) ?>"><?= e($label) ?><?= $k === 'pending' ? ' <span class="badge rounded-pill tab-count">' . $pendingCount . '</span>' : '' ?></a></li>
    <?php endforeach; ?>
</ul>
<div class="row g-3">
    <?php foreach ($rows as $r): $rid = (int) $r['id']; ?>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100<?= $highlight === $rid ? ' border border-2 border-primary' : '' ?>" id="request-<?= $rid ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <a class="fw-semibold" href="<?= e(url('/admin/users/' . (int) $r['user_id'])) ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></a>
                            <div class="small text-muted"><?= e(format_mobile($r['mobile'])) ?><?= $r['email'] ? ' · ' . e($r['email']) : '' ?></div>
                        </div>
                        <?= status_badge($r['status']) ?>
                    </div>
                    <dl class="row small mb-2">
                        <dt class="col-5">Current limit</dt><dd class="col-7"><?= $r['property_limit_unlimited'] ? 'Unlimited' : (int) $r['property_limit'] ?> (<?= (int) $r['used_now'] ?> used)</dd>
                        <dt class="col-5">Requested limit</dt><dd class="col-7 fw-semibold"><?= $r['requested_unlimited'] ? 'Unlimited' : (int) $r['requested_limit'] ?></dd>
                        <dt class="col-5">Date</dt><dd class="col-7"><?= e(format_ist($r['created_at'])) ?></dd>
                        <dt class="col-5">Reason</dt><dd class="col-7"><?= nl2br(e($r['reason'])) ?></dd>
                        <?php if ($r['status'] !== 'pending'): ?>
                            <dt class="col-5">Decision</dt><dd class="col-7"><?= $r['status'] === 'approved' ? 'Granted ' . ($r['approved_unlimited'] ? 'Unlimited' : (int) $r['approved_limit']) : 'Rejected' ?><?= $r['reviewed_at'] ? ' · ' . e(format_ist($r['reviewed_at'])) : '' ?><?= $r['admin_note'] ? '<br>' . e($r['admin_note']) : '' ?></dd>
                        <?php endif; ?>
                    </dl>
                    <?php if ($r['status'] === 'pending'): ?>
                        <form method="post" action="<?= e(url('/admin/limit-requests/' . $rid)) ?>" class="border-top pt-2">
                            <?= csrf_field() ?>
                            <div class="d-flex flex-wrap gap-2 align-items-end mb-2">
                                <div>
                                    <label class="form-label small mb-1" for="lim_<?= $rid ?>">New limit</label>
                                    <input class="form-control form-control-sm" style="width:6rem" type="number" min="1" max="<?= $max ?>" id="lim_<?= $rid ?>" name="approved_limit" value="<?= $r['requested_unlimited'] ? '' : (int) $r['requested_limit'] ?>">
                                </div>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" id="unl_<?= $rid ?>" name="unlimited" value="1"<?= $r['requested_unlimited'] ? ' checked' : '' ?>>
                                    <label class="form-check-label small" for="unl_<?= $rid ?>">Unlimited</label>
                                </div>
                            </div>
                            <label class="form-label small mb-1" for="note_<?= $rid ?>">Note for the owner (required to reject)</label>
                            <input class="form-control form-control-sm mb-2" id="note_<?= $rid ?>" name="note" maxlength="500">
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-success" type="submit" name="decision" value="approve">Approve</button>
                                <button class="btn btn-sm btn-outline-danger" type="submit" name="decision" value="reject">Reject</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-muted text-center">No requests here.</div></div></div><?php endif; ?>
</div>
<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
