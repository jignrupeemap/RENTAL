<?php
/**
 * @var array $u @var ?string $guard @var array $usage @var array $counts @var array $properties
 * @var array $logins @var array $activity @var array $adminLog @var ?array $limitRequest
 */
use App\Services\Admin\AdminLog;
$this->layout('layouts/admin');
$uid = (int) $u['id'];
$deleted = $u['deleted_at'] !== null;
$mob = static fn (?string $m): string => $m !== null && strlen($m) === 12 ? substr($m, 2) : (string) $m;
$v = static fn (string $f) => old($f, (string) ($f === 'mobile' ? $mob($u['mobile']) : ($u[$f] ?? '')));
$statusActions = ['active' => ['Activate', 'btn-success'], 'inactive' => ['Deactivate', 'btn-outline-secondary'],
    'suspended' => ['Suspend', 'btn-outline-warning'], 'blocked' => ['Block', 'btn-outline-danger']];
?>
<nav class="small mb-2"><a href="<?= e(url('/admin/users')) ?>">&larr; Users</a></nav>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><?= e($u['first_name'] . ' ' . $u['last_name']) ?></h1>
        <div class="small text-muted d-flex flex-wrap gap-2 align-items-center">
            <?= $deleted ? '<span class="badge text-bg-dark">Deleted</span>' : user_status_badge($u['status']) ?>
            <span>#<?= $uid ?></span><span><?= e(implode(', ', $u['roles'])) ?></span>
            <span>Joined <?= e(format_ist($u['created_at'], 'd M Y')) ?></span>
            <?php if ($u['is_demo']): ?><span class="badge text-bg-light border">demo account</span><?php endif; ?>
        </div>
        <?php if ($u['status_reason']): ?><div class="small mt-1">Reason: <?= e($u['status_reason']) ?></div><?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url('/admin/properties?owner=' . $uid)) ?>">Listings (<?= count($properties) ?>)</a>
        <?php if (!$deleted): ?><a class="btn btn-primary" href="<?= e(url('/admin/properties/new?owner=' . $uid)) ?>">+ Add property for this user</a><?php endif; ?>
    </div>
</div>

