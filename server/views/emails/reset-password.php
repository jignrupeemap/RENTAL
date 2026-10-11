<?php /** @var string $name @var string $link */ $this->layout('emails/layout'); ?>
<p>Hi <?= e($name) ?>,</p>
<p>We received a request to reset your password. Open this link to choose a new one:</p>
<p><a href="<?= e($link) ?>" style="display:inline-block;background:#0f5c63;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none">Reset password</a></p>
<p style="word-break:break-all;font-size:13px;color:#5b6475"><?= e($link) ?></p>
<p>This link is valid for 60 minutes and can be used once. If you did not ask for this, you can ignore this email; your password will not change.</p>
