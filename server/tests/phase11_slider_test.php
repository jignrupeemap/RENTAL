<?php
declare(strict_types=1);

/**
 * Phase 11 end-to-end tests: homepage hero slider and its admin
 * management (add, image checks, edit, replace image, order, activate /
 * deactivate, schedule, delete), plus how the homepage renders it.
 * DEVELOPMENT ONLY (creates and removes @test.invalid accounts and slides
 * whose titles start with "P11T"). Slides that already exist are left alone
 * but hidden during the run and restored afterwards.
 *
 *   php -S 127.0.0.1:8088 -t public public/index.php &
 *   php tests/phase11_slider_test.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

if (Config::isProduction() || Config::env('MAIL_DRIVER') !== 'log') {
    fwrite(STDERR, "Run only in development with MAIL_DRIVER=log.\n");
    exit(1);
}
require __DIR__ . '/lib/http.php';

$files = static function (array $s): array {
    return glob(PUBLIC_ROOT . '/uploads/' . $s['image_dir'] . '/' . $s['image_key'] . '_*') ?: [];
};
$cleanup = static function () use ($files): void {
    foreach (Database::all("SELECT * FROM homepage_sliders WHERE title LIKE 'P11T%' OR created_by IN (SELECT id FROM users WHERE email LIKE 'p11-%@test.invalid')") as $s) {
        foreach ($files($s) as $f) {
            @unlink($f);
        }
        Database::run('DELETE FROM homepage_sliders WHERE id = ?', [$s['id']]);
    }
    $ids = array_map('intval', array_column(Database::all("SELECT id FROM users WHERE email LIKE 'p11-%@test.invalid'"), 'id')) ?: [0];
    $in = implode(',', $ids);
    foreach ([
        "DELETE FROM notifications WHERE user_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in))",
        "DELETE FROM admin_activity_logs WHERE admin_id IN ($in)",
        "DELETE FROM activity_logs WHERE user_id IN ($in)",
        "DELETE FROM login_activity WHERE user_id IN ($in)",
        "DELETE FROM user_sessions WHERE user_id IN ($in)",
        "DELETE FROM user_roles WHERE user_id IN ($in)",
        "DELETE FROM users WHERE id IN ($in)",
        'DELETE FROM rate_limits',
    ] as $sql) {
        Database::run($sql);
    }
};
$cleanup();
// Hide any real slides during the run; restore them at the end.
$existing = array_column(Database::all('SELECT id, is_active FROM homepage_sliders'), 'is_active', 'id');
Database::run('UPDATE homepage_sliders SET is_active = 0');
Database::run("UPDATE settings SET setting_value = '0' WHERE setting_key = 'auth_otp_enabled'");

$pw = 'Secret123';
function registerUser(Browser $b, string $first, string $mobile, string $email): int
{
    global $pw;
    $b->get('/register');
    $b->post('/register', ['first_name' => $first, 'last_name' => 'Test', 'mobile' => $mobile, 'email' => $email,
        'password' => $pw, 'password_confirmation' => $pw, 'terms' => '1', 'website' => '']);
    return (int) Database::value('SELECT id FROM users WHERE email = ?', [$email]);
}
function slide(string $title): ?array { return Database::one('SELECT * FROM homepage_sliders WHERE title = ?', [$title]); }
function logged(string $action, int $id): bool { return (bool) Database::value('SELECT 1 FROM admin_activity_logs WHERE action = ? AND entity_id = ?', [$action, $id]); }
/** Multipart POST of the slide form (upload() also sends the CSRF token). */
function postSlide(Browser $b, string $path, array $data, ?string $image): Browser
{
    return $image !== null ? $b->upload($path, ['image' => $image], $data) : $b->post($path, $data);
}

$tmp = sys_get_temp_dir() . '/rw-p11-' . getmypid();
@mkdir($tmp);
$wide = testImage("$tmp/wide.jpg", 1800, 750);
$wide2 = testImage("$tmp/wide2.png", 1400, 600, 'png');
$wide3 = testImage("$tmp/wide3.webp", 1500, 640, 'webp');
$small = testImage("$tmp/small.jpg", 900, 400);
$tall = testImage("$tmp/tall.jpg", 1300, 1800);
file_put_contents("$tmp/fake.jpg", "<?php echo 'x'; ?>");

