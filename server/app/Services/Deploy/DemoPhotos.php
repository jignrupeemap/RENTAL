<?php
declare(strict_types=1);

namespace App\Services\Deploy;

use App\Core\Database;
use App\Services\Media\ImageProcessor;

/**
 * Real stock photos for DEMO listings, downloaded from Unsplash when the
 * demo seed runs (Unsplash License: free to use, no attribution needed).
 * Each URL is fetched once per run and cached in a temp folder. A photo
 * that cannot be downloaded returns null, so callers fall back to a
 * generated picture and seeding never fails because of the network.
 */
final class DemoPhotos
{
    /** kind => Unsplash photo ids */
    private const POOLS = [
        'tower'   => ['1545324418-cc1a3fa10c00', '1515263487990-61b07816b324', '1574362848149-11496d93a7c7', '1460317442991-0ec209397118', '1574958269340-fa927503f3dd'],
        'house'   => ['1512917774080-9991f1c4c750', '1568605114967-8130f3a36994', '1570129477492-45c003edd2be', '1580587771525-78b9dba3b914', '1564013799919-ab600027ffc6'],
        'shop'    => ['1486406146926-c627a92ad1ab', '1441986300917-64674bd600d8', '1497366216548-37526070297c', '1497366811353-6870744d04b2'],
        'shed'    => ['1586528116311-ad8dd3c8310d', '1553413077-190dd305871c', '1587293852726-70cdb56c2866'],
        'room'    => ['1502672260266-1c1ef2d93688', '1522708323590-d24dbb6b0267', '1493809842364-78817add7ffb', '1505691938895-1758d7feb511',
                      '1484154218962-a197022b5858', '1522771739844-6a9f6d5f14af', '1555041469-a586c61ea9bc', '1560448204-e02f11c3d0e2'],
        'office'  => ['1497366216548-37526070297c', '1497366811353-6870744d04b2', '1524758631624-e2822e304c36', '1497215728101-856f4ea42174'],
    ];

    /** Photos added this run: real downloads vs generated fallbacks. */
    public static int $realUsed = 0;
    public static int $drawnUsed = 0;

    private static ?string $dir = null;
    /** @var array<string, string|false> url => cached file, or false after a failed download */
    private static array $cache = [];

    /** Inside view for a listing: rooms for homes/PG, office or warehouse for the rest. */
    public static function insideKind(string $outsideKind): string
    {
        return match ($outsideKind) {
            'shop' => 'office',
            'shed' => 'shed',
            default => 'room',
        };
    }

    /**
     * A local JPEG copy of a photo of this kind, or null if none could be
     * downloaded. $n picks a different photo for different listings. The
     * returned file is a cached copy: do not delete it.
     */
    public static function get(string $kind, int $n): ?string
    {
        $pool = self::POOLS[$kind] ?? self::POOLS['room'];
        for ($try = 0; $try < count($pool); $try++) {
            $id = $pool[($n + $try) % count($pool)];
            $base = getenv('DEMO_PHOTO_BASE') ?: 'https://images.unsplash.com/'; // override only for tests
            $file = self::download("{$base}photo-{$id}?w=1600&h=1067&fit=crop&fm=jpg&q=80");
            if ($file !== null) {
                return $file;
            }
        }
        return null;
    }

    /** Outside-view kind for a property type. */
    public static function kindOf(string $typeCode): string
    {
        return match ($typeCode) {
            'flat' => 'tower',
            'bungalow', 'farmhouse_villa' => 'house',
            'office', 'shop', 'showroom', 'restaurant', 'hall', 'house_building', 'hospital', 'school', 'hotel', 'gym' => 'shop',
            default => 'shed',
        };
    }

