<?php
/** @var array $limit @var ?array $pending @var array $history */
$this->layout('layouts/owner', ['section' => 'limit']);
?>
<h1 class="h4 mb-3">Property Limit</h1>
<div class="row g-4">
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-4">
                <div class="text-muted small">Listings used</div>
                <div class="display-6 fw-semibold"><?= (int) $limit['used'] ?> / <?= $limit['unlimited'] ? 'Unlimited' : (int) $limit['limit'] ?></div>
                <?php if (!$limit['unlimited']): ?>
                    <div class="progress mt-2" role="progressbar" aria-label="Listings used" aria-valuenow="<?= (int) $limit['used'] ?>" aria-valuemin="0" aria-valuemax="<?= (int) $limit['limit'] ?>">
                        <div class="progress-bar<?= $limit['reached'] ? ' bg-danger' : '' ?>" style="width: <?= $limit['limit'] > 0 ? min(100, round($limit['used'] / $limit['limit'] * 100)) : 100 ?>%"></div>
                    </div>
                <?php endif; ?>
                <p class="small text-muted mt-3 mb-0">Every listing counts except deleted ones, including drafts.</p>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-4">
                <h2 class="h6">Request More Listings</h2>
                <?php if ($limit['unlimited']): ?>
                    <p class="text-muted mb-0">Your account has unlimited listings.</p>
                <?php elseif ($pending): ?>
                    <div class="alert alert-info mb-0">Your request for <strong><?= $pending['requested_unlimited'] ? 'unlimited listings' : (int) $pending['requested_limit'] . ' properties' ?></strong> was sent on <?= e(format_ist($pending['created_at'])) ?> and is waiting for admin review.</div>
                <?php else: ?>
                    <form method="post" action="<?= e(url('/owner/limit')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <p class="small mb-2">Current active properties: <strong><?= (int) $limit['used'] ?></strong> · current limit <strong><?= (int) $limit['limit'] ?></strong></p>
                        <div class="mb-3">
                            <label class="form-label" for="f_requested_limit">Requested limit</label>
                            <div class="d-flex flex-wrap gap-3 align-items-center">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="requested" id="req_n" value="number" <?= old('requested', 'number') !== 'unlimited' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="req_n">Number</label>
                                </div>
                                <input class="form-control w-auto<?= invalid('requested_limit') ?>" type="number" id="f_requested_limit" name="requested_limit"
                                       min="<?= (int) $limit['limit'] + 1 ?>" max="1000" value="<?= e(old('requested_limit', (string) max(10, (int) $limit['limit'] + 1))) ?>">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="requested" id="req_u" value="unlimited" <?= old('requested') === 'unlimited' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="req_u">Unlimited</label>
                                </div>
                            </div>
                            <?php if ($err = field_error('requested_limit')): ?><div class="text-danger small mt-1"><?= e($err) ?></div><?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="f_reason">Reason</label>
                            <textarea class="form-control" id="f_reason" name="reason" rows="3" maxlength="1000" required placeholder="For example: I manage 8 flats in the same society."><?= e(old('reason')) ?></textarea>
                        </div>
                        <button class="btn btn-primary" type="submit">Submit Request</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($history): ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white fw-semibold">Previous requests</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light"><tr><th>Date</th><th>Limit then</th><th>Requested</th><th>Status</th><th>Approved</th><th>Admin note</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td class="text-nowrap"><?= e(format_ist($h['created_at'], 'd M Y')) ?></td>
                        <td><?= (int) $h['current_limit'] ?></td>
                        <td><?= $h['requested_unlimited'] ? 'Unlimited' : (int) $h['requested_limit'] ?></td>
                        <td><?= status_badge($h['status']) ?></td>
                        <td><?= $h['status'] === 'approved' ? ($h['approved_unlimited'] ? 'Unlimited' : (int) $h['approved_limit']) : '—' ?></td>
                        <td><?= e((string) $h['admin_note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