// ---------------------------------------------------------------------
echo "Setup and access\n";
// ---------------------------------------------------------------------
$adminId = registerUser(new Browser($base), 'Asha', '9222201101', 'p11-admin@test.invalid');
Database::run("INSERT INTO user_roles (user_id, role) VALUES (?, 'admin')", [$adminId]);
$u = new Browser($base);
$userId = registerUser($u, 'Uday', '9222201102', 'p11-user@test.invalid');
$adm = new Browser($base);
$adm->get('/admin/login');
$adm->post('/admin/login', ['identifier' => 'p11-admin@test.invalid', 'password' => $pw]);
check('admin signed in', redirectsTo($adm, '/admin'));
$g = new Browser($base);
$g->get('/admin/sliders');
check('guest cannot open slider admin', $g->status === 302 && str_contains($g->location, '/admin/login'));
$u->get('/admin/sliders');
check('site user cannot open slider admin', $u->status === 302 && str_contains($u->location, '/admin/login'));
$adm->get('/admin');
check('admin menu has Homepage Sliders', $adm->has('Homepage Sliders') && str_contains($adm->body, $base . '/admin/sliders"'));
$adm->get('/admin/sliders');
check('empty list offers + Add Slider', $adm->status === 200 && $adm->has('+ Add Slider') && $adm->has('No slides yet'));

$g->get('/');
check('homepage without slides: plain heading, search card, no carousel', $g->status === 200 && !str_contains($g->body, 'id="heroSlider"')
    && $g->has('Find your next home') && $g->has('SEARCH PROPERTY'));

// ---------------------------------------------------------------------
echo "\nAdd: validation\n";
// ---------------------------------------------------------------------
$adm->get('/admin/sliders/new');
check('add form opens with upload field', $adm->status === 200 && str_contains($adm->body, 'enctype="multipart/form-data"') && str_contains($adm->body, 'name="image"'));
$bad = [
    'no image'              => [['title' => 'P11T None'], null, 'Please choose an image'],
    'too narrow'            => [['title' => 'P11T Small'], $small, 'at least 1200 pixels wide'],
    'portrait'              => [['title' => 'P11T Tall'], $tall, 'landscape'],
    'not an image'          => [['title' => 'P11T Fake'], "$tmp/fake.jpg", 'Only JPG, PNG or WebP'],
    'javascript: link'      => [['title' => 'P11T JS', 'button_text' => 'Go', 'button_link' => 'javascript:alert(1)'], $wide, 'Use a page on this site'],
    'protocol-relative link' => [['title' => 'P11T PR', 'button_text' => 'Go', 'button_link' => '//evil.example/x'], $wide, 'Use a page on this site'],
    'button text, no link'  => [['title' => 'P11T Half', 'button_text' => 'Go'], $wide, 'needs both its text and its link'],
    'end before start'      => [['title' => 'P11T Dates', 'starts_at' => '2026-12-10', 'ends_at' => '2026-12-01'], $wide, 'end date must be on or after'],
    'bad date'              => [['title' => 'P11T Date2', 'starts_at' => '2026-02-31'], $wide, 'valid start date'],
    'title too long'        => [['title' => 'P11T ' . str_repeat('x', 130)], $wide, 'under 120 characters'],
];
foreach ($bad as $label => [$data, $img, $msg]) {
    $adm->get('/admin/sliders/new');
    postSlide($adm, '/admin/sliders', $data, $img);
    $ok = $adm->status === 302 && str_contains($adm->location, '/admin/sliders/new');
    $adm->follow();
    check("refused: $label", $ok && $adm->has($msg) && !Database::value("SELECT 1 FROM homepage_sliders WHERE title LIKE 'P11T%'"));
}
check('refused uploads leave no files behind', !glob(PUBLIC_ROOT . '/uploads/sliders/*/*/*') || count(array_filter(glob(PUBLIC_ROOT . '/uploads/sliders/*/*/*'), static fn ($f) => filemtime($f) >= time() - 120)) === 0);

// ---------------------------------------------------------------------
echo "\nAdd, show on the homepage\n";
// ---------------------------------------------------------------------
$adm->get('/admin/sliders/new');
postSlide($adm, '/admin/sliders', ['title' => 'P11T Monsoon <b>offer</b>', 'subtitle' => 'PGs near colleges', 'button_text' => 'Browse PGs', 'button_link' => '/pg'], $wide);
$s1 = slide('P11T Monsoon <b>offer</b>');
check('slide added and active', $adm->status === 302 && $s1 !== null && (int) $s1['is_active'] === 1 && (int) $s1['created_by'] === $adminId);
check('image resized: 480/960/1600 JPEG + WebP, no original kept', $s1 && count($files($s1)) === 6 && (int) $s1['image_width'] === 1600 && (int) $s1['image_height'] === 667
    && is_file(PUBLIC_ROOT . '/uploads/' . $s1['image_dir'] . '/' . $s1['image_key'] . '_lg.webp'));
