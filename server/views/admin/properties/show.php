<?php
/**
 * @var array $p @var array $type @var array $allowed @var array $missing @var array $steps @var array $history
 * @var ?array $pending @var array $rentLog @var array $adminLog @var array $preview
 */
use App\Services\Admin\AdminLog;
$this->layout('layouts/admin');
$pid = (int) $p['id'];
$isDeleted = $p['status'] === 'deleted';
$editBase = $isDeleted ? null : url('/admin/properties/' . $pid . '/step/');
$today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$featuredUntil = $p['featured_until'] ? format_ist($p['featured_until'], 'Y-m-d') : '';
?>
<nav class="small mb-2"><a href="<?= e(url('/admin/properties')) ?>">&larr; Properties</a></nav>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><?= e($p['title'] !== '' ? $p['title'] : 'Untitled draft') ?></h1>
        <div class="d-flex flex-wrap align-items-center gap-2 small text-muted">
            <?= status_badge($p['status']) ?>
            <span>#<?= $pid ?></span>
            <span><?= e(purpose_label($p['purpose']) . ' · ' . $p['category_name'] . ' › ' . $p['type_name']) ?></span>
            <?php if ($p['is_featured']): ?><span class="badge text-bg-warning">Featured</span><?php endif; ?>
        </div>
    </div>
    <?php if (!$isDeleted): ?><a class="btn btn-outline-primary" href="<?= e(url('/admin/properties/' . $pid . '/edit')) ?>">Edit listing</a><?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <?php if ($missing && array_intersect($allowed, ['approve', 'activate', 'publish'])): ?>
            <div class="alert alert-warning small">
                <div class="fw-semibold">This listing cannot go live until these are filled in:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($missing as $n => $labels): ?>
                        <li><a href="<?= e(url('/admin/properties/' . $pid . '/step/' . $n)) ?>"><?= e($steps[$n]) ?></a>: <?= e(implode(', ', $labels)) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ($p['rejection_reason'] && in_array($p['status'], ['rejected', 'changes_requested'], true)): ?>
            <p class="small mb-2"><strong>Note sent to owner:</strong> <?= e($p['rejection_reason']) ?></p>
        <?php endif; ?>
        <?php if ($allowed): ?>
            <?php require APP_ROOT . '/views/admin/partials/property-actions.php'; ?>
        <?php else: ?>
            <p class="text-muted small mb-0">No status actions for a <?= e(str_replace('_', ' ', $p['status'])) ?> listing. <?= $p['status'] === 'draft' ? 'The owner has not submitted it yet.' : '' ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8 min-w-0">
        <?php if ($pending): ?>
            <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>Rent change waiting: <?= e(money_inr((int) $pending['old_rent'])) ?> → <strong><?= e(money_inr((int) $pending['new_rent'])) ?></strong></span>
                <a class="btn btn-sm btn-primary" href="<?= e(url('/admin/rent-changes/' . (int) $pending['id'])) ?>">Review</a>
            </div>
        <?php endif; ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <?php require APP_ROOT . '/views/partials/property-detail.php'; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Owner &amp; contact</div>
            <div class="card-body small">
                <dl class="kv mb-2">
                    <dt>Account</dt>
                    <dd>
                        <?php if ($p['owner_id']): ?>
                            <a href="<?= e(url('/admin/users/' . (int) $p['owner_id'])) ?>"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></a>
                            · <?= e(format_mobile($p['owner_mobile'])) ?><?= $p['owner_status'] !== 'active' ? ' · <span class="text-danger">' . e($p['owner_status']) . '</span>' : '' ?>
                        <?php else: ?>Admin / system<?php endif; ?>
                    </dd>
                    <dt>Shown to enquirers</dt>
                    <dd><?= e((string) $p['contact_name']) ?: '—' ?><?= $p['contact_mobile_1'] ? ' · ' . e(format_mobile($p['contact_mobile_1'])) : '' ?><?= $p['contact_mobile_2'] ? ', ' . e(format_mobile($p['contact_mobile_2'])) : '' ?><?= $p['contact_email'] ? '<br>' . e($p['contact_email']) : '' ?></dd>
                    <dt>Created</dt>
                    <dd><?= e(format_ist($p['created_at'])) ?> by <?= e(trim(($p['creator_first'] ?? '') . ' ' . ($p['creator_last'] ?? '')) ?: '—') ?></dd>
                    <?php if ($p['published_at']): ?><dt>First published</dt><dd><?= e(format_ist($p['published_at'])) ?></dd><?php endif; ?>
                    <?php if ($p['expires_at']): ?><dt>Expires</dt><dd><?= e(format_ist($p['expires_at'], 'd M Y')) ?></dd><?php endif; ?>
                    <dt>Views · enquiries · shortlists</dt>
                    <dd><?= (int) $p['views_count'] ?> · <?= (int) $p['enquiries_count'] ?> · <?= (int) $p['shortlists_count'] ?></dd>
                </dl>
                <?php if (!$isDeleted): ?>
                    <details>
                        <summary class="small">Change owner</summary>
                        <form method="post" action="<?= e(url('/admin/properties/' . $pid . '/owner')) ?>" class="mt-2">
                            <?= csrf_field() ?>
                            <div class="form-check"><input class="form-check-input" type="radio" name="assign" id="as_sys" value="system"<?= $p['owner_id'] ? '' : ' checked' ?>><label class="form-check-label" for="as_sys">Admin / system</label></div>
                            <div class="form-check mb-2"><input class="form-check-input" type="radio" name="assign" id="as_user" value="user"<?= $p['owner_id'] ? ' checked' : '' ?>><label class="form-check-label" for="as_user">Account (mobile or email)</label></div>
                            <input class="form-control form-control-sm mb-2" name="owner" value="<?= e($p['owner_id'] ? (string) ($p['owner_email'] ?: format_mobile($p['owner_mobile'])) : '') ?>" aria-label="Owner mobile or email">
                            <button class="btn btn-sm btn-outline-primary" type="submit">Save owner</button>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isDeleted): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Featured &amp; order</div>
            <div class="card-body small">
                <form method="post" action="<?= e(url('/admin/properties/' . $pid . '/featured')) ?>" class="mb-2">
                    <?= csrf_field() ?>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="featured" name="featured" value="1"<?= $p['is_featured'] ? ' checked' : '' ?>>
                        <label class="form-check-label" for="featured">Featured listing</label>
                    </div>
                    <label class="form-label small mb-1" for="featured_until">Featured until (optional)</label>
                    <div class="d-flex gap-2">
                        <input class="form-control form-control-sm" type="date" id="featured_until" name="featured_until" min="<?= e($today) ?>" value="<?= e($featuredUntil) ?>">
                        <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                    </div>
                </form>
                <div>Display priority: <strong><?= (int) $p['display_rank'] >= 1000000 ? 'none' : (int) $p['display_rank'] ?></strong>
                    · <a href="<?= e(url('/admin/order?purpose=' . $p['purpose'])) ?>">Change display order</a></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Admin note (private)</div>
            <div class="card-body">
                <form method="post" action="<?= e(url('/admin/properties/' . $pid . '/note')) ?>">
                    <?= csrf_field() ?>
                    <textarea class="form-control form-control-sm mb-2" name="admin_note" rows="2" maxlength="500" aria-label="Admin note"><?= e((string) $p['admin_note']) ?></textarea>
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Save note</button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Status history</div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($history as $h): ?>
                    <li class="list-group-item">
                        <?= $h['old_status'] ? status_badge($h['old_status']) . ' → ' : '' ?><?= status_badge($h['new_status']) ?>
                        <div class="text-muted"><?= e(format_ist($h['created_at'])) ?> · <?= e($h['actor_role']) ?><?= $h['first_name'] ? ' ' . e($h['first_name'] . ' ' . $h['last_name']) : '' ?></div>
                        <?php if ($h['reason']): ?><div><?= e($h['reason']) ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between"><span class="fw-semibold">Rent changes</span><a class="small" href="<?= e(url('/admin/rent-changes?property=' . $pid)) ?>">All</a></div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($rentLog as $r): ?>
                    <li class="list-group-item"><a href="<?= e(url('/admin/rent-changes/' . (int) $r['id'])) ?>"><?= e(money_inr((int) $r['old_rent'])) ?> → <?= e(money_inr((int) $r['new_rent'])) ?></a> <?= status_badge($r['status']) ?>
                        <div class="text-muted"><?= e(format_ist($r['created_at'])) ?></div></li>
                <?php endforeach; ?>
                <?php if (!$rentLog): ?><li class="list-group-item text-muted">No rent changes.</li><?php endif; ?>
            </ul>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Admin actions on this listing</div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($adminLog as $a): ?>
                    <li class="list-group-item"><strong><?= e($a['action']) ?></strong> · <?= e(AdminLog::describe($a)) ?>
                        <div class="text-muted"><?= e(format_ist($a['created_at'])) ?> · <?= e(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''))) ?></div></li>
                <?php endforeach; ?>
                <?php if (!$adminLog): ?><li class="list-group-item text-muted">None yet.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
