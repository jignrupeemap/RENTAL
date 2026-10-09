<?php /** @var string $name @var string $title @var bool $approved @var int $newRent @var int $liveRent @var string $note @var string $link */ $this->layout('emails/layout'); ?>
<p>Hi <?= e($name) ?>,</p>
<?php if ($approved): ?>
    <p>Your rent change for <strong><?= e($title) ?></strong> was approved. <strong><?= e(money_inr($newRent)) ?></strong> per month is now live.</p>
<?php else: ?>
    <p>Your rent change to <?= e(money_inr($newRent)) ?> for <strong><?= e($title) ?></strong> was not approved. <strong><?= e(money_inr($liveRent)) ?></strong> per month stays live.</p>
<?php endif; ?>
<?php if ($note !== ''): ?><p style="background:#f3f6f5;padding:10px;border-radius:6px">Note from the admin: <?= e($note) ?></p><?php endif; ?>
<p><a href="<?= e($link) ?>" style="display:inline-block;background:#0f5c63;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none">Rental change history</a></p>
