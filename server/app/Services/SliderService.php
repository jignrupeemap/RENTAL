<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Services\Admin\AdminLog;
use App\Services\Media\ImageProcessor;
use App\Services\Media\MediaException;
use App\Services\Media\MediaService;

/**
 * Homepage hero slider (spec 19, 36). Images go through the same pipeline
 * as listing photos (MIME sniffing, GD re-encode to 480/960/1600 px JPEG +
 * WebP, EXIF dropped) into public/uploads/sliders/YYYY/MM. Every admin
 * change is written to the admin activity log.
 */
final class SliderService
{
    /** Hero images must be landscape and at least this wide so they stay sharp. */
    public const MIN_WIDTH = 1200;

    /** Slides visitors see now: active and inside their optional date window, in display order. */
    public static function live(): array
    {
        return Database::all(
            'SELECT * FROM homepage_sliders
              WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP()) AND (ends_at IS NULL OR ends_at > UTC_TIMESTAMP())
              ORDER BY sort_order, id'
        );
    }

    public static function all(): array
    {
        return Database::all('SELECT * FROM homepage_sliders ORDER BY sort_order, id');
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM homepage_sliders WHERE id = ?', [$id]);
    }

    /** Media-shaped array so media_url()/media_picture() work for slides. */
    public static function media(array $s): array
    {
        return ['storage_dir' => $s['image_dir'], 'file_key' => $s['image_key'], 'has_webp' => $s['has_webp'],
                'width' => $s['image_width'], 'height' => $s['image_height']];
    }

    /**
     * Create (id null) or update a slide. The image is required for a new slide.
     * @return array{0: array<string,string>, 1: ?int} [errors, id]
     */
    public static function save(?int $id, array $input, ?array $file, int $adminId): array
    {
        $old = $id !== null ? self::find($id) : null;
        if ($id !== null && $old === null) {
            return [['_form' => 'Slide not found.'], null];
        }
        [$data, $errors] = self::validate($input);
        $hasFile = $file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($old === null && !$hasFile) {
            $errors['image'] = 'Please choose an image for the slide.';
        }
        if ($errors) {
            return [$errors, null];
        }

        $image = null;
        if ($hasFile) {
            try {
                $image = self::storeImage($file);
            } catch (MediaException $e) {
                return [['image' => $e->getMessage()], null];
            }
        }

        try {
            if ($old === null) {
                $data['sort_order'] = (int) Database::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM homepage_sliders');
                $data += $image + ['created_by' => $adminId, 'is_active' => 1];
                $cols = implode(', ', array_keys($data));
                $id = Database::insert("INSERT INTO homepage_sliders ($cols) VALUES (" . rtrim(str_repeat('?, ', count($data)), ', ') . ')', array_values($data));
                AdminLog::record($adminId, 'slider.created', 'slider', $id, null, self::loggable($data));
            } else {
                $data += $image ?? [];
                $set = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($data)));
                Database::run("UPDATE homepage_sliders SET $set WHERE id = ?", [...array_values($data), $id]);
                $changedOld = array_intersect_key($old, $data);
                AdminLog::record($adminId, 'slider.updated', 'slider', $id, self::loggable($changedOld), self::loggable($data));
                if ($image !== null) {
                    ImageProcessor::deleteFiles(PUBLIC_ROOT . '/uploads/' . $old['image_dir'], $old['image_key']);
                }
            }
        } catch (\Throwable $e) {
            if ($image !== null) {
                ImageProcessor::deleteFiles(PUBLIC_ROOT . '/uploads/' . $image['image_dir'], $image['image_key']);
            }
            throw $e;
        }
        return [[], $id];
    }

    public static function setActive(int $id, bool $active, int $adminId): bool
    {
        $s = self::find($id);
        if ($s === null) {
            return false;
        }
        if ((bool) $s['is_active'] !== $active) {
            Database::run('UPDATE homepage_sliders SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
            AdminLog::record($adminId, $active ? 'slider.activated' : 'slider.deactivated', 'slider', $id, ['active' => (bool) $s['is_active']], ['active' => $active]);
        }
        return true;
    }

    /** Swap with the neighbour above or below, then renumber 1..n so the order stays clean. */
    public static function move(int $id, string $direction, int $adminId): bool
    {
        return Database::transaction(static function () use ($id, $direction, $adminId): bool {
            $ids = array_map('intval', array_column(Database::all('SELECT id FROM homepage_sliders ORDER BY sort_order, id FOR UPDATE'), 'id'));
            $i = array_search($id, $ids, true);
            if ($i === false) {
                return false;
            }
            $j = $direction === 'up' ? $i - 1 : $i + 1;
            if ($j < 0 || $j >= count($ids)) {
                return true; // already first/last
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $n => $sid) {
                Database::run('UPDATE homepage_sliders SET sort_order = ? WHERE id = ?', [$n + 1, $sid]);
            }
            AdminLog::record($adminId, 'slider.moved', 'slider', $id, ['position' => $i + 1], ['position' => $j + 1]);
            return true;
        });
    }

    /** Remove a slide and its image files (slides hold no user data). */
    public static function delete(int $id, int $adminId): bool
    {
        $s = self::find($id);
        if ($s === null) {
            return false;
        }
        Database::run('DELETE FROM homepage_sliders WHERE id = ?', [$id]);
        ImageProcessor::deleteFiles(PUBLIC_ROOT . '/uploads/' . $s['image_dir'], $s['image_key']);
        AdminLog::record($adminId, 'slider.deleted', 'slider', $id, self::loggable($s), null);
        return true;
    }

    /** India date (Y-m-d) shown in the form for a stored start/end time. */
    public static function formDate(?string $utc, bool $isEnd): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $d = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Kolkata'));
        return ($isEnd ? $d->modify('-1 day') : $d)->format('Y-m-d');
    }

    /** @return array{0: array, 1: array<string,string>} [clean column values, errors] */
    private static function validate(array $in): array
    {
        $errors = [];
        $text = static fn (string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in[$k] ?? '')) ?? ''), 0, $max + 1);
        $data = [
            'title'       => $text('title', 120),
            'subtitle'    => $text('subtitle', 255),
            'button_text' => $text('button_text', 40),
            'button_link' => trim((string) ($in['button_link'] ?? '')),
        ];
        foreach (['title' => 120, 'subtitle' => 255, 'button_text' => 40] as $k => $max) {
            if (mb_strlen($data[$k]) > $max) {
                $errors[$k] = "Please keep this under {$max} characters.";
            }
        }
        $link = $data['button_link'];
        if ($link !== '') {
            $internal = str_starts_with($link, '/') && !str_starts_with($link, '//') && !str_contains($link, '\\');
            $external = (bool) preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $link) && filter_var($link, FILTER_VALIDATE_URL) !== false;
            if ((!$internal && !$external) || mb_strlen($link) > 255 || preg_match('/[\s<>"]/', $link)) {
                $errors['button_link'] = 'Use a page on this site such as /rent, or a full https:// address.';
            }
        }
        if (($data['button_text'] === '') !== ($link === '')) {
            $errors[$data['button_text'] === '' ? 'button_text' : 'button_link'] = 'A button needs both its text and its link (or leave both empty).';
        }
        foreach (['starts_at' => 'start', 'ends_at' => 'end'] as $k => $word) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') {
                $data[$k] = null;
                continue;
            }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v, new \DateTimeZone('Asia/Kolkata'));
            if ($d === false || $d->format('Y-m-d') !== $v) {
                $errors[$k] = "Please choose a valid {$word} date.";
                continue;
            }
            // Dates are whole India days: show from the start of the first day to the end of the last.
            $data[$k] = ($k === 'ends_at' ? $d->modify('+1 day') : $d)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if (!isset($errors['starts_at']) && !isset($errors['ends_at']) && $data['starts_at'] !== null && $data['ends_at'] !== null && $data['ends_at'] <= $data['starts_at']) {
            $errors['ends_at'] = 'The end date must be on or after the start date.';
        }
        foreach (['title', 'subtitle', 'button_text', 'button_link'] as $k) {
            $data[$k] = $data[$k] !== '' ? $data[$k] : null;
        }
        return [$data, $errors];
    }

    /** @throws MediaException */
    private static function storeImage(array $file): array
    {
        MediaService::checkUpload($file, MediaService::limits()['image_bytes']);
        $mime = MediaService::sniff((string) $file['tmp_name']);
        if (!in_array($mime, Config::get('uploads.image_mimes'), true)) {
            throw new MediaException('Only JPG, PNG or WebP images can be used.');
        }
        $size = @getimagesize((string) $file['tmp_name']);
        if ($size === false) {
            throw new MediaException('This file is not a readable image.');
        }
        [$w, $h] = [(int) $size[0], (int) $size[1]];
        if ($w < self::MIN_WIDTH || $w < $h * 1.2) {
            throw new MediaException("Please use a wide (landscape) image at least " . self::MIN_WIDTH . " pixels wide. This one is {$w}×{$h}.");
        }
        $dir = 'sliders/' . gmdate('Y/m');
        $key = bin2hex(random_bytes(16));
        $img = ImageProcessor::process((string) $file['tmp_name'], $mime, PUBLIC_ROOT . '/uploads/' . $dir, $key);
        return ['image_dir' => $dir, 'image_key' => $key, 'image_width' => $img['width'], 'image_height' => $img['height'], 'has_webp' => $img['has_webp'] ? 1 : 0];
    }

    private static function loggable(array $row): array
    {
        return array_intersect_key($row, array_flip(['title', 'subtitle', 'button_text', 'button_link', 'starts_at', 'ends_at', 'is_active', 'image_key']));
    }
}
