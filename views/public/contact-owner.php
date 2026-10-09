<?php
/**
 * 📞 Contact Owner (spec 26). Property details are attached on the server;
 * the viewer only confirms their own details and writes a message.
 * @var array $p @var ?array $cover @var array $user @var bool $canAct @var ?string $lastContact
 */
$this->layout('layouts/main');
$mobile = strlen((string) $user['mobile']) === 12 ? substr((string) $user['mobile'], 2) : (string) $user['mobile'];
?>
<div class="container py-4 form-narrow">
    <h1 class="h4 mb-3">Contact owner</h1>
    <?php require APP_ROOT . '/views/partials/listing-summary.php'; ?>
    <?php if (!$canAct): ?>
        <div class="alert alert-warning">Your account can't contact owners right now. <a href="<?= e(url('/account')) ?>">See why in My Account</a>.</div>
    <?php else: ?>
        <?php if ($lastContact): ?>
            <p class="small text-muted">You last contacted the owner of this property on <?= e(format_ist($lastContact)) ?>. <a href="<?= e(url('/account/contacted')) ?>">See your enquiries</a>.</p>
        <?php endif; ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="post" action="<?= e(url('/property/' . (int) $p['id'] . '/contact')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-2">
                        <div class="col-sm-6"><?= field('first_name', 'First name', 'text', 'maxlength="80" required autocomplete="given-name"', '', old('first_name', (string) $user['first_name'])) ?></div>
                        <div class="col-sm-6"><?= field('last_name', 'Last name', 'text', 'maxlength="80" required autocomplete="family-name"', '', old('last_name', (string) $user['last_name'])) ?></div>
                        <div class="col-sm-6"><?= field('mobile_1', 'Mobile number', 'tel', 'inputmode="numeric" maxlength="14" required autocomplete="tel-national"', '', old('mobile_1', $mobile)) ?></div>
                        <div class="col-sm-6"><?= field('mobile_2', 'Alternate mobile (optional)', 'tel', 'inputmode="numeric" maxlength="14"') ?></div>
                    </div>
                    <?= field('email', 'Email (optional)', 'email', 'maxlength="191" autocomplete="email"', '', old('email', (string) $user['email'])) ?>
                    <div class="mb-3">
                        <label class="form-label" for="f_message">Message</label>
                        <textarea class="form-control<?= invalid('message') ?>" id="f_message" name="message" rows="4" maxlength="1000" placeholder="For example: Is it available from next month? Is parking included?"><?= e(old('message')) ?></textarea>
                        <?php if ($err = field_error('message')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
                    </div>
                    <p class="small text-muted">The owner will see your name, mobile number, email and message. The property's details are attached for you.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" type="submit">Send to owner</button>
                        <a class="btn btn-outline-secondary" href="<?= e(url(property_url($p))) ?>">Back to property</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
