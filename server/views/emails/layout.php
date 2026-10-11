<?php /** @var string $content @var string $subject */ $site = (string) App\Core\Settings::get('site_name', 'Rental Website'); ?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($subject) ?></title></head>
<body style="margin:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1d2433">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:24px 12px">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;padding:32px">
            <tr><td style="font-size:20px;font-weight:bold;color:#0f5c63;padding-bottom:16px"><?= e($site) ?></td></tr>
            <tr><td style="font-size:15px;line-height:1.6"><?= $content ?></td></tr>
            <tr><td style="font-size:12px;color:#7a8394;padding-top:24px">You received this email because of activity on your <?= e($site) ?> account.</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
