<?php /** @var array $page */ $this->layout('layouts/main'); ?>
<div class="container py-4 py-md-5" style="max-width: 820px">
    <h1 class="h2 mb-4"><?= e($page['title']) ?></h1>
    <?php // Admin-authored HTML, passed through an allow-list on every render. ?>
    <div class="cms-body"><?= safe_html((string) $page['body']) ?></div>
</div>
