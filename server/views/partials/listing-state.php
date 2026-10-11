<?php
/**
 * "View property" link while the listing is live, otherwise its state, so a
 * paused or removed listing never breaks the viewer's history (spec 24).
 * @var bool|int|string $live @var ?string $pstatus @var array $plink  [id, purpose, slug] or empty
 */
use App\Repositories\ViewerRepository;
?>
<?php if ((int) $live && $plink): ?>
    <a class="btn btn-sm btn-outline-primary" href="<?= e(url(property_url($plink))) ?>">View Property</a>
<?php else: ?>
    <span class="badge rounded-pill text-bg-light border text-secondary"><?= e(ViewerRepository::unavailableLabel($pstatus)) ?></span>
<?php endif; ?>
