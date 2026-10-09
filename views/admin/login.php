<?php $this->layout('layouts/admin'); ?>
<div class="row justify-content-center pt-md-5">
    <div class="col-12 col-sm-9 col-md-6 col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <h1 class="h4 mb-1">Admin sign in</h1>
                <p class="text-muted small mb-4">For website administrators only.</p>
                <form method="post" action="<?= e(url('/admin/login')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <?= field('identifier', 'Mobile number or email', 'text', 'autocomplete="username" required autofocus') ?>
                    <?= field('password', 'Password', 'password', 'autocomplete="current-password" required') ?>
                    <button class="btn btn-dark w-100 btn-lg" type="submit">Sign in</button>
                </form>
            </div>
        </div>
        <p class="text-center small text-muted mt-3">You will be signed out after 30 minutes of inactivity.</p>
    </div>
</div>
