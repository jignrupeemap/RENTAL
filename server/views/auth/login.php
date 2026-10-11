<?php
/** @var string $tab @var string $next @var bool $otpEnabled */
$this->layout('layouts/main', ['noindex' => true]);
$q = $next !== '' ? '?next=' . rawurlencode($next) : '';
$otpMode = $tab === 'login' && $otpEnabled && isset($_GET['otp']);
?>
<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-7 col-lg-5">
            <div class="card auth-card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <ul class="nav nav-pills nav-fill auth-tabs mb-4" role="tablist">
                        <li class="nav-item"><a class="nav-link<?= $tab === 'login' ? ' active' : '' ?>" href="<?= e(url('/login' . $q)) ?>">Login</a></li>
                        <li class="nav-item"><a class="nav-link<?= $tab === 'register' ? ' active' : '' ?>" href="<?= e(url('/register' . $q)) ?>">Register</a></li>
                    </ul>

                    <?php if ($tab === 'login' && !$otpMode): ?>
                        <form method="post" action="<?= e(url('/login')) ?>" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="next" value="<?= e($next) ?>">
                            <?= field('identifier', 'Mobile number or email', 'text', 'autocomplete="username" inputmode="email" required autofocus') ?>
                            <?= field('password', 'Password', 'password', 'autocomplete="current-password" required') ?>
                            <div class="d-flex justify-content-end mb-3 small">
                                <a href="<?= e(url('/forgot-password')) ?>">Forgot password?</a>
                            </div>
                            <button class="btn btn-primary w-100 btn-lg" type="submit">Login</button>
                        </form>
                        <?php if ($otpEnabled): ?>
                            <div class="text-center mt-3 small"><a href="<?= e(url('/login' . ($q ? $q . '&' : '?') . 'otp=1')) ?>">Login with a one-time code instead</a></div>
                        <?php endif; ?>

                    <?php elseif ($otpMode): ?>
                        <form method="post" action="<?= e(url('/login/otp/send')) ?>" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="next" value="<?= e($next) ?>">
                            <?= field('mobile', 'Mobile number', 'tel', 'autocomplete="tel" inputmode="numeric" maxlength="14" placeholder="98765 43210" required autofocus', 'We will send a 6-digit code by SMS.') ?>
                            <button class="btn btn-primary w-100 btn-lg" type="submit">Send code</button>
                        </form>
                        <div class="text-center mt-3 small"><a href="<?= e(url('/login' . $q)) ?>">Login with password instead</a></div>

                    <?php else: ?>
                        <form method="post" action="<?= e(url('/register')) ?>" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="next" value="<?= e($next) ?>">
                            <div class="hp-field" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                            <div class="row g-2">
                                <div class="col-sm-6"><?= field('first_name', 'First name', 'text', 'autocomplete="given-name" maxlength="80" required autofocus') ?></div>
                                <div class="col-sm-6"><?= field('last_name', 'Last name', 'text', 'autocomplete="family-name" maxlength="80" required') ?></div>
                            </div>
                            <?= field('mobile', 'Mobile number', 'tel', 'autocomplete="tel" inputmode="numeric" maxlength="14" placeholder="98765 43210" required', $otpEnabled ? 'We will verify it with a one-time code.' : '') ?>
                            <?= field('email', 'Email', 'email', 'autocomplete="email" maxlength="191" required') ?>
                            <?= field('password', 'Password', 'password', 'autocomplete="new-password" minlength="8" required', 'At least 8 characters, with a letter and a number.') ?>
                            <?= field('password_confirmation', 'Confirm password', 'password', 'autocomplete="new-password" required') ?>
                            <div class="form-check mb-3">
                                <input class="form-check-input<?= invalid('terms') ?>" type="checkbox" value="1" id="f_terms" name="terms" <?= old('terms') ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="f_terms">
                                    I agree to the <a href="<?= e(url('/page/terms')) ?>" target="_blank" rel="noopener">Terms of Use</a> and <a href="<?= e(url('/page/privacy')) ?>" target="_blank" rel="noopener">Privacy Policy</a>
                                </label>
                                <?php if ($err = field_error('terms')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
                            </div>
                            <button class="btn btn-primary w-100 btn-lg" type="submit">Create account</button>
                            <p class="small text-muted text-center mt-3 mb-0">One account lets you search, shortlist and contact owners, and post your own property whenever you need to.</p>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
