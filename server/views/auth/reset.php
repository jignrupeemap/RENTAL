<?php
/** @var string $token @var bool $valid @var bool $otp */
$this->layout('layouts/main', ['noindex' => true]);
?>
<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-6 col-lg-5">
            <div class="card auth-card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h4 mb-3">Choose a new password</h1>
                    <?php if (!$valid): ?>
                        <div class="alert alert-warning">This reset link is invalid or has expired.</div>
                        <a class="btn btn-primary w-100" href="<?= e(url('/forgot-password')) ?>">Request a new link</a>
                    <?php else: ?>
                        <?php if ($otp): ?>
                            <p class="text-muted small">Enter the 6-digit code sent to <strong><?= e(format_mobile($mobile)) ?></strong>.</p>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url($otp ? '/reset-password/otp' : '/reset-password')) ?>" novalidate>
                            <?= csrf_field() ?>
                            <?php if ($otp): ?>
                                <?= field('code', 'Code', 'text', 'inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus') ?>
                            <?php else: ?>
                                <input type="hidden" name="token" value="<?= e($token) ?>">
                            <?php endif; ?>
                            <?= field('password', 'New password', 'password', 'autocomplete="new-password" minlength="8" required', 'At least 8 characters, with a letter and a number.') ?>
                            <?= field('password_confirmation', 'Confirm new password', 'password', 'autocomplete="new-password" required') ?>
                            <button class="btn btn-primary w-100 btn-lg" type="submit">Save new password</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