check('stored under uploads/sliders/YYYY/MM with a random name', $s1 && preg_match('#^sliders/\d{4}/\d{2}$#', $s1['image_dir']) && preg_match('/^[a-f0-9]{32}$/', $s1['image_key']));
check('creation logged', $s1 && logged('slider.created', (int) $s1['id']));
$adm->follow();
check('list shows image, title, subtitle, button, order, Live', $adm->has('Slide added') && $adm->has('P11T Monsoon <b>offer</b>') && $adm->has('PGs near colleges')
    && $adm->has('Browse PGs') && $adm->has('Live') && str_contains($adm->body, $s1['image_key'] . '_thumb.jpg') && !str_contains($adm->body, '<b>offer</b>'));

$g->get('/');
check('homepage shows the slider above the search', str_contains($g->body, 'id="heroSlider"') && strpos($g->body, 'id="heroSlider"') < strpos($g->body, 'SEARCH PROPERTY'));
check('title escaped, subtitle and button to /pg', $g->has('P11T Monsoon <b>offer</b>') && !str_contains($g->body, '<b>offer</b>') && $g->has('PGs near colleges')
    && str_contains($g->body, 'href="' . $base . '/pg">Browse PGs</a>'));
check('first image loads at once with WebP and sizes', str_contains($g->body, 'fetchpriority="high"') && str_contains($g->body, $s1['image_key'] . '_lg.webp 1600w')
    && str_contains($g->body, 'width="1600" height="667"'));
check('one slide: no arrows, no auto-play', !str_contains($g->body, 'data-bs-slide="next"') && !str_contains($g->body, 'data-bs-ride'));
check('still one H1 on the page', substr_count($g->body, '<h1') === 1);

$adm->get('/admin/sliders/new');
postSlide($adm, '/admin/sliders', ['title' => 'P11T Shops', 'button_text' => 'See shops', 'button_link' => 'https://example.com/shops?a=1'], $wide2);
$s2 = slide('P11T Shops');
check('second slide (PNG) added at the end', $s2 !== null && (int) $s2['sort_order'] > (int) $s1['sort_order']);
$g->get('/');
check('two slides: arrows, dots and auto-play', substr_count($g->body, 'class="carousel-item') === 2 && str_contains($g->body, 'data-bs-slide="next"')
    && str_contains($g->body, 'data-bs-ride="carousel"') && str_contains($g->body, 'data-bs-slide-to="1"'));
check('second image is lazy-loaded', (bool) preg_match('/' . $s2['image_key'] . '_lg\.jpg"[^>]*loading="lazy"/', $g->body) || (bool) preg_match('/' . $s2['image_key'] . '_md\.jpg"[^>]*loading="lazy"/', $g->body));
check('external link opens safely', str_contains($g->body, 'href="https://example.com/shops?a=1" rel="noopener" target="_blank">See shops'));
check('first slide comes first', strpos($g->body, $s1['image_key']) < strpos($g->body, $s2['image_key']));

// ---------------------------------------------------------------------
echo "\nOrder, status, schedule\n";
// ---------------------------------------------------------------------
$adm->get('/admin/sliders');
$adm->post('/admin/sliders/' . $s2['id'] . '/move', ['direction' => 'up']);
$g->get('/');
check('move up: second slide now first on the homepage', strpos($g->body, $s2['image_key']) < strpos($g->body, $s1['image_key']) && logged('slider.moved', (int) $s2['id']));
$adm->get('/admin/sliders');
$adm->post('/admin/sliders/' . $s2['id'] . '/move', ['direction' => 'up']);
check('moving the first slide up changes nothing', (int) slide('P11T Shops')['sort_order'] < (int) slide('P11T Monsoon <b>offer</b>')['sort_order']);
$adm->get('/admin/sliders');
$adm->post('/admin/sliders/' . $s1['id'] . '/status', ['active' => '0']);
$g->get('/');
check('deactivate: slide leaves the homepage', (int) slide('P11T Monsoon <b>offer</b>')['is_active'] === 0 && !str_contains($g->body, $s1['image_key'])
    && str_contains($g->body, $s2['image_key']) && logged('slider.deactivated', (int) $s1['id']));
$adm->get('/admin/sliders');
check('list shows Inactive with an Activate button', $adm->has('Inactive') && $adm->has('Activate'));
$adm->post('/admin/sliders/' . $s1['id'] . '/status', ['active' => '1']);
check('activate again', (int) slide('P11T Monsoon <b>offer</b>')['is_active'] === 1 && logged('slider.activated', (int) $s1['id']));

