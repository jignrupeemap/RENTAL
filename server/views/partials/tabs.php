<?php
/** Status filter tabs. @var array $tabs [key|null => [label, count]] @var ?string $current */
?>
<ul class="nav nav-pills status-tabs flex-nowrap overflow-auto mb-3">
    <?php foreach ($tabs as $key => [$label, $count]): ?>
        <li class="nav-item">
            <a class="nav-link text-nowrap<?= ($current ?? '') === ($key === '' ? '' : $key) ? ' active' : '' ?>" href="<?= e(url_with(['status' => $key === '' ? null : $key, 'page' => null])) ?>">
                <?= e($label) ?> <span class="badge rounded-pill tab-count"><?= (int) $count ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
