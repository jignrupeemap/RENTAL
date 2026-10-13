<?php
declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Config;
use App\Core\Logger;

/**
 * Picture of a listing's map area for the public page: OpenStreetMap
 * tiles stitched with GD into one JPEG, softly blurred for an approximate
 * pin (with the 500 m circle) or sharp with a dot for an exact one.
 *
 * Made once per location and kept in public/uploads/maps/, so the page
 * shows a plain <img>: no Google key, billing or browser script involved.
 * The tiles are fetched only when the picture is first needed (or by
 * bin/map-images.php after a deploy). A failed fetch is not retried for
 * an hour; meanwhile the page falls back to the Google map.
 */
final class AreaMapImage
{
    public const WIDTH = 768;
    public const HEIGHT = 320;
    private const VERSION = 'v1';
    private const RADIUS_M = 500;          // approximate area circle (public point is 150-400 m off)
    private const FAIL_RETRY = 3600;       // seconds before trying a failed location again
    private const DEFAULT_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

    /** Public URL path of the picture, making it if needed; null when it can't be made now. */
    public static function url(float $lat, float $lng, bool $exact, bool $create = true): ?string
    {
        $name = self::fileName($lat, $lng, $exact);
        $file = self::dir() . '/' . $name;
        if (is_file($file)) {
            return 'uploads/maps/' . $name;
        }
        if (!$create || !function_exists('imagecreatetruecolor')) {
            return null;
        }
        $failMarker = APP_ROOT . '/storage/cache/mapfail-' . $name;
        if (is_file($failMarker) && time() - (int) filemtime($failMarker) < self::FAIL_RETRY) {
            return null;
        }
        if (self::make($lat, $lng, $exact, $file)) {
            @unlink($failMarker);
            return 'uploads/maps/' . $name;
        }
        @touch($failMarker);
        return null;
    }

    public static function fileName(float $lat, float $lng, bool $exact): string
    {
        return md5(self::VERSION . '|' . sprintf('%.6f,%.6f', $lat, $lng) . '|' . ($exact ? 'e' : 'a')) . '.jpg';
    }

    public static function dir(): string
    {
        return PUBLIC_ROOT . '/uploads/maps';
    }

    private static function make(float $lat, float $lng, bool $exact, string $file): bool
    {
        $z = $exact ? 16 : 15;
        $world = 256 * (2 ** $z);
        $cx = ($lng + 180) / 360 * $world;
        $sin = sin(deg2rad($lat));
        $cy = (0.5 - log((1 + $sin) / (1 - $sin)) / (4 * M_PI)) * $world;
        $x0 = (int) floor($cx - self::WIDTH / 2);
        $y0 = (int) floor($cy - self::HEIGHT / 2);
        $tx0 = intdiv($x0, 256);
        $ty0 = intdiv($y0, 256);
        $tx1 = intdiv($x0 + self::WIDTH - 1, 256);
        $ty1 = intdiv($y0 + self::HEIGHT - 1, 256);

        $urls = [];
        $template = (string) Config::env('MAP_TILE_URL', '') ?: self::DEFAULT_TILES;
        for ($tx = $tx0; $tx <= $tx1; $tx++) {
            for ($ty = $ty0; $ty <= $ty1; $ty++) {
                $urls["$tx,$ty"] = strtr($template, ['{z}' => (string) $z, '{x}' => (string) $tx, '{y}' => (string) $ty]);
            }
        }
        $tiles = self::fetchAll($urls);
        if ($tiles === null) {
            return false;
        }

        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        foreach ($tiles as $key => $bytes) {
            [$tx, $ty] = array_map('intval', explode(',', $key));
            $tile = @imagecreatefromstring($bytes);
            if ($tile === false) {
                imagedestroy($img);
                Logger::warning('Area map: tile is not an image', ['tile' => $key]);
                return false;
            }
            imagecopy($img, $tile, $tx * 256 - $x0, $ty * 256 - $y0, 0, 0, 256, 256);
            imagedestroy($tile);
        }

        $px = $cx - $x0;
        $py = $cy - $y0;
        if ($exact) {
            $ring = imagecolorallocate($img, 255, 255, 255);
            $dot = imagecolorallocate($img, 15, 92, 99);  // brand teal
            imagefilledellipse($img, (int) $px, (int) $py, 26, 26, $ring);
            imagefilledellipse($img, (int) $px, (int) $py, 18, 18, $dot);
        } else {
            for ($i = 0; $i < 6; $i++) {
                imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR);
            }
            $metersPerPx = 156543.03392 * cos(deg2rad($lat)) / (2 ** $z);
            $d = (int) round(2 * self::RADIUS_M / $metersPerPx);
            imagefilledellipse($img, (int) $px, (int) $py, $d, $d, imagecolorallocatealpha($img, 13, 110, 253, 100));
            imagesetthickness($img, 2);
            imageellipse($img, (int) $px, (int) $py, $d, $d, imagecolorallocatealpha($img, 13, 110, 253, 40));
        }

        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0755, true)) {
            imagedestroy($img);
            return false;
        }
        $tmp = $file . '.tmp';
        $ok = imagejpeg($img, $tmp, 82) && rename($tmp, $file);
        imagedestroy($img);
        if (!$ok) {
            @unlink($tmp);
        }
        return $ok;
    }

    /**
     * Fetches every tile in parallel; null if any of them fails.
     * @param array<string,string> $urls
     * @return ?array<string,string>
     */
    private static function fetchAll(array $urls): ?array
    {
        if (!function_exists('curl_multi_init')) {
            return null;
        }
        $agent = 'Homeforrent/1.0 (+' . rtrim((string) Config::env('APP_URL', ''), '/') . ')';
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
                CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => $agent,
                CURLOPT_REFERER => rtrim((string) Config::env('APP_URL', ''), '/') . '/',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        $failed = null;
        foreach ($handles as $key => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $body = curl_multi_getcontent($ch);
            if ($code !== 200 || !is_string($body) || $body === '') {
                $failed ??= ['tile' => $key, 'status' => $code, 'error' => curl_error($ch)];
            } else {
                $out[$key] = $body;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        if ($failed !== null) {
            Logger::warning('Area map: tile download failed', $failed);
            return null;
        }
        return $out;
    }
}
