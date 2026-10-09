<?php
/** Viewer profile + password (spec 23). @var array $user */
$this->layout('layouts/account', ['section' => 'profile']);
?>
<h1 class="h4 mb-3">Profile</h1>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <form method="post" action="<?= e(url('/account/profile')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="col-md-6"><?= field('first_name', 'First name', 'text', 'maxlength="80" required autocomplete="given-name"', '', old('first_name', $user['first_name'])) ?></div>
                <div class="col-md-6"><?= field('last_name', 'Last name', 'text', 'maxlength="80" required autocomplete="family-name"', '', old('last_name', $user['last_name'])) ?></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="f_mobile_ro">Mobile number</label>
                <input class="form-control" id="f_mobile_ro" value="<?= e(format_mobile($user['mobile'])) ?>" disabled>
                <div class="form-text">
                    <?= $user['mobile_verified_at'] ? 'Verified. ' : '' ?>Your mobile number is your login. Contact support to change it.
                </div>
            </div>
            <?= field('email', 'Email', 'email', 'maxlength="191" required autocomplete="email"', $user['email_verified_at'] ? 'Verified. Changing it sends a new verification link.' : 'Changing it sends a new verification link.', old('email', (string) $user['email'])) ?>
            <?php if ($user['email'] && !$user['email_verified_at']): ?>
                <p class="small text-warning-emphasis">Your email is not verified yet.</p>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit">Save profile</button>
        </form>
        <?php if ($user['email'] && !$user['email_verified_at']): ?>
            <form method="post" action="<?= e(url('/verify-email/resend')) ?>" class="mt-2">
                <?= csrf_field() ?><button class="btn btn-link btn-sm p-0" type="submit">Resend verification link</button>
            </form>
        <?php endif; ?>
        <p class="small text-muted mt-3 mb-0">Member since <?= e(format_ist($user['created_at'], 'd M Y')) ?></p>
    </div>
</div>

<div class="card border-0 shadow-sm" id="password">
    <div class="card-body p-4">
        <h2 class="h5 mb-3">Change password</h2>
        <form method="post" action="<?= e(url('/account/password')) ?>" novalidate>
            <?= csrf_field() ?>
            <?= field('current_password', 'Current password', 'password', 'autocomplete="current-password" required') ?>
            <div class="row g-2">
                <div class="col-md-6"><?= field('password', 'New password', 'password', 'autocomplete="new-password" minlength="8" required') ?></div>
                <div class="col-md-6"><?= field('password_confirmation', 'Confirm new password', 'password', 'autocomplete="new-password" required') ?></div>
            </div>
            <button class="btn btn-primary" type="submit">Update password</button>
        </form>
    </div>
</div>
