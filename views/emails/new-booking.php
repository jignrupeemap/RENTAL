<?php /** @var string $name @var string $from @var string $title @var string $when @var string $message @var string $dashboard */ $this->layout('emails/layout'); ?>
<p>Hi <?= e($name) ?>,</p>
<p><strong><?= e($from) ?></strong> would like to visit <strong><?= e($title) ?></strong> on <strong><?= e($when) ?></strong>.</p>
<?php if ($message !== ''): ?><p style="background:#f3f6f5;padding:10px;border-radius:6px"><?= nl2br(e($message)) ?></p><?php endif; ?>
<p><a href="<?= e($dashboard . '/bookings') ?>" style="display:inline-block;background:#0f5c63;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none">Accept, decline or suggest a time</a></p>
