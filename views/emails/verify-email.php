<?php /** @var string $name @var string $link */ $this->layout('emails/layout'); ?>
<p>Hi <?= e($name) ?>,</p>
<p>Please confirm your email address by opening this link:</p>
<p><a href="<?= e($link) ?>" style="display:inline-block;background:#0f5c63;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none">Verify email</a></p>
<p style="word-break:break-all;font-size:13px;color:#5b6475"><?= e($link) ?></p>
<p>This link is valid for 2 days.</p>
