<?php
/** @var string $heading @var string $mobile @var string $action @var ?string $resendAction */
$this->layout('layouts/main', ['noindex' => true]);
?>
<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-6 col-lg-4">
            <div class="card auth-card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h4 mb-2"><?= e($heading) ?></h1>
                    <p class="text-muted small mb-4">Enter the 6-digit code sent to <strong><?= e(format_mobile($mobile)) ?></strong>. It is valid for 10 minutes.</p>
                    <form method="post" action="<?= e(url($action)) ?>" novalidate>
                        <?= csrf_field() ?>
                        <?= field('code', 'Code', 'text', 'inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required autofocus') ?>
                        <button class="btn btn-primary w-100 btn-lg" type="submit">Verify</button>
                    </form>
                    <?php if ($resendAction): ?>
                        <form method="post" action="<?= e(url($resendAction)) ?>" class="text-center mt-3">
                            <?= csrf_field() ?>
                            <button class="btn btn-link btn-sm" type="submit">Send a new code</button>
                        </form>
                    <?php else: ?>
                        <div class="text-center mt-3 small"><a href="<?= e(url('/login?otp=1')) ?>">Use a different number or resend</a></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
