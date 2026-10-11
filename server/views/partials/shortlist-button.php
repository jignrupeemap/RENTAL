<?php
/**
 * ❤️ Shortlist control (spec 21, 24). Logged in: a real POST form (fetch()
 * upgrades it in listings.js). Guest: a link to login that returns here.
 * @var int $sid @var bool $son @var string $sreturn @var string $sclass
 */
use App\Core\Auth;
$sclass ??= 'btn btn-sm';
?>
<?php if (Auth::check()): ?>
    <form method="post" action="<?= e(url('/shortlist/' . $sid)) ?>" class="m-0 js-shortlist">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($sreturn) ?>">
        <input type="hidden" name="state" value="<?= $son ? 'off' : 'on' ?>">
        <button type="submit" class="<?= e($sclass) ?> shortlist-btn<?= $son ? ' is-on' : '' ?>" aria-pressed="<?= $son ? 'true' : 'false' ?>">
            <span class="heart" aria-hidden="true"><?= $son ? '♥' : '♡' ?></span> <span class="label"><?= $son ? 'Shortlisted' : 'Shortlist' ?></span>
        </button>
    </form>
<?php else: ?>
    <a class="<?= e($sclass) ?> shortlist-btn" href="<?= e(url('/login?next=' . rawurlencode($sreturn))) ?>" title="Log in or register to shortlist">
        <span class="heart" aria-hidden="true">♡</span> <span class="label">Shortlist</span>
    </a>
<?php endif; ?>
