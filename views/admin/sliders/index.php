<?php
/** Homepage Sliders (spec 36). @var array $rows @var list<int> $liveIds */
use App\Services\SliderService;
$this->layout('layouts/admin');
$last = count($rows) - 1;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Homepage sliders</h1>
    <a class="btn btn-primary" href="<?= e(url('/admin/sliders/new')) ?>">+ Add Slider</a>
</div>
<p class="small text-muted">Active slides show at the top of the homepage in this order. With no active slide, the homepage shows a plain heading above the search.</p>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 admin-table small align-middle">
            <thead class="table-light"><tr><th>Image</th><th>Title</th><th>Subtitle</th><th>Button</th><th>Order</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $s): $live = in_array((int) $s['id'], $liveIds, true); ?>
                <tr>
                    <td style="width:9rem"><img class="rounded slider-thumb" src="<?= e(media_url(SliderService::media($s), 'thumb')) ?>" alt="" width="128" height="<?= (int) round(128 * max(1, (int) $s['image_height']) / max(1, (int) $s['image_width'])) ?>" loading="lazy"></td>
                    <td class="fw-semibold"><?= e((string) ($s['title'] ?? '—')) ?></td>
                    <td style="max-width:16rem"><?= e(mb_strimwidth((string) $s['subtitle'], 0, 90, '…')) ?></td>
                    <td><?php if ($s['button_text']): ?><?= e($s['button_text']) ?><div class="text-muted text-break"><?= e((string) $s['button_link']) ?></div><?php else: ?>—<?php endif; ?></td>
                    <td class="text-nowrap">
                        <?= $i + 1 ?>
                        <?php foreach (['up' => '↑', 'down' => '↓'] as $dir => $arrow): $disabled = ($dir === 'up' && $i === 0) || ($dir === 'down' && $i === $last); ?>
                            <form class="d-inline" method="post" action="<?= e(url('/admin/sliders/' . (int) $s['id'] . '/move')) ?>">
                                <?= csrf_field() ?><input type="hidden" name="direction" value="<?= $dir ?>">
                                <button class="btn btn-sm btn-outline-secondary py-0" type="submit"<?= $disabled ? ' disabled' : '' ?> aria-label="Move <?= $dir ?>"><?= $arrow ?></button>
                            </form>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php if (!$s['is_active']): ?><span class="badge text-bg-secondary">Inactive</span>
                        <?php elseif ($live): ?><span class="badge text-bg-success">Live</span>
                        <?php else: ?><span class="badge text-bg-warning">Scheduled / ended</span><?php endif; ?>
                        <?php if ($s['starts_at'] || $s['ends_at']): ?><div class="text-muted"><?= e(SliderService::formDate($s['starts_at'], false) ?: '…') ?> – <?= e(SliderService::formDate($s['ends_at'], true) ?: '…') ?></div><?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/sliders/' . (int) $s['id'] . '/edit')) ?>">Edit</a>
                        <form class="d-inline" method="post" action="<?= e(url('/admin/sliders/' . (int) $s['id'] . '/status')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="active" value="<?= $s['is_active'] ? '0' : '1' ?>">
                            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $s['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                        <form class="d-inline" method="post" action="<?= e(url('/admin/sliders/' . (int) $s['id'] . '/delete')) ?>" data-confirm="Delete this slide and its image? This cannot be undone.">
                            <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No slides yet. <a href="<?= e(url('/admin/sliders/new')) ?>">Add the first one</a>.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
