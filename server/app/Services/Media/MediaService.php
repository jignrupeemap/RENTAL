<?php
declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;
use App\Services\ActivityLog;
use App\Services\PropertyService;

/**
 * Photos and video of a listing. Files live in public/uploads/properties/YYYY/MM
 * under random 32-hex names; the database row (property_media) is the
 * source of truth. Every method re-reads the listing with owner_id = the
 * logged-in user and locks it, so ids from the request cannot reach
 * another owner's media and two tabs cannot pass the photo limit.
 */
final class MediaService
{
    private const VIDEO_MIMES = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];

    /** Effective limits: admin settings, capped by what PHP on this server accepts. */
    public static function limits(): array
    {
        $server = min(self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size'));
        return [
            'max_images'      => max(1, Settings::int('max_images_per_property', 20)),
            'image_bytes'     => min(max(1, Settings::int('max_image_upload_mb', 10)) * 1048576, $server),
            'video_bytes'     => min(max(1, Settings::int('max_video_upload_mb', 50)) * 1048576, $server),
            'server_bytes'    => $server,
            'client_resize'   => (int) Config::get('uploads.client_resize_px', 2000),
        ];
    }

    /** @return list<array> active photos, cover first then by order */
    public static function photos(int $propertyId): array
    {
        return Database::all(
            "SELECT * FROM property_media WHERE property_id = ? AND media_type = 'image' AND status = 'active' ORDER BY is_cover DESC, sort_order, id",
            [$propertyId]
        );
    }

    public static function video(int $propertyId): ?array
    {
        return Database::one("SELECT * FROM property_media WHERE property_id = ? AND media_type = 'video' AND status = 'active' ORDER BY id DESC LIMIT 1", [$propertyId]);
    }

    /**
     * Add uploaded photos. $files is a list of PHP upload arrays (name, tmp_name, error, size).
     * @return array{added: int, errors: list<string>}
     */
    public static function addPhotos(int $propertyId, int $uid, array $files, string $ip, bool $asAdmin = false): array
    {
        $limits = self::limits();
        $added = 0;
        $errors = [];
        foreach ($files as $file) {
            $name = self::displayName((string) ($file['name'] ?? 'photo'));
            try {
                self::checkUpload($file, $limits['image_bytes']);
                $mime = self::sniff((string) $file['tmp_name']);
                if (!in_array($mime, Config::get('uploads.image_mimes'), true)) {
                    throw new MediaException('Only JPG, PNG or WebP photos can be uploaded.');
                }
                Database::transaction(function () use ($propertyId, $uid, $file, $mime, $name, $limits, $ip, $asAdmin): void {
                    $p = self::lockProperty($propertyId, $uid, $asAdmin);
                    $count = (int) Database::value("SELECT COUNT(*) FROM property_media WHERE property_id = ? AND media_type = 'image' AND status = 'active'", [$propertyId]);
                    if ($count >= $limits['max_images']) {
                        throw new MediaException("A listing can have up to {$limits['max_images']} photos. Delete one to add another.");
                    }
                    $dir = 'properties/' . gmdate('Y/m');
                    $abs = PUBLIC_ROOT . '/uploads/' . $dir;
                    $key = bin2hex(random_bytes(16));
                    $img = ImageProcessor::process((string) $file['tmp_name'], $mime, $abs, $key);
                    try {
                        $order = (int) Database::value("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM property_media WHERE property_id = ? AND media_type = 'image'", [$propertyId]);
                        $id = Database::insert(
                            "INSERT INTO property_media (property_id, media_type, storage_dir, file_key, ext, has_webp, width, height, size_bytes,
                                                         mime_type, original_name, sort_order, is_cover, uploaded_by)
                             VALUES (?, 'image', ?, ?, 'jpg', ?, ?, ?, ?, 'image/jpeg', ?, ?, 0, ?)",
                            [$propertyId, $dir, $key, $img['has_webp'] ? 1 : 0, $img['width'], $img['height'], $img['size_bytes'], $name, $order, $uid]
                        );
                        if ($p['cover_media_id'] === null) {
                            self::makeCover($propertyId, $id);
                        }
                        if (Settings::bool('keep_original_images')) {
                            self::keepOriginal((string) $file['tmp_name'], $dir, $key, $mime);
                        }
                        PropertyService::recordEdit($p, $uid, $ip, ['photo' => 'added'], $asAdmin);
                    } catch (\Throwable $e) {
                        ImageProcessor::deleteFiles($abs, $key);
                        throw $e;
                    }
                });
                $added++;
            } catch (MediaException $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }
        if ($added > 0) {
            ActivityLog::event($uid, 'media.photos_added', 'property', $propertyId, ['count' => $added], $ip);
        }
        return ['added' => $added, 'errors' => $errors];
    }

    /** One video per listing; a new upload replaces the old one. @throws MediaException */
    public static function addVideo(int $propertyId, int $uid, array $file, string $ip, bool $asAdmin = false): void
    {
        $limits = self::limits();
        self::checkUpload($file, $limits['video_bytes']);
        $tmp = (string) $file['tmp_name'];
        $mime = self::sniff($tmp);
        $head = (string) file_get_contents($tmp, false, null, 0, 16);
        // Trust the bytes, not the name: MP4 has an "ftyp" box, WebM starts with the EBML id.
        if ($mime === 'video/quicktime' || ($mime === 'video/mp4' && substr($head, 8, 2) === 'qt')) {
            throw new MediaException('This is a .MOV video. Please upload MP4 or WebM, or paste a YouTube link instead.');
        }
        $ok = ($mime === 'video/mp4' && substr($head, 4, 4) === 'ftyp') || ($mime === 'video/webm' && str_starts_with($head, "\x1A\x45\xDF\xA3"));
        if (!$ok) {
            throw new MediaException('Only MP4 or WebM videos can be uploaded.');
        }
        $ext = self::VIDEO_MIMES[$mime];

        Database::transaction(function () use ($propertyId, $uid, $file, $tmp, $mime, $ext, $ip, $asAdmin): void {
            $p = self::lockProperty($propertyId, $uid, $asAdmin);
            $dir = 'properties/' . gmdate('Y/m');
            $abs = PUBLIC_ROOT . '/uploads/' . $dir;
            if (!is_dir($abs) && !mkdir($abs, 0755, true) && !is_dir($abs)) {
                throw new \RuntimeException('Cannot create upload folder');
            }
            $key = bin2hex(random_bytes(16));
            $dest = "{$abs}/{$key}.{$ext}";
            $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : rename($tmp, $dest);
            if (!$moved) {
                throw new \RuntimeException('Could not store the video');
            }
            @chmod($dest, 0644);
            try {
                Database::run("UPDATE property_media SET status = 'deleted', deleted_at = UTC_TIMESTAMP() WHERE property_id = ? AND media_type = 'video' AND status = 'active'", [$propertyId]);
                Database::insert(
                    "INSERT INTO property_media (property_id, media_type, storage_dir, file_key, ext, size_bytes, mime_type, original_name, uploaded_by)
                     VALUES (?, 'video', ?, ?, ?, ?, ?, ?, ?)",
                    [$propertyId, $dir, $key, $ext, (int) filesize($dest), $mime, self::displayName((string) $file['name']), $uid]
                );
                PropertyService::recordEdit($p, $uid, $ip, ['video' => 'uploaded'], $asAdmin);
            } catch (\Throwable $e) {
                @unlink($dest);
                throw $e;
            }
        });
        ActivityLog::event($uid, 'media.video_added', 'property', $propertyId, [], $ip);
    }

    /** @return bool false when the media item is not this listing's */
    public static function setCover(int $propertyId, int $uid, int $mediaId, string $ip, bool $asAdmin = false): bool
    {
        return Database::transaction(function () use ($propertyId, $uid, $mediaId, $ip, $asAdmin): bool {
            $p = self::lockProperty($propertyId, $uid, $asAdmin);
            if (self::item($propertyId, $mediaId, 'image') === null) {
                return false;
            }
            if ((int) $p['cover_media_id'] !== $mediaId) {
                self::makeCover($propertyId, $mediaId);
                PropertyService::recordEdit($p, $uid, $ip, ['photo' => 'cover'], $asAdmin);
            }
            return true;
        });
    }

    /** Swap a photo with its neighbour in the display order. */
    public static function move(int $propertyId, int $uid, int $mediaId, string $direction, string $ip, bool $asAdmin = false): bool
    {
        return Database::transaction(function () use ($propertyId, $uid, $mediaId, $direction, $ip, $asAdmin): bool {
            $p = self::lockProperty($propertyId, $uid, $asAdmin);
            $ids = array_map('intval', array_column(self::photos($propertyId), 'id'));
            $i = array_search($mediaId, $ids, true);
            if ($i === false) {
                return false;
            }
            $j = $direction === 'up' ? $i - 1 : $i + 1;
            if ($j < 0 || $j >= count($ids)) {
                return true; // already first / last
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $n => $id) {
                Database::run('UPDATE property_media SET sort_order = ? WHERE id = ?', [$n + 1, $id]);
            }
            // The first photo is the cover, so moving one into first place makes it the cover.
            self::makeCover($propertyId, $ids[0]);
            PropertyService::recordEdit($p, $uid, $ip, ['photo' => 'reordered'], $asAdmin);
            return true;
        });
    }

    /**
     * Soft delete (files are removed by cron after 30 days, see bin/cron.php).
     * @return bool false when the media item is not this listing's
     */
    public static function delete(int $propertyId, int $uid, int $mediaId, string $ip, bool $asAdmin = false): bool
    {
        return Database::transaction(function () use ($propertyId, $uid, $mediaId, $ip, $asAdmin): bool {
            $p = self::lockProperty($propertyId, $uid, $asAdmin);
            $m = self::item($propertyId, $mediaId, null);
            if ($m === null) {
                return false;
            }
            Database::run("UPDATE property_media SET status = 'deleted', is_cover = 0, deleted_at = UTC_TIMESTAMP() WHERE id = ?", [$mediaId]);
            if ((int) $p['cover_media_id'] === $mediaId) {
                $next = Database::value("SELECT id FROM property_media WHERE property_id = ? AND media_type = 'image' AND status = 'active' ORDER BY sort_order, id LIMIT 1", [$propertyId]);
                if ($next !== null) {
                    self::makeCover($propertyId, (int) $next);
                } else {
                    Database::run('UPDATE properties SET cover_media_id = NULL WHERE id = ?', [$propertyId]);
                }
            }
            PropertyService::recordEdit($p, $uid, $ip, [$m['media_type'] => 'deleted'], $asAdmin);
            ActivityLog::event($uid, 'media.deleted', 'property', $propertyId, ['media' => $mediaId], $ip);
            return true;
        });
    }

    /** Cron: remove files of media deleted more than $days ago. Rows stay as history. */
    public static function purgeDeletedFiles(int $days = 30): int
    {
        $n = 0;
        $rows = Database::all("SELECT id, media_type, storage_dir, file_key, ext FROM property_media
                                WHERE status = 'deleted' AND deleted_at < UTC_TIMESTAMP() - INTERVAL ? DAY AND size_bytes > 0 LIMIT 500", [$days]);
        foreach ($rows as $m) {
            $abs = PUBLIC_ROOT . '/uploads/' . $m['storage_dir'];
            if ($m['media_type'] === 'video') {
                @unlink("{$abs}/{$m['file_key']}.{$m['ext']}");
            } else {
                ImageProcessor::deleteFiles($abs, $m['file_key']);
            }
            Database::run('UPDATE property_media SET size_bytes = 0 WHERE id = ?', [$m['id']]); // marks "files gone"
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------

    private static function lockProperty(int $propertyId, int $uid, bool $asAdmin): array
    {
        $p = PropertyService::lock($propertyId, $uid, $asAdmin);
        if ($p === null) {
            throw new MediaException('Listing not found.');
        }
        return $p;
    }

    private static function item(int $propertyId, int $mediaId, ?string $type): ?array
    {
        $m = Database::one("SELECT * FROM property_media WHERE id = ? AND property_id = ? AND status = 'active'", [$mediaId, $propertyId]);
        return $m !== null && ($type === null || $m['media_type'] === $type) ? $m : null;
    }

    private static function makeCover(int $propertyId, int $mediaId): void
    {
        Database::run("UPDATE property_media SET is_cover = (id = ?) WHERE property_id = ? AND media_type = 'image'", [$mediaId, $propertyId]);
        Database::run('UPDATE properties SET cover_media_id = ? WHERE id = ?', [$mediaId, $propertyId]);
    }

    /** @throws MediaException */
    public static function checkUpload(array $file, int $maxBytes): void
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $mb = self::mb($maxBytes);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || ($err === UPLOAD_ERR_OK && (int) $file['size'] > $maxBytes)) {
            throw new MediaException("This file is too big. The limit is {$mb}.");
        }
        if ($err === UPLOAD_ERR_NO_FILE) {
            throw new MediaException('Please choose a file.');
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new MediaException('The upload did not finish. Please try again.');
        }
        if ((int) $file['size'] === 0) {
            throw new MediaException('This file is empty.');
        }
    }

    /** MIME type from the file's bytes (never the name or the browser's claim). */
    public static function sniff(string $path): string
    {
        return (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    }

    private static function keepOriginal(string $tmp, string $dir, string $key, string $mime): void
    {
        $abs = APP_ROOT . '/storage/originals/' . $dir; // outside the web root
        if (!is_dir($abs)) {
            @mkdir($abs, 0750, true);
        }
        @copy($tmp, $abs . '/' . $key . '.' . ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime]);
    }

    private static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\p{L}\p{N} ._()-]/u', '', $name);
        return mb_substr($name !== '' ? $name : 'file', 0, 150);
    }

    public static function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB';
    }

    private static function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        if ($v === '' || $v === '0' || $v === '-1') {
            return PHP_INT_MAX;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
        };
    }
}
