<?php /** @var string $name @var string $from @var string $title @var ?string $message @var string $mobile @var string $dashboard */ $this->layout('emails/layout'); ?>
<p>Hi <?= e($name) ?>,</p>
<p><strong><?= e($from) ?></strong> sent an enquiry about <strong><?= e($title) ?></strong>.</p>
<?php if ($message): ?><p style="background:#f3f6f5;padding:10px;border-radius:6px"><?= nl2br(e($message)) ?></p><?php endif; ?>
<p>Mobile: <?= e($mobile) ?></p>
<p><a href="<?= e($dashboard . '/enquiries') ?>" style="display:inline-block;background:#0f5c63;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none">Open your enquiries</a></p>
