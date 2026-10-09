<?php
/** 410 page for a listing that was live once. @var array $old  purpose, city_id, city_name */
$this->layout('layouts/main');
$more = '/' . $old['purpose'] . ($old['city_id'] ? '?city=' . (int) $old['city_id'] : '');
?>
<div class="container py-5">
    <div class="card border-0 shadow-sm mx-auto" style="max-width:36rem">
        <div class="card-body text-center py-5">
            <h1 class="h4">This listing is no longer available</h1>
            <p class="text-muted">The owner has paused or removed it, or it has expired.</p>
            <a class="btn btn-primary" href="<?= e(url($more)) ?>">See other <?= e(purpose_label($old['purpose'])) ?> properties<?= $old['city_name'] ? ' in ' . e($old['city_name']) : '' ?></a>
        </div>
    </div>
</div>
