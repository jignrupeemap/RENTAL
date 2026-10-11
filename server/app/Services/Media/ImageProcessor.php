<?php
declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Config;

/**
 * Turns one uploaded photo into web sizes with GD (available on every
 * Hostinger plan):
 *   {key}_thumb.jpg / .webp   480 px longest edge  (cards, admin lists)
 *   {key}_md.jpg    / .webp   960 px               (detail page, phones)
 *   {key}_lg.jpg    / .webp   1600 px              (detail page, desktop)
 * Never upscales. Re-encoding drops EXIF (including any GPS position the
 * phone stored). The original is discarded unless the caller keeps it.
 */
final class ImageProcessor
{
    /** Width/height of a size variant, from the stored large size. Same rounding as process(). */
    public static function variantSize(int $w, int $h, int $target): array
    {
        $scale = min(1.0, $target / max($w, $h, 1));
        return [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
    }

    /**
     * @return array{width:int, height:int, has_webp:bool, size_bytes:int}
     * @throws MediaException with a message safe to show the owner
     */
    public static function process(string $tmpPath, string $mime, string $destDir, string $key): array
    {
        $cfg = Config::get('uploads');
        $info = @getimagesize($tmpPath);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new MediaException('This file is not a readable photo.');
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if (max($w, $h) < $cfg['min_image_px']) {
            throw new MediaException("This photo is too small ({$w}×{$h}). Please use one at least {$cfg['min_image_px']} pixels wide or tall.");
        }
        if (max($w, $h) > $cfg['max_image_px'] || $w * $h > $cfg['max_image_megapixels'] * 1000000) {
            throw new MediaException("This photo is too large ({$w}×{$h}). Please use one under {$cfg['max_image_megapixels']} megapixels.");
        }

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png'  => @imagecreatefrompng($tmpPath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
            default      => false,
        };
        if (!$src instanceof \GdImage) {
            throw new MediaException('This photo could not be read. Please save it again as JPG and retry.');
        }
        $src = self::orient($src, $tmpPath, $mime);
        [$w, $h] = [imagesx($src), imagesy($src)];

        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            imagedestroy($src);
            throw new \RuntimeException('Cannot create upload folder ' . $destDir);
        }

        $written = [];
        $webp = function_exists('imagewebp');
        $result = ['width' => 0, 'height' => 0, 'has_webp' => $webp, 'size_bytes' => 0];
        try {
            foreach ($cfg['image_sizes'] as $name => $target) {
                [$vw, $vh] = self::variantSize($w, $h, (int) $target);
                $img = imagecreatetruecolor($vw, $vh);
                // Flatten transparency (PNG/WebP) onto white for JPEG.
                imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
                imagecopyresampled($img, $src, 0, 0, 0, 0, $vw, $vh, $w, $h);
                imageinterlace($img, true); // progressive JPEG

                $jpg = "{$destDir}/{$key}_{$name}.jpg";
                if (!imagejpeg($img, $jpg, (int) $cfg['jpeg_quality'])) {
                    throw new \RuntimeException('imagejpeg failed');
                }
                $written[] = $jpg;
                if ($webp) {
                    $wp = "{$destDir}/{$key}_{$name}.webp";
                    if (@imagewebp($img, $wp, (int) $cfg['webp_quality'])) {
                        $written[] = $wp;
                    } else {
                        $result['has_webp'] = false;
                    }
                }
                imagedestroy($img);
                if ($name === 'lg') {
                    $result['width'] = $vw;
                    $result['height'] = $vh;
                    $result['size_bytes'] = (int) filesize($jpg);
                }
            }
        } catch (\Throwable $e) {
            foreach ($written as $f) {
                @unlink($f);
            }
            throw $e;
        } finally {
            imagedestroy($src);
        }
        foreach ($written as $f) {
            @chmod($f, 0644);
        }
        return $result;
    }

    /** Remove every generated file for a key. */
    public static function deleteFiles(string $dir, string $key): void
    {
        foreach (glob($dir . '/' . $key . '_*.{jpg,webp}', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
    }

    /** Phones store photos sideways with an EXIF "rotate me" flag; apply it. */
    private static function orient(\GdImage $img, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $rotated = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };
        if ($rotated instanceof \GdImage) {
            imagedestroy($img);
            return $rotated;
        }
        return $img;
    }
}
