<?php
/** Admin email copy of the RENTAL CHANGE ALERT (spec 30). @var array $c @var bool $waiting @var string $base */
$this->layout('emails/layout');
$d = (int) $c['difference'];
$btn = 'display:inline-block;background:#0f5c63;color:#ffffff;padding:10px 16px;border-radius:6px;text-decoration:none;margin:0 6px 6px 0';
?>
<p><strong>RENTAL CHANGE ALERT</strong><?= $waiting ? ' · waiting for your approval' : '' ?></p>
<table cellpadding="4" style="border-collapse:collapse;font-size:14px">
    <tr><td style="color:#5b6475">Owner</td><td><?= e((string) ($c['owner_name'] ?: 'Admin / system')) ?></td></tr>
    <tr><td style="color:#5b6475">Mobile</td><td><?= e($c['owner_mobile'] ? format_mobile($c['owner_mobile']) : '—') ?></td></tr>
    <tr><td style="color:#5b6475">Property</td><td>#<?= (int) $c['property_id'] ?> <?= e($c['property_title']) ?></td></tr>
    <tr><td style="color:#5b6475">Old rent</td><td><?= e(money_inr((int) $c['old_rent'])) ?></td></tr>
    <tr><td style="color:#5b6475">New rent</td><td><strong><?= e(money_inr((int) $c['new_rent'])) ?></strong></td></tr>
    <tr><td style="color:#5b6475"><?= $d > 0 ? 'Increase' : 'Decrease' ?></td><td><?= e(money_inr(abs($d))) ?></td></tr>
    <tr><td style="color:#5b6475">Changed</td><td><?= e(format_ist($c['created_at'])) ?> (India time)</td></tr>
</table>
<p style="margin-top:16px">
    <a style="<?= $btn ?>" href="<?= e($base . '/admin/rent-changes/' . (int) $c['id']) ?>"><?= $waiting ? 'REVIEW' : 'VIEW ALERT' ?></a>
    <a style="<?= $btn ?>" href="<?= e($base . '/admin/properties/' . (int) $c['property_id']) ?>">VIEW PROPERTY</a>
    <?php if ($c['owner_id']): ?><a style="<?= $btn ?>" href="<?= e($base . '/admin/users/' . (int) $c['owner_id']) ?>">VIEW OWNER</a><?php endif; ?>
    <a style="<?= $btn ?>" href="<?= e($base . '/admin/rent-changes?property=' . (int) $c['property_id']) ?>">VIEW CHANGE HISTORY</a>
</p>
