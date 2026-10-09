<?php
declare(strict_types=1);

/**
 * Phase 5 end-to-end tests: photo and video upload, resizing/WebP,
 * validation, cover/order/delete, limits, ownership, preview markup.
 * DEVELOPMENT ONLY (creates and removes @test.invalid accounts and files).
 *
 *   php -d upload_max_filesize=64M -d post_max_size=70M -S 127.0.0.1:8088 -t public public/index.php &
 *   php tests/phase5_media_test.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Media\MediaService;

if (Config::isProduction() || Config::env('MAIL_DRIVER') !== 'log') {
    fwrite(STDERR, "Run only in development with MAIL_DRIVER=log.\n");
    exit(1);
}
require __DIR__ . '/lib/http.php';

$cleanup = static function (): void {
    $ids = array_map('intval', array_column(Database::all("SELECT id FROM users WHERE email LIKE '%@test.invalid'"), 'id'));
    if (!$ids) {
        return;
    }
    $in = implode(',', $ids);
    $props = array_map('intval', array_column(Database::all("SELECT id FROM properties WHERE owner_id IN ($in)"), 'id')) ?: [0];
    $p = implode(',', $props);
    foreach (Database::all("SELECT storage_dir, file_key FROM property_media WHERE property_id IN ($p)") as $m) {
        array_map('unlink', glob(PUBLIC_ROOT . '/uploads/' . $m['storage_dir'] . '/' . $m['file_key'] . '*') ?: []);
    }
    foreach ([
        "DELETE FROM notifications WHERE user_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in)) OR (entity_type = 'property' AND entity_id IN ($p)) OR (entity_type = 'user' AND entity_id IN ($in))",
        "UPDATE properties SET cover_media_id = NULL, pending_rent_change_id = NULL WHERE id IN ($p)",
        "DELETE FROM rental_change_history WHERE property_id IN ($p)",
        "DELETE FROM shortlists WHERE property_id IN ($p)",
        "DELETE FROM properties WHERE id IN ($p)",
        "DELETE FROM activity_logs WHERE user_id IN ($in)",
        "DELETE FROM login_activity WHERE user_id IN ($in)",
        "DELETE FROM users WHERE id IN ($in)",
        'DELETE FROM rate_limits',
    ] as $sql) {
        Database::run($sql);
    }
};
$cleanup();
$setting = static fn (string $k, string $v) => Database::run('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$v, $k]);
$setting('auth_otp_enabled', '0');
$setting('max_images_per_property', '20');
$setting('max_image_upload_mb', '10');
$setting('max_video_upload_mb', '50');
$setting('reapprove_on_owner_edit', '0');

$pw = 'Secret123';
function registerUser(Browser $b, string $first, string $mobile, string $email): int
{
    global $pw;
    $b->get('/register');
    $b->post('/register', ['first_name' => $first, 'last_name' => 'Test', 'mobile' => $mobile, 'email' => $email,
        'password' => $pw, 'password_confirmation' => $pw, 'terms' => '1', 'website' => '']);
    return (int) Database::value('SELECT id FROM users WHERE email = ?', [$email]);
}
function newDraft(Browser $b, int $owner): int
{
    $b->get('/owner/properties/new');
    $b->post('/owner/properties/new', ['purpose' => 'rent', 'category_id' => 1, 'type_id' => (int) Database::value("SELECT id FROM property_types WHERE code = 'flat'")]);
    return (int) Database::value('SELECT MAX(id) FROM properties WHERE owner_id = ?', [$owner]);
}
function photos(int $pid): array
{
    return Database::all("SELECT * FROM property_media WHERE property_id = ? AND media_type = 'image' AND status = 'active' ORDER BY is_cover DESC, sort_order, id", [$pid]);
}
function files(array $m): array
{
    return glob(PUBLIC_ROOT . '/uploads/' . $m['storage_dir'] . '/' . $m['file_key'] . '*') ?: [];
}
function uploadCount(): int
{
    return count(glob(PUBLIC_ROOT . '/uploads/properties/*/*/*') ?: []);
}
function httpStatus(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => false]);
    $body = (string) curl_exec($ch);
    $r = [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE), $body];
    curl_close($ch);
    return $r;
}

// ---------------------------------------------------------------------
// Fixtures (temp folder, outside the project)
// ---------------------------------------------------------------------
$fx = sys_get_temp_dir() . '/rw-media-' . getmypid();
@mkdir($fx);
$big = testImage("$fx/living-room.jpg", 3000, 2000);
$png = testImage("$fx/plan.png", 1200, 900, 'png');
$webp = testImage("$fx/balcony.webp", 1000, 800, 'webp');
$small = testImage("$fx/tiny.jpg", 300, 200);
$huge = testImage("$fx/huge.jpg", 7000, 5000); // 35 megapixels
file_put_contents("$fx/shell.jpg", "<?php system(\$_GET['c']); ?>");
file_put_contents("$fx/notes.jpg", str_repeat('not an image ', 200));
file_put_contents("$fx/heavy.jpg", random_bytes(1300000));
file_put_contents("$fx/tour.mp4", "\0\0\0\x18ftypmp42\0\0\0\0mp42isom" . random_bytes(200000));
file_put_contents("$fx/tour.webm", "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x02\x42\x85\x81\x02" . random_bytes(150000));
file_put_contents("$fx/iphone.mov", "\0\0\0\x14ftypqt  \0\0\0\0qt  " . random_bytes(50000));
file_put_contents("$fx/fake.mp4", str_repeat('hello ', 1000));
// Sideways phone photo with GPS in EXIF (made with Pillow when available)
$rotated = "$fx/sideways.jpg";
$hasExifFixture = false;
if (trim((string) shell_exec('python3 -c "import PIL; print(1)" 2>/dev/null')) === '1') {
    shell_exec('python3 -c ' . escapeshellarg(
        "from PIL import Image\nim=Image.new('RGB',(1200,800),(200,120,40))\nex=Image.Exif()\nex[0x0112]=6\nex[0x8825]={1:'N',2:(21.0,10.0,0.0),3:'E',4:(72.0,50.0,0.0)}\nim.save('$rotated',exif=ex.tobytes(),quality=85)"
    ));
    $hasExifFixture = is_file($rotated) && (int) (@exif_read_data($rotated)['Orientation'] ?? 0) === 6;
}

$a = new Browser($base);
$ownerA = registerUser($a, 'Rahul', '9222200201', 'rahul5@test.invalid');
$b = new Browser($base);
$ownerB = registerUser($b, 'Bina', '9222200202', 'bina5@test.invalid');
$pid = newDraft($a, $ownerA);
$bid = newDraft($b, $ownerB);
check('test owners and drafts ready', $ownerA > 0 && $ownerB > 0 && $pid > 0 && $bid > 0);

// ---------------------------------------------------------------------
echo "\nPhoto upload and processing\n";
// ---------------------------------------------------------------------
$a->get("/owner/properties/$pid/step/2");
$a->get("/owner/properties/$pid/step/6");
check('step 6 not reachable before steps 2-5 on a draft', redirectsTo($a, "/owner/properties/$pid/step/2"));
Database::run('UPDATE properties SET draft_step = 5 WHERE id = ?', [$pid]);
$a->get("/owner/properties/$pid/step/6");
check('step 6 shows the photo uploader and video uploader', $a->status === 200 && $a->has('Add photos') && $a->has('Upload a video') && $a->has('up to 20 photos') === false && $a->has('Up to 20 photos'));
$a->upload("/owner/properties/$pid/photos", ['photos[]' => $big], [], true);
$j = $a->json();
$ph = photos($pid);
check('JPEG upload answers JSON for the progress bar', $a->status === 200 && ($j['ok'] ?? false) === true && ($j['added'] ?? 0) === 1, $a->body);
check('photo row saved with random 32-hex name', count($ph) === 1 && preg_match('/^[a-f0-9]{32}$/', $ph[0]['file_key']) === 1 && $ph[0]['original_name'] === 'living-room.jpg');
$f = files($ph[0]);
check('three sizes written as JPEG + WebP (6 files)', count($f) === 6, implode(',', array_map('basename', $f)));
$dir = PUBLIC_ROOT . '/uploads/' . $ph[0]['storage_dir'] . '/' . $ph[0]['file_key'];
[$lw, $lh] = getimagesize($dir . '_lg.jpg');
[$mw] = getimagesize($dir . '_md.jpg');
[$tw] = getimagesize($dir . '_thumb.jpg');
check('3000×2000 resized to 1600 / 960 / 480 wide', $lw === 1600 && $lh === 1067 && $mw === 960 && $tw === 480, "$lw x $lh, $mw, $tw");
check('stored size matches the large file', (int) $ph[0]['width'] === 1600 && (int) $ph[0]['height'] === 1067 && (int) $ph[0]['has_webp'] === 1);
check('large photo compressed well below the original', filesize($dir . '_lg.jpg') < filesize($big) && filesize($dir . '_lg.webp') < filesize($dir . '_lg.jpg') * 1.2);
check('first photo becomes the cover', (int) $ph[0]['is_cover'] === 1 && (int) Database::value('SELECT cover_media_id FROM properties WHERE id = ?', [$pid]) === (int) $ph[0]['id']);
[$code, $type] = httpStatus(media_url($ph[0], 'thumb', true));
check('generated WebP is served to browsers', $code === 200 && $type === 'image/webp', "$code $type");
check('original upload not kept (setting off)', !is_dir(APP_ROOT . '/storage/originals/' . $ph[0]['storage_dir']) || !glob(APP_ROOT . '/storage/originals/' . $ph[0]['storage_dir'] . '/' . $ph[0]['file_key'] . '*'));

$a->get("/owner/properties/$pid/step/6");
$a->upload("/owner/properties/$pid/photos", ['photos[]' => [$png, $webp]]);
check('PNG and WebP accepted together (form without JavaScript)', redirectsTo($a, "/owner/properties/$pid/step/6") && count(photos($pid)) === 3 && followHas2($a, '2 photos added.'));
function followHas2(Browser $b, string $t): bool { $b->follow(); return $b->has($t); }

if ($hasExifFixture) {
    $a->upload("/owner/properties/$pid/photos", ['photos[]' => $rotated], [], true);
    $m = Database::one('SELECT * FROM property_media WHERE property_id = ? AND original_name = ?', [$pid, 'sideways.jpg']);
    $out = PUBLIC_ROOT . '/uploads/' . $m['storage_dir'] . '/' . $m['file_key'] . '_lg.jpg';
    $exif = @exif_read_data($out);
    check('sideways phone photo turned upright (1200×800 → 800×1200)', (int) $m['width'] === 800 && (int) $m['height'] === 1200);
    check('EXIF removed from output (no GPS position published)', !is_array($exif) || (!isset($exif['GPSLatitude']) && !isset($exif['Orientation'])));
} else {
    echo "  SKIP  EXIF orientation checks (Pillow not available to build the fixture)\n";
}

// ---------------------------------------------------------------------
echo "\nRefused files\n";
// ---------------------------------------------------------------------
$before = uploadCount();
$rows = count(photos($pid));
$a->upload("/owner/properties/$pid/photos", ['photos[]' => "$fx/shell.jpg"], [], true);
check('PHP code named .jpg is refused', $a->status === 422 && str_contains(implode(' ', $a->json()['errors'] ?? []), 'Only JPG, PNG or WebP'));
$a->upload("/owner/properties/$pid/photos", ['photos[]' => "$fx/notes.jpg"], [], true);
check('text file named .jpg is refused', $a->status === 422);
$a->upload("/owner/properties/$pid/photos", ['photos[]' => $small], [], true);
check('photo under 600 px is refused', str_contains(implode(' ', $a->json()['errors'] ?? []), 'too small'));
$a->upload("/owner/properties/$pid/photos", ['photos[]' => $huge], [], true);
check('35-megapixel photo is refused before decoding', str_contains(implode(' ', $a->json()['errors'] ?? []), 'too large'));
$setting('max_image_upload_mb', '1');
$a->upload("/owner/properties/$pid/photos", ['photos[]' => "$fx/heavy.jpg"], [], true);
check('file over the size limit is refused with the limit', str_contains(implode(' ', $a->json()['errors'] ?? []), 'too big. The limit is 1 MB'));
$setting('max_image_upload_mb', '10');
check('refused files leave no rows and no files', count(photos($pid)) === $rows && uploadCount() === $before);
$a->upload("/owner/properties/$pid/photos", ['photos[]' => [$small, $png]], [], true);
$j = $a->json();
check('mixed batch: good photo saved, bad one reported', ($j['added'] ?? 0) === 1 && count($j['errors'] ?? []) === 1 && str_starts_with($j['errors'][0], 'tiny.jpg:'));
$a->get("/owner/properties/$pid/step/6");
$a->upload("/owner/properties/$pid/photos", [], [], true);
check('upload with no file chosen is refused', $a->status === 422);
$a->upload("/owner/properties/$pid/photos", ['photos[]' => $png], ['_token' => 'wrong'], true);
check('upload without the CSRF token is refused', $a->status === 419);

// ---------------------------------------------------------------------
echo "\nCover, order, delete, limit\n";
// ---------------------------------------------------------------------
$ph = photos($pid);
$n = count($ph);
$a->get("/owner/properties/$pid/step/6");
check('step 6 lists every photo with controls', substr_count($a->body, 'Make cover') === $n - 1 && substr_count($a->body, '>Delete</button>') === $n && $a->has('Cover'));
$third = (int) $ph[2]['id'];
$a->post("/owner/properties/$pid/media/$third/cover");
check('Make cover changes the cover', (int) Database::value('SELECT cover_media_id FROM properties WHERE id = ?', [$pid]) === $third && (int) photos($pid)[0]['id'] === $third);
$a->get("/owner/properties/$pid/step/6");
$second = (int) photos($pid)[1]['id'];
$a->post("/owner/properties/$pid/media/$second/move", ['direction' => 'up']);
check('moving a photo to first place makes it the cover', (int) photos($pid)[0]['id'] === $second && (int) Database::value('SELECT cover_media_id FROM properties WHERE id = ?', [$pid]) === $second);
$a->get("/owner/properties/$pid/step/6");
$a->post("/owner/properties/$pid/media/$second/move", ['direction' => 'down']);
check('move down swaps with the next photo', (int) photos($pid)[1]['id'] === $second);
$cover = (int) photos($pid)[0]['id'];
$a->get("/owner/properties/$pid/step/6");
$a->post("/owner/properties/$pid/media/$cover/delete");
$ph = photos($pid);
check('deleting the cover promotes the next photo', count($ph) === $n - 1 && (int) Database::value('SELECT cover_media_id FROM properties WHERE id = ?', [$pid]) === (int) $ph[0]['id'] && (int) $ph[0]['is_cover'] === 1);
check('deleted photo kept as history (soft delete), files kept for 30 days',
    Database::value('SELECT status FROM property_media WHERE id = ?', [$cover]) === 'deleted' && count(files(Database::one('SELECT * FROM property_media WHERE id = ?', [$cover]))) === 6);

$setting('max_images_per_property', (string) count($ph));
$a->get("/owner/properties/$pid/step/6");
check('at the photo limit the uploader is replaced by a note', $a->has('maximum of ' . count($ph) . ' photos') && !$a->has('Add photos'));
$a->upload("/owner/properties/$pid/photos", ['photos[]' => $png], [], true);
check('upload over the photo limit is refused', $a->status === 422 && str_contains(implode(' ', $a->json()['errors'] ?? []), 'up to ' . count($ph) . ' photos'));
$setting('max_images_per_property', '20');

// Cron: files of photos deleted 30+ days ago are removed, the row stays.
Database::run('UPDATE property_media SET deleted_at = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE id = ?', [$cover]);
$purged = MediaService::purgeDeletedFiles(30);
check('cron removes files of media deleted 30+ days ago', $purged >= 1 && count(files(Database::one('SELECT * FROM property_media WHERE id = ?', [$cover]))) === 0
    && Database::value('SELECT status FROM property_media WHERE id = ?', [$cover]) === 'deleted');

// ---------------------------------------------------------------------
echo "\nVideo\n";
// ---------------------------------------------------------------------
$a->get("/owner/properties/$pid/step/6");
$a->upload("/owner/properties/$pid/video", ['video' => "$fx/tour.mp4"], [], true);
$v = Database::one("SELECT * FROM property_media WHERE property_id = ? AND media_type = 'video' AND status = 'active'", [$pid]);
check('MP4 video uploaded', $a->status === 200 && $v !== null && $v['ext'] === 'mp4' && is_file(PUBLIC_ROOT . '/uploads/' . $v['storage_dir'] . '/' . $v['file_key'] . '.mp4'), $a->body);
[$code, $type] = httpStatus(video_url($v));
check('video file is served as video/mp4', $code === 200 && $type === 'video/mp4', "$code $type");
$a->get("/owner/properties/$pid/step/6");
check('step 6 shows the video player and Replace option', $a->has('<video') && $a->has('preload="none"') && $a->has('Replace the video'));
$a->upload("/owner/properties/$pid/video", ['video' => "$fx/tour.webm"], [], true);
$v2 = Database::one("SELECT * FROM property_media WHERE property_id = ? AND media_type = 'video' AND status = 'active'", [$pid]);
check('WebM replaces the old video (one video per listing)', $v2['ext'] === 'webm' && Database::value('SELECT status FROM property_media WHERE id = ?', [$v['id']]) === 'deleted'
    && (int) Database::value("SELECT COUNT(*) FROM property_media WHERE property_id = ? AND media_type = 'video' AND status = 'active'", [$pid]) === 1);
$a->upload("/owner/properties/$pid/video", ['video' => "$fx/iphone.mov"], [], true);
check('.MOV refused with a helpful message', $a->status === 422 && str_contains(implode(' ', $a->json()['errors'] ?? []), '.MOV video'));
$a->upload("/owner/properties/$pid/video", ['video' => "$fx/fake.mp4"], [], true);
check('text file named .mp4 refused', $a->status === 422 && str_contains(implode(' ', $a->json()['errors'] ?? []), 'Only MP4 or WebM'));
$a->upload("/owner/properties/$pid/video", ['video' => $png], [], true);
check('photo sent as video refused', $a->status === 422);
$setting('max_video_upload_mb', '1');
file_put_contents("$fx/long.mp4", "\0\0\0\x18ftypmp42\0\0\0\0mp42isom" . random_bytes(1200000));
$a->upload("/owner/properties/$pid/video", ['video' => "$fx/long.mp4"], [], true);
check('video over the size limit refused', str_contains(implode(' ', $a->json()['errors'] ?? []), 'too big'));
$setting('max_video_upload_mb', '50');
$a->get("/owner/properties/$pid/step/6");
$a->post("/owner/properties/$pid/media/{$v2['id']}/delete");
check('Delete video', Database::value('SELECT status FROM property_media WHERE id = ?', [$v2['id']]) === 'deleted');

// ---------------------------------------------------------------------
echo "\nOwnership\n";
// ---------------------------------------------------------------------
$photoA = (int) photos($pid)[0]['id'];
$b->get("/owner/properties/$bid/step/2");
$rowsA = count(photos($pid));
$b->upload("/owner/properties/$pid/photos", ['photos[]' => $png], [], true);
check("other owner cannot upload to someone else's listing", $b->status === 404 && count(photos($pid)) === $rowsA);
$b->upload("/owner/properties/$pid/video", ['video' => "$fx/tour.mp4"], [], true);
check("other owner cannot upload a video there", $b->status === 404);
$b->post("/owner/properties/$pid/media/$photoA/delete");
check("other owner cannot delete someone else's photo", $b->status === 404 && Database::value('SELECT status FROM property_media WHERE id = ?', [$photoA]) === 'active');
$b->get("/owner/properties/$bid/step/2");
$b->post("/owner/properties/$bid/media/$photoA/delete");
check("…not even through their own listing's address", $b->status === 404 && Database::value('SELECT status FROM property_media WHERE id = ?', [$photoA]) === 'active');
$b->get("/owner/properties/$bid/step/2");
$b->post("/owner/properties/$bid/media/$photoA/cover");
check("…nor make it their cover", $b->status === 404 && Database::value('SELECT cover_media_id FROM properties WHERE id = ?', [$bid]) === null);
$b->get("/owner/properties/$bid/step/2");
$b->post("/owner/properties/$pid/media/$photoA/move", ['direction' => 'down']);
check("…nor reorder it", $b->status === 404);
Database::run("UPDATE users SET status = 'suspended' WHERE id = ?", [$ownerA]);
$a->upload("/owner/properties/$pid/photos", ['photos[]' => $png]);
check('suspended owner cannot upload', redirectsTo($a, '/account') && count(photos($pid)) === $rowsA);
Database::run("UPDATE users SET status = 'active' WHERE id = ?", [$ownerA]);

// ---------------------------------------------------------------------
echo "\nPhotos in the form, preview and listings\n";
// ---------------------------------------------------------------------
$c = new Browser($base);
$ownerC = registerUser($c, 'Chirag', '9222200203', 'chirag5@test.invalid');
$cid = newDraft($c, $ownerC);
Database::run("UPDATE properties SET draft_step = 5, title = 'No photo flat', bedrooms = 2, bathrooms = 1, furnishing = 'furnished', available_from = CURDATE(),
               address_line = 'Some road', city_id = 1, locality_id = (SELECT id FROM localities WHERE slug = 'vesu'), pincode = '395007', rent = 15000 WHERE id = ?", [$cid]);
Database::run("INSERT INTO property_locations (property_id, latitude, longitude, public_latitude, public_longitude) VALUES (?, 21.14, 72.77, 21.141, 72.771)", [$cid]);
$c->get("/owner/properties/$cid/step/6");
$c->post("/owner/properties/$cid/step/6", ['action' => 'continue']);
check('Save & Continue on step 6 needs at least one photo', followHas2($c, 'Please add at least one photo.'));
$c->get("/owner/properties/$cid/step/6");
$c->post("/owner/properties/$cid/step/6", ['action' => 'draft']);
check('Save Draft on step 6 works without photos', redirectsTo($c, '/owner/properties?status=draft'));
Database::run("UPDATE properties SET draft_step = 7, contact_name = 'Chirag Test', contact_mobile_1 = '919222200203' WHERE id = ?", [$cid]);
$c->get("/owner/properties/$cid/step/8");
check('preview lists Photos as missing', $c->has('Please complete these') && $c->has('Photos & Video') && $c->has('Photos'));
$c->post("/owner/properties/$cid/submit", ['_token' => $c->token()]);
check('listing without photos cannot be submitted', Database::value('SELECT status FROM properties WHERE id = ?', [$cid]) === 'draft');

$a->get("/owner/properties/$pid/step/6");
Database::run('UPDATE properties SET draft_step = 7 WHERE id = ?', [$pid]);
$a->get("/owner/properties/$pid/step/8");
$cov = photos($pid)[0];
check('preview shows the cover as a responsive <picture> (WebP + JPEG)', $a->has('<picture><source type="image/webp"') && $a->has(media_url($cov, 'lg')) && $a->has(' 1600w'));
check('cover loads eagerly, other photos lazily, all with width/height', $a->has('fetchpriority="high"') && $a->has('loading="lazy"') && preg_match('/<img [^>]*width="\d+" height="\d+"/', $a->body) === 1);
$a->get('/owner/properties');
check('My Properties shows the cover thumbnail', $a->has(media_url($cov, 'thumb', true)) || $a->has($cov['file_key'] . '_thumb'));

// Re-approval: adding a photo to a live listing when the setting is on
Database::run("UPDATE properties SET status = 'active', published_at = UTC_TIMESTAMP() WHERE id = ?", [$pid]);
$setting('reapprove_on_owner_edit', '1');
$a->get("/owner/properties/$pid/step/6");
$a->upload("/owner/properties/$pid/photos", ['photos[]' => $webp], [], true);
check('new photo on a live listing goes for re-approval when that setting is on', Database::value('SELECT status FROM properties WHERE id = ?', [$pid]) === 'pending');
$setting('reapprove_on_owner_edit', '0');
check('photo changes are logged', (int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE user_id = ? AND event IN ('media.photos_added', 'media.deleted', 'media.video_added')", [$ownerA]) >= 4);

// ---------------------------------------------------------------------
echo "\nUpload bigger than the server accepts\n";
// ---------------------------------------------------------------------
// A second dev server with a 1 MB post_max_size: the body arrives empty, so
// the app must say "too big", not "session expired".
$port = 8089;
$cmd = sprintf('php -d post_max_size=1M -d upload_max_filesize=1M -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!',
    $port, escapeshellarg(APP_ROOT . '/public'), escapeshellarg(APP_ROOT . '/public/index.php'));
$srv = (int) shell_exec($cmd);
usleep(600000);
$small2 = new Browser("http://127.0.0.1:$port");
$small2->get('/login');
$small2->upload("/owner/properties/$pid/photos", ['photos[]' => "$fx/heavy.jpg"], [], true);
check('oversized request gets a clear "too big" answer (413)', $small2->status === 413 && str_contains(implode(' ', $small2->json()['errors'] ?? []), 'too big for the server'), $small2->status . ' ' . substr($small2->body, 0, 120));
if ($srv > 0) {
    posix_kill($srv, SIGTERM);
}

// ---------------------------------------------------------------------
$cleanup();
array_map('unlink', glob("$fx/*") ?: []);
@rmdir($fx);
echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
