<?php
/** @var bool $unread @var array $rows @var int $total @var int $page @var int $perPage */
$this->layout('layouts/admin');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Notifications</h1>
    <form method="post" action="<?= e(url('/admin/notifications/read')) ?>" class="m-0"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" type="submit">Mark all as read</button></form>
</div>
<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link<?= !$unread ? ' active' : '' ?>" href="<?= e(url('/admin/notifications')) ?>">All</a></li>
    <li class="nav-item"><a class="nav-link<?= $unread ? ' active' : '' ?>" href="<?= e(url('/admin/notifications?show=unread')) ?>">Unread</a></li>
</ul>
<div class="card border-0 shadow-sm">
    <ul class="list-group list-group-flush">
        <?php foreach ($rows as $n): ?>
            <li class="list-group-item d-flex justify-content-between gap-2<?= $n['read_at'] === null ? ' fw-semibold' : '' ?>">
                <div>
                    <a class="text-reset" href="<?= e(url('/admin/notifications/' . (int) $n['id'])) ?>"><?= e($n['title']) ?></a>
                    <?php if ($n['body']): ?><div class="small text-muted fw-normal"><?= e($n['body']) ?></div><?php endif; ?>
                </div>
                <span class="small text-muted fw-normal text-nowrap"><?= e(format_ist($n['created_at'])) ?></span>
            </li>
        <?php endforeach; ?>
        <?php if (!$rows): ?><li class="list-group-item text-muted">Nothing here.</li><?php endif; ?>
    </ul>
</div>
<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