    /**
     * Adds two photos to a listing, outside view (cover) and inside view,
     * real when they download, generated otherwise.
     * @return list<array{0:string,1:string}> [dir, key] of the files written
     */
    public static function attach(int $propertyId, string $typeCode, string $title, int $uploaderId, int $n): array
    {
        $dir = 'properties/' . gmdate('Y/m');
        $abs = PUBLIC_ROOT . '/uploads/' . $dir;
        $outside = self::kindOf($typeCode);
        $written = [];
        try {
            foreach ([$outside, self::insideKind($outside)] as $i => $kind) {
                $real = self::get($kind, $n);
                $real === null ? self::$drawnUsed++ : self::$realUsed++;
                $src = $real ?? self::draw($kind === 'office' ? 'room' : $kind, $propertyId * 10 + $i, $i === 0 ? $title : 'inside view');
                $key = bin2hex(random_bytes(16));
                try {
                    $img = ImageProcessor::process($src, 'image/jpeg', $abs, $key);
                } finally {
                    if ($real === null) {
                        @unlink($src);
                    }
                }
                $written[] = [$abs, $key];
                $mid = Database::insert(
                    "INSERT INTO property_media (property_id, media_type, storage_dir, file_key, ext, has_webp, width, height, size_bytes,
                                                 mime_type, original_name, alt_text, sort_order, is_cover, uploaded_by)
                     VALUES (?, 'image', ?, ?, 'jpg', ?, ?, ?, ?, 'image/jpeg', ?, ?, ?, ?, ?)",
                    [$propertyId, $dir, $key, $img['has_webp'] ? 1 : 0, $img['width'], $img['height'], $img['size_bytes'],
                     "demo-{$i}.jpg", $title, $i, $i === 0 ? 1 : 0, $uploaderId]
                );
                if ($i === 0) {
                    Database::run('UPDATE properties SET cover_media_id = ? WHERE id = ?', [$mid, $propertyId]);
                }
            }
        } catch (\Throwable $e) {
            foreach ($written as [$d, $k]) {
                ImageProcessor::deleteFiles($d, $k);
            }
            throw $e;
        }
        return $written;
    }

    /**
     * Draws a simple, clearly-fake property picture (1200 x 800 JPEG) for
     * when no real photo can be downloaded. $kind: tower | house | shop |
     * shed | room. Returns a temp file the caller deletes.
     */
    public static function draw(string $kind, int $seed, string $caption): string
    {
        mt_srand($seed);
        [$w, $h] = [1200, 800];
        $im = imagecreatetruecolor($w, $h);
        $c = static fn (int|float $r, int|float $g, int|float $b): int => imagecolorallocate($im, ...array_map(static fn ($v) => max(0, min(255, (int) $v)), [$r, $g, $b]));
        $hue = [[47, 111, 143], [58, 134, 120], [150, 96, 70], [120, 84, 140], [70, 110, 160], [160, 120, 60]][mt_rand(0, 5)];
        $wall = $c(...array_map(static fn ($v) => $v + mt_rand(-15, 15), $hue));
        $light = $c(236, 240, 244);

        if ($kind === 'room') {
            // Interior: wall, floor, window, sofa/bed block.
            imagefilledrectangle($im, 0, 0, $w, 560, $c(232 - mt_rand(0, 20), 226 - mt_rand(0, 20), 214));
            for ($y = 560; $y < $h; $y++) {
                imageline($im, 0, $y, $w, $y, $c(170 - ($y - 560) / 6, 130 - ($y - 560) / 8, 95));
            }
            imagefilledrectangle($im, 760, 120, 1060, 420, $c(170, 205, 235));
            imagerectangle($im, 760, 120, 1060, 420, $c(255, 255, 255));
            imageline($im, 910, 120, 910, 420, $c(255, 255, 255));
            imagefilledrectangle($im, 140, 420, 620, 600, $wall);
            imagefilledrectangle($im, 140, 360, 620, 440, $c($hue[0] + 30, $hue[1] + 30, $hue[2] + 30));
            imagefilledrectangle($im, 260, 140, 480, 300, $c(255, 255, 255));
            imagefilledrectangle($im, 272, 152, 468, 288, $c($hue[0] + 60, $hue[1] + 50, $hue[2] + 40));
        } else {
            // Sky gradient and ground.
            for ($y = 0; $y < 600; $y++) {
                imageline($im, 0, $y, $w, $y, $c(120 + $y / 6, 170 + $y / 10, 225));
            }
            imagefilledrectangle($im, 0, 600, $w, $h, $c(110, 150, 95));
            imagefilledrectangle($im, 0, 640, $w, 720, $c(120, 120, 125));
            for ($x = 0; $x < $w; $x += 120) {
                imagefilledrectangle($im, $x + 20, 676, $x + 80, 684, $c(235, 235, 235));
            }
            $windows = static function (int $x1, int $y1, int $x2, int $y2, int $cols, int $rows) use ($im, $c): void {
                $cw = ($x2 - $x1) / $cols;
                $rh = ($y2 - $y1) / $rows;
                for ($i = 0; $i < $cols; $i++) {
                    for ($j = 0; $j < $rows; $j++) {
                        $lit = mt_rand(0, 4) === 0;
                        imagefilledrectangle($im, (int) ($x1 + $i * $cw + $cw * .2), (int) ($y1 + $j * $rh + $rh * .2),
                            (int) ($x1 + ($i + 1) * $cw - $cw * .2), (int) ($y1 + ($j + 1) * $rh - $rh * .25),
                            $lit ? $c(250, 225, 150) : $c(185, 215, 240));
                    }
                }
            };
            switch ($kind) {
                case 'tower':
                    $floors = mt_rand(7, 11);
                    $top = 600 - $floors * 48;
                    imagefilledrectangle($im, 330, $top, 870, 600, $wall);
                    imagefilledrectangle($im, 330, $top - 14, 870, $top, $light);
                    $windows(350, $top + 10, 850, 590, 6, $floors);
                    imagefilledrectangle($im, 900, 360, 1080, 600, $c($hue[0] - 20, $hue[1] - 20, $hue[2] - 20));
                    $windows(910, 370, 1070, 590, 3, 5);
                    break;
                case 'house':
                    imagefilledrectangle($im, 300, 330, 900, 600, $wall);
                    imagefilledpolygon($im, [270, 340, 600, 160, 930, 340], $c(150, 60, 45));
                    imagefilledrectangle($im, 540, 450, 660, 600, $c(110, 70, 40));
                    $windows(330, 370, 520, 560, 2, 2);
                    $windows(680, 370, 870, 560, 2, 2);
                    imagefilledellipse($im, 1040, 540, 180, 200, $c(60, 120, 60));
                    imagefilledellipse($im, 150, 550, 160, 170, $c(70, 130, 65));
                    break;
                case 'shop':
                    imagefilledrectangle($im, 200, 260, 1000, 600, $wall);
                    imagefilledrectangle($im, 200, 230, 1000, 260, $light);
                    for ($x = 200; $x < 1000; $x += 80) {
                        imagefilledrectangle($im, $x, 330, $x + 40, 380, $c(240, 240, 240));
                        imagefilledrectangle($im, $x + 40, 330, $x + 80, 380, $c(210, 70, 60));
                    }
                    imagefilledrectangle($im, 240, 400, 960, 600, $c(190, 220, 240));
                    imageline($im, 600, 400, 600, 600, $c(90, 90, 90));
                    imagefilledrectangle($im, 360, 275, 840, 320, $c(255, 255, 255));
                    break;
                default: // shed / warehouse
                    imagefilledrectangle($im, 160, 340, 1040, 600, $c(170, 175, 180));
                    imagefilledpolygon($im, [130, 350, 600, 200, 1070, 350], $wall);
                    for ($x = 170; $x < 1040; $x += 22) {
                        imageline($im, $x, 350, $x, 600, $c(150, 155, 160));
                    }
                    imagefilledrectangle($im, 300, 430, 520, 600, $c(95, 100, 105));
                    imagefilledrectangle($im, 680, 430, 900, 600, $c(95, 100, 105));
                    break;
            }
        }

        // "DEMO" caption, scaled up from GD's built-in font.
        $text = strtoupper('Demo photo · ' . $caption);
        $text = preg_replace('/[^\x20-\x7E]/', '-', $text);
        $tw = imagefontwidth(5) * strlen($text) + 12;
        $label = imagecreatetruecolor($tw, 24);
        imagefill($label, 0, 0, imagecolorallocate($label, 20, 30, 40));
        imagestring($label, 5, 6, 4, $text, imagecolorallocate($label, 255, 255, 255));
        imagecopyresized($im, $label, 24, $h - 72, 0, 0, $tw * 2, 48, $tw, 24);
        imagedestroy($label);

        $tmp = tempnam(sys_get_temp_dir(), 'demo');
        imagejpeg($im, $tmp, 90);
        imagedestroy($im);
        return $tmp;
    }

    /** Remove the downloaded copies. */
    public static function cleanup(): void
    {
        foreach (self::$cache as $file) {
            if ($file !== false) {
                @unlink($file);
            }
        }
        if (self::$dir !== null) {
            @rmdir(self::$dir);
        }
        self::$cache = [];
        self::$dir = null;
    }

    private static function download(string $url): ?string
    {
        if (array_key_exists($url, self::$cache)) {
            return self::$cache[$url] ?: null;
        }
        self::$dir ??= sys_get_temp_dir() . '/demo-photos-' . bin2hex(random_bytes(4));
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0700, true);
        }
        $file = self::$dir . '/' . md5($url) . '.jpg';
        $ok = false;
        if (function_exists('curl_init')) {
            $fh = fopen($file, 'wb');
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'homeforrent-demo-seed']);
            $ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
            curl_close($ch);
            fclose($fh);
        } else {
            $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30]]));
            $ok = $data !== false && file_put_contents($file, $data) !== false;
        }
        $info = $ok ? @getimagesize($file) : false;
        if ($info === false || $info['mime'] !== 'image/jpeg') {
            @unlink($file);
            self::$cache[$url] = false;
            return null;
        }
        return self::$cache[$url] = $file;
    }
}
