<?php
/** @var array $user */
$this->layout('layouts/owner', ['section' => 'profile']);
?>
<h1 class="h4 mb-3">Profile</h1>
<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="post" action="<?= e(url('/owner/profile')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="col-md-6"><?= field('first_name', 'First name', 'text', 'maxlength="80" required autocomplete="given-name"', '', old('first_name', $user['first_name'])) ?></div>
                <div class="col-md-6"><?= field('last_name', 'Last name', 'text', 'maxlength="80" required autocomplete="family-name"', '', old('last_name', $user['last_name'])) ?></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="f_mobile_ro">Mobile number</label>
                <input class="form-control" id="f_mobile_ro" value="<?= e(format_mobile($user['mobile'])) ?>" disabled>
                <div class="form-text">Your mobile number is your login. Contact support to change it.</div>
            </div>
            <?= field('email', 'Email', 'email', 'maxlength="191" required autocomplete="email"', 'Changing it sends a new verification link.', old('email', (string) $user['email'])) ?>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Save profile</button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/account/profile#password')) ?>">Change password</a>
            </div>
        </form>
    </div>
</div>
