<?php
/** Add / edit a homepage slide (spec 19). @var ?array $s @var int $maxMb */
use App\Services\SliderService;
$this->layout('layouts/admin');
$v = static fn (string $k): string => old($k, (string) ($s[$k] ?? ''));
?>
<nav class="small mb-2"><a href="<?= e(url('/admin/sliders')) ?>">&larr; Homepage sliders</a></nav>
<h1 class="h4 mb-3"><?= $s ? 'Edit slide' : 'Add slide' ?></h1>
<div class="card border-0 shadow-sm" style="max-width:48rem">
    <div class="card-body p-4">
        <form method="post" action="<?= e(url($s ? '/admin/sliders/' . (int) $s['id'] : '/admin/sliders')) ?>" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="f_image">Image<?= $s ? ' (choose one only to replace it)' : '' ?></label>
                <?php if ($s): ?><div class="mb-2"><?= media_picture(SliderService::media($s), '480px', (string) ($s['title'] ?? 'Current slide'), true, 'img-fluid rounded slider-preview', 'md') ?></div><?php endif; ?>
                <input class="form-control<?= invalid('image') ?>" type="file" id="f_image" name="image" accept="image/jpeg,image/png,image/webp"<?= $s ? '' : ' required' ?>>
                <?php if ($err = field_error('image')): ?><div class="invalid-feedback"><?= e($err) ?></div><?php endif; ?>
                <div class="form-text">JPG, PNG or WebP, landscape, at least <?= SliderService::MIN_WIDTH ?> pixels wide, up to <?= $maxMb ?> MB. It is resized and compressed automatically. About 1920×800 works best.</div>
            </div>
            <?= field('title', 'Title (optional)', 'text', 'maxlength="120"', '', $v('title')) ?>
            <?= field('subtitle', 'Subtitle (optional)', 'text', 'maxlength="255"', '', $v('subtitle')) ?>
            <div class="row g-2">
                <div class="col-md-5"><?= field('button_text', 'Button text (optional)', 'text', 'maxlength="40" placeholder="Browse PGs"', '', $v('button_text')) ?></div>
                <div class="col-md-7"><?= field('button_link', 'Button link', 'text', 'maxlength="255" placeholder="/pg or https://…"', 'A page on this site like /rent, or a full https:// address.', $v('button_link')) ?></div>
            </div>
            <div class="row g-2">
                <div class="col-md-6"><?= field('starts_at', 'Show from (optional)', 'date', '', 'India date. Empty = from now.', old('starts_at', SliderService::formDate($s['starts_at'] ?? null, false))) ?></div>
                <div class="col-md-6"><?= field('ends_at', 'Show until (optional)', 'date', '', 'Last day it shows. Empty = no end.', old('ends_at', SliderService::formDate($s['ends_at'] ?? null, true))) ?></div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit"><?= $s ? 'Save slide' : 'Add slide' ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/admin/sliders')) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
