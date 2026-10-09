<?php
/** @var int $total @var int $page @var int $perPage */
$pages = (int) ceil($total / max(1, $perPage));
if ($pages <= 1) {
    return;
}
$from = max(1, $page - 2);
$to = min($pages, $page + 2);
?>
<nav aria-label="Pages" class="mt-3">
    <ul class="pagination pagination-sm flex-wrap mb-0">
        <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(url_with(['page' => $page - 1])) ?>">Previous</a></li>
        <?php for ($i = $from; $i <= $to; $i++): ?>
            <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e(url_with(['page' => $i])) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
        <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= e(url_with(['page' => $page + 1])) ?>">Next</a></li>
    </ul>
    <div class="small text-muted mt-1">Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> total</div>
</nav>
