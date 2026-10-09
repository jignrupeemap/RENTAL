<?php
use App\Core\Session;
$messages = array_filter([
    'success' => Session::flash('success'),
    'danger'  => Session::flash('error'),
    'info'    => Session::flash('info'),
]);
if (!$messages) {
    return;
}
?>
<div class="container mt-3">
    <?php foreach ($messages as $type => $message): ?>
        <div class="alert alert-<?= e($type) ?> alert-dismissible fade show mb-2" role="alert">
            <?= e($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
</div>
