<?php
/** @var ?string $status @var array $rows @var int $total @var int $page @var int $perPage @var array $counts @var array $history */
use App\Services\BookingService;
$this->layout('layouts/owner', ['section' => 'bookings']);
$tabs = ['' => ['All', $counts['total']], 'pending' => ['Waiting', $counts['pending']], 'accepted' => ['Accepted', $counts['accepted']],
    'rescheduled' => ['Rescheduled', $counts['rescheduled']], 'closed' => ['Closed', $counts['closed']]];
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$buttons = ['accept' => ['Accept', 'btn-success'], 'reject' => ['Decline', 'btn-outline-danger'],
    'complete' => ['Mark completed', 'btn-outline-dark'], 'cancel' => ['Cancel visit', 'btn-outline-secondary']];
?>
<h1 class="h4 mb-3">Booking / Visit Requests</h1>
<?php $current = $status; require APP_ROOT . '/views/partials/tabs.php'; ?>

<?php if (!$rows): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">No visit requests here yet.</div></div>
<?php else: ?>
    <div class="vstack gap-3">
        <?php foreach ($rows as $b): $actions = BookingService::OWNER_ACTIONS[$b['status']] ?? []; ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                        <div><strong><?= e($b['first_name'] . ' ' . $b['last_name']) ?></strong> <span class="ms-2"><?= status_badge($b['status']) ?></span></div>
                        <span class="small text-muted">Requested <?= e(format_ist($b['created_at'])) ?></span>
                    </div>
                    <div class="row small g-2 mb-2">
                        <div class="col-md-6">
                            <div class="text-muted">Property</div>
                            <div><?= e($b['property_title']) ?><?= $b['rent_at_request'] !== null ? ' · ' . e(money_inr((int) $b['rent_at_request'])) : '' ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted">Preferred</div>
                            <div class="fw-semibold"><?= e(date('d M Y', strtotime($b['preferred_date']))) ?>, <?= e(date('h:i A', strtotime($b['preferred_time']))) ?></div>
                        </div>
                        <?php if ($b['proposed_date']): ?>
                            <div class="col-md-3">
                                <div class="text-muted">Your proposed time</div>
                                <div class="fw-semibold"><?= e(date('d M Y', strtotime($b['proposed_date']))) ?>, <?= e(date('h:i A', strtotime((string) $b['proposed_time']))) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex flex-wrap gap-3 small mb-2">
                        <a href="tel:+<?= e($b['mobile']) ?>"><?= e(format_mobile($b['mobile'])) ?></a>
                        <?php if ($b['email']): ?><a class="text-break" href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a><?php endif; ?>
                    </div>
                    <?php if ($b['message']): ?><p class="small bg-light rounded p-2 mb-2"><?= nl2br(e($b['message'])) ?></p><?php endif; ?>
                    <?php if ($b['owner_response']): ?><p class="small mb-2"><span class="text-muted">Your note:</span> <?= e($b['owner_response']) ?></p><?php endif; ?>

                    <?php if ($actions): ?>
                        <div class="d-flex flex-wrap gap-2 align-items-start">
                            <?php foreach (array_diff($actions, ['reschedule']) as $a): ?>
                                <form method="post" action="<?= e(url('/owner/bookings/' . (int) $b['id'])) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="<?= e($a) ?>">
                                    <input type="hidden" name="tab" value="<?= e((string) $status) ?>">
                                    <button class="btn btn-sm <?= e($buttons[$a][1]) ?>" type="submit"><?= e($buttons[$a][0]) ?></button>
                                </form>
                            <?php endforeach; ?>
                            <?php if (in_array('reschedule', $actions, true)): ?>
                                <details class="reschedule">
                                    <summary class="btn btn-sm btn-outline-primary">Propose new time</summary>
                                    <form class="row g-2 mt-1" method="post" action="<?= e(url('/owner/bookings/' . (int) $b['id'])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reschedule">
                                        <input type="hidden" name="tab" value="<?= e((string) $status) ?>">
                                        <div class="col-6"><input class="form-control form-control-sm" type="date" name="date" min="<?= e($today) ?>" required aria-label="New date"></div>
                                        <div class="col-6"><input class="form-control form-control-sm" type="time" name="time" required aria-label="New time"></div>
                                        <div class="col-12"><input class="form-control form-control-sm" name="note" maxlength="500" placeholder="Message to the visitor (optional)"></div>
                                        <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Send new time</button></div>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($history[(int) $b['id']])): ?>
                        <details class="mt-2 small">
                            <summary class="text-muted">History</summary>
                            <ul class="list-unstyled mt-2 mb-0 timeline">
                                <?php foreach ($history[(int) $b['id']] as $h): ?>
                                    <li><span class="text-muted"><?= e(format_ist($h['created_at'])) ?></span> · <?= e(ucfirst($h['actor_role'])) ?>: <?= e(str_replace('_', ' ', (string) $h['old_status'])) ?> → <?= e(str_replace('_', ' ', $h['new_status'])) ?><?= $h['note'] ? ' · ' . e($h['note']) : '' ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
<?php endif; ?>
