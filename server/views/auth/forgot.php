<?php
/** @var bool $otpEnabled */
$this->layout('layouts/main', ['noindex' => true]);
?>
<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-6 col-lg-5">
            <div class="card auth-card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h4 mb-2">Forgot your password?</h1>
                    <p class="text-muted small mb-4">
                        <?= $otpEnabled
                            ? 'Enter your email to get a reset link, or your mobile number to get a code by SMS.'
                            : 'Enter your email or mobile number and we will email you a reset link.' ?>
                    </p>
                    <form method="post" action="<?= e(url('/forgot-password')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <?= field('identifier', 'Email or mobile number', 'text', 'autocomplete="username" required autofocus') ?>
                        <button class="btn btn-primary w-100 btn-lg" type="submit">Continue</button>
                    </form>
                    <div class="text-center mt-3 small"><a href="<?= e(url('/login')) ?>">Back to login</a></div>
                </div>
            </div>
        </div>
    </div>
</div>
