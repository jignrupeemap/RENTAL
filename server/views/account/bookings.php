<?php
/**
 * Booking / Visit Requests (spec 28) from the viewer's side: status, the
 * owner's reply or proposed time, history; Cancel and Accept new time.
 * @var ?string $status @var array $rows @var int $total @var int $page @var int $perPage @var array $tabs @var array $history
 */
use App\Services\BookingService;
$this->layout('layouts/account', ['section' => 'bookings']);
$labels = ['accept_time' => ['Accept new time', 'btn-success'], 'cancel' => ['Cancel request', 'btn-outline-secondary']];
?>
<h1 class="h4 mb-3">Booking / Visit Requests</h1>
<?php $current = $status; require APP_ROOT . '/views/partials/tabs.php'; ?>

<?php if (!$rows): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5 text-muted">No visit requests here.</div></div>
<?php else: ?>
    <div class="vstack gap-3">
        <?php foreach ($rows as $b): $actions = BookingService::VIEWER_ACTIONS[$b['status']] ?? []; ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                        <div class="fw-semibold"><?= e($b['property_title']) ?> <span class="ms-1"><?= status_badge($b['status']) ?></span></div>
                        <span class="small text-muted">Requested <?= e(format_ist($b['created_at'])) ?></span>
                    </div>
                    <div class="row small g-2 mb-2">
                        <div class="col-sm-4">
                            <div class="text-muted"><?= $b['status'] === 'accepted' ? 'Visit time' : 'Your preferred time' ?></div>
                            <div class="fw-semibold"><?= e(date('d M Y', strtotime($b['preferred_date']))) ?>, <?= e(date('h:i A', strtotime($b['preferred_time']))) ?></div>
                        </div>
                        <?php if ($b['status'] === 'rescheduled' && $b['proposed_date']): ?>
                            <div class="col-sm-4">
                                <div class="text-muted">Owner proposed</div>
                                <div class="fw-semibold text-primary"><?= e(date('d M Y', strtotime($b['proposed_date']))) ?>, <?= e(date('h:i A', strtotime((string) $b['proposed_time']))) ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="col-sm-4">
                            <div class="text-muted">Place</div>
                            <div><?= e(implode(', ', array_filter([$b['locality_label'], $b['city_name']]))) ?><?= $b['rent_at_request'] !== null ? ' · ' . e(money_inr((int) $b['rent_at_request'])) : '' ?></div>
                        </div>
                    </div>
                    <?php if ($b['message']): ?><p class="small bg-light rounded p-2 mb-2 text-break"><span class="text-muted">Your message:</span> <?= e($b['message']) ?></p><?php endif; ?>
                    <?php if ($b['owner_response']): ?><p class="small mb-2"><span class="text-muted">Owner's note:</span> <?= e($b['owner_response']) ?></p><?php endif; ?>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <?php $live = $b['is_live']; $pstatus = $b['property_status']; $plink = ['id' => $b['property_id'], 'purpose' => $b['purpose'], 'slug' => $b['slug']]; require APP_ROOT . '/views/partials/listing-state.php'; ?>
                        <?php foreach ($actions as $a): ?>
                            <form method="post" action="<?= e(url('/account/bookings/' . (int) $b['id'])) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="<?= e($a) ?>">
                                <input type="hidden" name="tab" value="<?= e((string) $status) ?>">
                                <button class="btn btn-sm <?= e($labels[$a][1]) ?>" type="submit"><?= e($labels[$a][0]) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($history[(int) $b['id']])): ?>
                        <details class="mt-2 small">
                            <summary class="text-muted">History</summary>
                            <ul class="list-unstyled mt-2 mb-0 timeline">
                                <?php foreach ($history[(int) $b['id']] as $h): ?>
                                    <li><span class="text-muted"><?= e(format_ist($h['created_at'])) ?></span> · <?= e(['viewer' => 'You', 'owner' => 'Owner', 'admin' => 'Admin', 'system' => 'System'][$h['actor_role']] ?? $h['actor_role']) ?>: <?= e(str_replace('_', ' ', (string) $h['old_status'])) ?> → <?= e(str_replace('_', ' ', $h['new_status'])) ?><?= $h['note'] ? ' · ' . e($h['note']) : '' ?></li>
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
