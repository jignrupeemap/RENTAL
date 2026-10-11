<?php
/**
 * 📅 Request Visit (spec 28): preferred date, time and a message.
 * @var array $p @var ?array $cover @var array $user @var bool $canAct @var ?array $open
 * @var array $slots @var string $today @var string $lastDay
 */
$this->layout('layouts/main');
?>
<div class="container py-4 form-narrow">
    <h1 class="h4 mb-3">Request a visit</h1>
    <?php require APP_ROOT . '/views/partials/listing-summary.php'; ?>
    <?php if (!$canAct): ?>
        <div class="alert alert-warning">Your account can't request visits right now. <a href="<?= e(url('/account')) ?>">See why in My Account</a>.</div>
    <?php elseif ($open): ?>
        <div class="alert alert-info">
            You already asked to visit on <strong><?= e(date('d M Y', strtotime($open['preferred_date']))) ?>, <?= e(date('h:i A', strtotime($open['preferred_time']))) ?></strong>
            (<?= e(str_replace('_', ' ', $open['status'])) ?>).
            <a href="<?= e(url('/account/bookings?status=open')) ?>">Open your visit requests</a> to cancel it or accept a new time.
        </div>
    <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="post" action="<?= e(url('/property/' . (int) $p['id'] . '/visit')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-2">
                        <div class="col-sm-6"><?= field('preferred_date', 'Preferred date', 'date', 'required min="' . e($today) . '" max="' . e($lastDay) . '"') ?></div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label" for="f_preferred_time">Preferred time</label>
                            <select class="form-select<?= invalid('preferred_time') ?>" id="f_preferred_time" name="preferred_time" required>
                                <option value="">Choose a time</option>
                                <?php foreach ($slots as $v => $label): ?><option value="<?= e($v) ?>"<?= old('preferred_time') === $v ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                            </select>
                            <?php if ($err = field_error('preferred_time')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="f_message">Message (optional)</label>
                        <textarea class="form-control<?= invalid('message') ?>" id="f_message" name="message" rows="3" maxlength="1000" placeholder="For example: I will come with my family."><?= e(old('message')) ?></textarea>
                        <?php if ($err = field_error('message')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
                    </div>
                    <p class="small text-muted">Times are India time. The owner will accept, decline or suggest another time, and you'll see it in My Account. They will see your name and mobile number.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" type="submit">Request visit</button>
                        <a class="btn btn-outline-secondary" href="<?= e(url(property_url($p))) ?>">Back to property</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
