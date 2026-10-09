<?php
/**
 * Step 6: photos (upload, cover, order, delete) and video (file or YouTube).
 * Each photo/video action is its own small form, so everything works
 * without JavaScript; with it, uploads show progress and phones shrink
 * photos before sending (assets/js/property-wizard.js).
 * @var array $photos @var ?array $video @var array $mediaLimits
 */
$pid = (int) $p['id'];
$mediaUrl = static fn (string $path): string => url($base . '/' . $pid . $path);
$count = count($photos);
$full = $count >= $mediaLimits['max_images'];
?>
<h2 class="h6 mb-1">Photos <span class="text-danger">*</span></h2>
<p class="small text-muted mb-2">
    JPG, PNG or WebP, up to <?= e(\App\Services\Media\MediaService::mb($mediaLimits['image_bytes'])) ?> each. Up to <?= (int) $mediaLimits['max_images'] ?> photos.
    The first photo is the cover shown in search results.
</p>
<?= $err('photos') ?>

<?php if (!$full): ?>
    <form method="post" action="<?= e($mediaUrl('/photos')) ?>" enctype="multipart/form-data" class="upload-box rounded-3 p-3 mb-3"
          data-upload="photos" data-max-bytes="<?= (int) $mediaLimits['image_bytes'] ?>" data-resize="<?= (int) $mediaLimits['client_resize'] ?>"
          data-remaining="<?= (int) ($mediaLimits['max_images'] - $count) ?>">
        <?= csrf_field() ?>
        <label class="form-label fw-semibold" for="f_photos">Add photos</label>
        <div class="d-flex flex-wrap gap-2">
            <input class="form-control file-input" type="file" id="f_photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
            <button class="btn btn-primary" type="submit">Upload photos</button>
        </div>
        <ul class="upload-progress list-unstyled small mt-2 mb-0" aria-live="polite"></ul>
    </form>
<?php else: ?>
    <div class="alert alert-light border small">This listing has the maximum of <?= (int) $mediaLimits['max_images'] ?> photos. Delete one to add another.</div>
<?php endif; ?>

<?php if ($photos): ?>
    <p class="small text-muted mb-2"><?= e(plural($count, 'photo', 'photos')) ?> · use the arrows to change the order</p>
    <div class="photo-grid mb-4">
        <?php foreach ($photos as $i => $m): $mid = (int) $m['id']; ?>
            <figure class="photo-card mb-0">
                <div class="photo-thumb">
                    <?= media_picture($m, '(max-width: 576px) 50vw, 200px', 'Photo ' . ($i + 1), $i > 3, '', 'thumb') ?>
                    <?php if ((int) $m['is_cover']): ?><span class="badge text-bg-primary cover-badge">Cover</span><?php endif; ?>
                </div>
                <figcaption class="d-flex flex-wrap gap-1 justify-content-between align-items-center pt-2">
                    <span class="d-flex gap-1">
                        <form method="post" action="<?= e($mediaUrl('/media/' . $mid . '/move')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="direction" value="up">
                            <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Move photo <?= $i + 1 ?> earlier"<?= $i === 0 ? ' disabled' : '' ?>>&larr;</button>
                        </form>
                        <form method="post" action="<?= e($mediaUrl('/media/' . $mid . '/move')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="direction" value="down">
                            <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Move photo <?= $i + 1 ?> later"<?= $i === $count - 1 ? ' disabled' : '' ?>>&rarr;</button>
                        </form>
                    </span>
                    <span class="d-flex gap-1">
                        <?php if (!(int) $m['is_cover']): ?>
                            <form method="post" action="<?= e($mediaUrl('/media/' . $mid . '/cover')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-primary" type="submit">Make cover</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= e($mediaUrl('/media/' . $mid . '/delete')) ?>" data-confirm="Delete this photo?">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete photo <?= $i + 1 ?>">Delete</button>
                        </form>
                    </span>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="h6 mb-1 mt-4">Video <span class="text-muted small fw-normal">(optional)</span></h2>
<?php if ($video): ?>
    <div class="mb-2">
        <video class="w-100 rounded-3 bg-dark" controls preload="none" style="max-height:320px"
            <?= $photos ? 'poster="' . e(media_url($photos[0], 'md')) . '"' : '' ?>>
            <source src="<?= e(video_url($video)) ?>" type="<?= e($video['mime_type']) ?>">
        </video>
        <div class="d-flex flex-wrap gap-2 align-items-center small mt-1">
            <span class="text-muted"><?= e($video['original_name']) ?> · <?= e(\App\Services\Media\MediaService::mb((int) $video['size_bytes'])) ?></span>
            <form method="post" action="<?= e($mediaUrl('/media/' . (int) $video['id'] . '/delete')) ?>" data-confirm="Delete this video?">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-danger" type="submit">Delete video</button>
            </form>
        </div>
    </div>
<?php endif; ?>
<form method="post" action="<?= e($mediaUrl('/video')) ?>" enctype="multipart/form-data" class="upload-box rounded-3 p-3 mb-3"
      data-upload="video" data-max-bytes="<?= (int) $mediaLimits['video_bytes'] ?>">
    <?= csrf_field() ?>
    <label class="form-label fw-semibold" for="f_video"><?= $video ? 'Replace the video' : 'Upload a video' ?></label>
    <div class="d-flex flex-wrap gap-2">
        <input class="form-control file-input" type="file" id="f_video" name="video" accept="video/mp4,video/webm" required>
        <button class="btn btn-outline-primary" type="submit">Upload video</button>
    </div>
    <div class="form-text">MP4 or WebM, up to <?= e(\App\Services\Media\MediaService::mb($mediaLimits['video_bytes'])) ?>. For longer videos, use a YouTube link below.</div>
    <ul class="upload-progress list-unstyled small mt-2 mb-0" aria-live="polite"></ul>
</form>

<form method="post" action="<?= e($stepUrl(6)) ?>">
    <?= csrf_field() ?>
    <label class="form-label" for="f_video_url"><?= $labelFor('video_url', 'YouTube video link') ?></label>
    <input class="form-control<?= invalid('video_url') ?>" id="f_video_url" name="video_url" type="url" inputmode="url" value="<?= e($val('video_url')) ?>" placeholder="https://youtu.be/...">
    <?= $err('video_url') ?>
    <div class="form-text">Upload your walkthrough video to YouTube (it can be "Unlisted") and paste the link here.</div>
    <?php require __DIR__ . '/actions.php'; ?>
</form>