<?php if ($guard && !$deleted): ?><div class="alert alert-secondary small"><?= e($guard) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Account status</div>
            <div class="card-body">
                <?php if ($deleted): ?>
                    <p class="small">Deleted <?= e(format_ist($u['deleted_at'])) ?>. Data is kept; the account cannot sign in.</p>
                    <?php if (!$guard): ?>
                        <form method="post" action="<?= e(url('/admin/users/' . $uid . '/restore')) ?>"><?= csrf_field() ?><button class="btn btn-outline-primary btn-sm" type="submit">Restore account</button></form>
                    <?php endif; ?>
                <?php elseif (!$guard): ?>
                    <form method="post" action="<?= e(url('/admin/users/' . $uid . '/status')) ?>">
                        <?= csrf_field() ?>
                        <label class="form-label small mb-1" for="reason">Reason (shown to the user; needed except for Activate)</label>
                        <input class="form-control form-control-sm mb-2" id="reason" name="reason" maxlength="255" value="<?= e((string) $u['status_reason']) ?>">
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($statusActions as $st => [$label, $class]): if ($st === $u['status']) continue; ?>
                                <button class="btn btn-sm <?= $class ?>" type="submit" name="status" value="<?= $st ?>"><?= $st === 'active' && $u['status'] === 'blocked' ? 'Unblock' : $label ?></button>
                            <?php endforeach; ?>
                        </div>
                    </form>
                    <p class="small text-muted mt-2 mb-0">Inactive and suspended accounts can sign in and browse but cannot post, edit, shortlist or contact. Blocked accounts are signed out and cannot sign in.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Property posting limit</div>
            <div class="card-body">
                <p class="mb-2"><strong><?= (int) $usage['used'] ?> / <?= $usage['unlimited'] ? 'Unlimited' : (int) $usage['limit'] ?></strong> listings used</p>
                <?php if ($limitRequest): ?>
                    <div class="alert alert-info small py-2">Waiting request for <?= $limitRequest['requested_unlimited'] ? 'Unlimited' : (int) $limitRequest['requested_limit'] ?>: <a href="<?= e(url('/admin/limit-requests/' . (int) $limitRequest['id'])) ?>">review</a></div>
                <?php endif; ?>
                <?php if (!$deleted): ?>
                    <form method="post" action="<?= e(url('/admin/users/' . $uid . '/limit')) ?>" class="d-flex flex-wrap gap-2 align-items-end">
                        <?= csrf_field() ?>
                        <div>
                            <label class="form-label small mb-1" for="property_limit">Limit</label>
                            <input class="form-control form-control-sm" style="width:6rem" type="number" min="0" max="<?= \App\Services\PropertyLimitService::MAX_REQUESTABLE ?>" id="property_limit" name="property_limit" value="<?= (int) $u['property_limit'] ?>">
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" id="unlimited" name="unlimited" value="1"<?= $u['property_limit_unlimited'] ? ' checked' : '' ?>>
                            <label class="form-check-label small" for="unlimited">Unlimited</label>
                        </div>
                        <button class="btn btn-sm btn-outline-primary" type="submit">Save limit</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Activity</div>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('/admin/enquiries?user=' . $uid)) ?>">Enquiries sent</a><span><?= $counts['enquiries_sent'] ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('/admin/enquiries?owner=' . $uid)) ?>">Enquiries received</a><span><?= $counts['enquiries_received'] ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('/admin/bookings?user=' . $uid)) ?>">Visit requests made</a><span><?= $counts['bookings_made'] ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('/admin/bookings?owner=' . $uid)) ?>">Visit requests received</a><span><?= $counts['bookings_received'] ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Shortlisted</span><span><?= $counts['shortlists'] ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Logins (last <?= $u['last_login_at'] ? e(format_ist($u['last_login_at'])) : 'never' ?>)</span><span><?= (int) $u['login_count'] ?></span></li>
                <li class="list-group-item d-flex justify-content-between align-items-center"><span>Signed-in devices: <?= $counts['sessions'] ?></span>
                    <?php if ($counts['sessions'] > 0 && !$guard): ?>
                        <form method="post" action="<?= e(url('/admin/users/' . $uid . '/signout')) ?>" class="m-0"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" type="submit">Sign out everywhere</button></form>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>

    <div class="col-xl-8 min-w-0">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Profile</div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/admin/users/' . $uid)) ?>">
                    <?= csrf_field() ?>
                    <fieldset<?= $guard && $uid !== (int) \App\Core\Auth::id() ? ' disabled' : '' ?>>
                        <div class="row g-2">
                            <div class="col-md-6"><label class="form-label small mb-1" for="first_name">First name</label><input class="form-control<?= invalid('first_name') ?>" id="first_name" name="first_name" value="<?= e($v('first_name')) ?>" maxlength="80"><?= field_error('first_name') ? '<div class="invalid-feedback">' . e(field_error('first_name')) . '</div>' : '' ?></div>
                            <div class="col-md-6"><label class="form-label small mb-1" for="last_name">Last name</label><input class="form-control<?= invalid('last_name') ?>" id="last_name" name="last_name" value="<?= e($v('last_name')) ?>" maxlength="80"><?= field_error('last_name') ? '<div class="invalid-feedback">' . e(field_error('last_name')) . '</div>' : '' ?></div>
                            <div class="col-md-6"><label class="form-label small mb-1" for="mobile">Mobile <?= $u['mobile_verified_at'] ? '<span class="badge text-bg-success">verified</span>' : '' ?></label><div class="input-group"><span class="input-group-text">+91</span><input class="form-control<?= invalid('mobile') ?>" id="mobile" name="mobile" value="<?= e($v('mobile')) ?>" inputmode="numeric"></div><?= field_error('mobile') ? '<div class="invalid-feedback d-block">' . e(field_error('mobile')) . '</div>' : '' ?></div>
                            <div class="col-md-6"><label class="form-label small mb-1" for="email">Email <?= $u['email_verified_at'] ? '<span class="badge text-bg-success">verified</span>' : '' ?></label><input class="form-control<?= invalid('email') ?>" id="email" name="email" type="email" value="<?= e($v('email')) ?>"><?= field_error('email') ? '<div class="invalid-feedback">' . e(field_error('email')) . '</div>' : '' ?></div>
                        </div>
                        <button class="btn btn-outline-primary btn-sm mt-3" type="submit">Save profile</button>
                    </fieldset>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Listings</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 admin-table small">
                    <tbody>
                    <?php foreach ($properties as $p): ?>
                        <tr><td><a href="<?= e(url('/admin/properties/' . (int) $p['id'])) ?>">#<?= (int) $p['id'] ?> <?= e($p['title'] !== '' ? $p['title'] : 'Untitled draft') ?></a><div class="text-muted"><?= e(purpose_label($p['purpose']) . ' · ' . $p['type_name']) ?></div></td>
                            <td class="text-end"><?= $p['rent'] !== null ? e(money_inr((int) $p['rent'])) : '—' ?></td><td><?= status_badge($p['status']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$properties): ?><tr><td class="text-muted">No listings.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">Login history</div>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($logins as $l): ?>
                            <li class="list-group-item"><?= e(str_replace('_', ' ', $l['event'])) ?> · <?= e($l['context']) ?><div class="text-muted"><?= e(format_ist($l['created_at'])) ?> · <?= e((string) $l['ip_address']) ?></div></li>
                        <?php endforeach; ?>
                        <?php if (!$logins): ?><li class="list-group-item text-muted">No logins yet.</li><?php endif; ?>
                    </ul>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">Recent activity</div>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($activity as $a): ?>
                            <li class="list-group-item"><?= e($a['event']) ?><?= $a['entity_id'] ? ' · ' . e($a['entity_type']) . ' #' . (int) $a['entity_id'] : '' ?><div class="text-muted"><?= e(format_ist($a['created_at'])) ?></div></li>
                        <?php endforeach; ?>
                        <?php if (!$activity): ?><li class="list-group-item text-muted">Nothing yet.</li><?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-3">
            <div class="card-header bg-white fw-semibold">Admin changes to this account</div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($adminLog as $a): ?>
                    <li class="list-group-item"><strong><?= e($a['action']) ?></strong> · <?= e(AdminLog::describe($a)) ?><div class="text-muted"><?= e(format_ist($a['created_at'])) ?> · <?= e(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''))) ?></div></li>
                <?php endforeach; ?>
                <?php if (!$adminLog): ?><li class="list-group-item text-muted">None yet.</li><?php endif; ?>
            </ul>
        </div>

        <?php if (!$deleted && !$guard): ?>
            <form method="post" action="<?= e(url('/admin/users/' . $uid . '/delete')) ?>" class="mt-3 text-end" data-confirm="Delete this account? They are signed out and cannot sign in. Their data is kept and the account can be restored.">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-danger" type="submit">Delete account</button>
            </form>
        <?php endif; ?>
    </div>
</div>