$ist = static fn (int $d): string => gmdate('Y-m-d', time() + 19800 + $d * 86400);
$adm->get('/admin/sliders/' . $s2['id'] . '/edit');
postSlide($adm, '/admin/sliders/' . $s2['id'], ['title' => 'P11T Shops', 'button_text' => 'See shops', 'button_link' => '/lease', 'starts_at' => $ist(1), 'ends_at' => $ist(5)], null);
$g->get('/');
check('scheduled for tomorrow: not shown yet', !str_contains($g->body, $s2['image_key']) && str_contains($g->body, $s1['image_key']));
$adm->get('/admin/sliders');
check('list marks it Scheduled with its dates', $adm->has('Scheduled / ended') && $adm->has($ist(1) . ' – ' . $ist(5)));
$adm->get('/admin/sliders/' . $s2['id'] . '/edit');
check('edit form shows the same India dates back', str_contains($adm->body, 'value="' . $ist(1) . '"') && str_contains($adm->body, 'value="' . $ist(5) . '"'));
postSlide($adm, '/admin/sliders/' . $s2['id'], ['title' => 'P11T Shops', 'button_text' => 'See shops', 'button_link' => '/lease', 'starts_at' => $ist(0), 'ends_at' => $ist(0)], null);
$g->get('/');
check('a one-day window that includes today shows it', str_contains($g->body, $s2['image_key']));
postSlide($adm, '/admin/sliders/' . $s2['id'], ['title' => 'P11T Shops', 'button_text' => 'See shops', 'button_link' => '/lease', 'starts_at' => $ist(-5), 'ends_at' => $ist(-1)], null);
$g->get('/');
check('ended window hides it', !str_contains($g->body, $s2['image_key']));

// ---------------------------------------------------------------------
echo "\nEdit and replace image\n";
// ---------------------------------------------------------------------
$oldFiles = $files($s1);
$adm->get('/admin/sliders/' . $s1['id'] . '/edit');
check('edit form shows the current image and values', $adm->status === 200 && str_contains($adm->body, $s1['image_key'] . '_md.jpg') && str_contains($adm->body, 'value="PGs near colleges"'));
postSlide($adm, '/admin/sliders/' . $s1['id'], ['title' => 'P11T Monsoon <b>offer</b>', 'subtitle' => 'Now with food', 'button_text' => '', 'button_link' => ''], $wide3);
$e1 = slide('P11T Monsoon <b>offer</b>');
check('saved: new subtitle, button removed, new image', $e1['subtitle'] === 'Now with food' && $e1['button_text'] === null && $e1['image_key'] !== $s1['image_key'] && count($files($e1)) === 6);
clearstatcache();
check('old image files deleted', count(array_filter($oldFiles, 'is_file')) === 0);
check('edit logged with before and after', (bool) Database::value("SELECT 1 FROM admin_activity_logs WHERE action = 'slider.updated' AND entity_id = ? AND old_values LIKE '%PGs near colleges%' AND new_values LIKE '%Now with food%'", [$s1['id']]));
$g->get('/');
check('slide without a button shows no button', $g->has('Now with food') && !$g->has('Browse PGs'));
$adm->get('/admin/sliders/' . $s1['id'] . '/edit');
postSlide($adm, '/admin/sliders/' . $s1['id'], ['title' => 'P11T Monsoon <b>offer</b>'], $small);
$adm->follow();
check('bad replacement image refused, current image kept', $adm->has('at least 1200 pixels wide') && slide('P11T Monsoon <b>offer</b>')['image_key'] === $e1['image_key'] && count($files($e1)) === 6);

// ---------------------------------------------------------------------
echo "\nSafety and delete\n";
// ---------------------------------------------------------------------
$adm->post('/admin/sliders/' . $s1['id'] . '/delete', [], false);
check('missing CSRF token refused', $adm->status === 419 && slide('P11T Monsoon <b>offer</b>') !== null);
$u->get('/account');
$u->post('/admin/sliders/' . $s1['id'] . '/delete');
check('site user cannot delete', slide('P11T Monsoon <b>offer</b>') !== null);
$adm->get('/admin/sliders/999999/edit');
check('unknown slide → 404', $adm->status === 404);
$adm->get('/admin/sliders');
check('delete asks for confirmation first', str_contains($adm->body, 'data-confirm="Delete this slide'));
$keepFiles = $files($e1);
$adm->post('/admin/sliders/' . $s1['id'] . '/delete');
clearstatcache();
check('deleted: row and image files gone, logged', slide('P11T Monsoon <b>offer</b>') === null && count(array_filter($keepFiles, 'is_file')) === 0 && logged('slider.deleted', (int) $s1['id']));
$adm->get('/admin/sliders');
$adm->post('/admin/sliders/' . $s2['id'] . '/delete');
$g->get('/');
check('no live slides: homepage falls back to the plain heading', !str_contains($g->body, 'id="heroSlider"') && $g->has('Find your next home'));

$cleanup();
foreach ($existing as $id => $active) {
    Database::run('UPDATE homepage_sliders SET is_active = ? WHERE id = ?', [$active, $id]);
}
array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
check('test data removed', !Database::value("SELECT 1 FROM homepage_sliders WHERE title LIKE 'P11T%'"));
echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
