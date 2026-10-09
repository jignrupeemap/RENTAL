<?php
declare(strict_types=1);

/**
 * Phase 15 deployment rehearsal (spec 44, 46, 65). Builds the Hostinger
 * release zip, unpacks it into a fake Hostinger home folder
 * (domains/example.com/public_html + rental-app), points it at a brand-new
 * empty database in PRODUCTION mode and installs it the way a site owner
 * without SSH would: /setup → token → Run migrations → create first admin
 * → admin login. Then it checks the command-line tools (check, cron,
 * backup, restore), the one-file install.sql, and that /setup disappears
 * when SETUP_TOKEN is cleared.
 *
 * Needs a MySQL account that may create databases (TEST_MYSQL, default
 * "mysql" = the local root socket). It creates and drops its own databases
 * rental_p15_*; the development database is not touched.
 *   php tests/phase15_deployment_test.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/lib/http.php';

use App\Core\Config;
use App\Services\Deploy\InstallSql;

$mysql = getenv('TEST_MYSQL') ?: 'mysql';
$port = 8099;
$siteUrl = "http://127.0.0.1:{$port}";
$dbUser = (string) Config::env('DB_USER');
$dbPass = (string) Config::env('DB_PASSWORD');
$dbs = ['fresh' => 'rental_p15_fresh', 'import' => 'rental_p15_import', 'restore' => 'rental_p15_restore'];
$tmp = sys_get_temp_dir() . '/p15-' . bin2hex(random_bytes(4));
$home = "{$tmp}/home/domains/example.com";
$app = "{$home}/rental-app";
$web = "{$home}/public_html";
$server = null;

function sh(string $cmd, ?int &$code = null): string
{
    $out = [];
    exec($cmd . ' 2>&1', $out, $code);
    return implode("\n", $out);
}
function sql(string $db, string $q): string
{
    global $mysql;
    return trim(sh($mysql . ' -N ' . escapeshellarg($db) . ' -e ' . escapeshellarg($q)));
}
function setEnv(string $file, string $key, string $phpValue): void
{
    $s = (string) file_get_contents($file);
    $s = (string) preg_replace("/('" . preg_quote($key, '/') . "'\\s*=>\\s*)[^\\n]*,/", '${1}' . addcslashes($phpValue, '\\$') . ',', $s, 1, $n);
    if ($n !== 1) {
        throw new RuntimeException("env key {$key} not found");
    }
    file_put_contents($file, $s);
}
function schemaHash(string $db): string
{
    return md5(sql('information_schema', "SELECT table_name, column_name, column_type, is_nullable, column_default, column_key, extra FROM columns WHERE table_schema = '{$db}' ORDER BY 1, 2")
        . sql('information_schema', "SELECT table_name, index_name, column_name, seq_in_index, non_unique FROM statistics WHERE table_schema = '{$db}' ORDER BY 1, 2, 4")
        . sql('information_schema', "SELECT constraint_name, table_name, referenced_table_name, delete_rule FROM referential_constraints WHERE constraint_schema = '{$db}' ORDER BY 1"));
}
function dataHash(string $db): string
{
    $tables = array_map(static fn ($t) => "`{$t}`", explode("\n", sql($db, 'SHOW TABLES')));
    $lines = explode("\n", sql($db, 'CHECKSUM TABLE ' . implode(', ', $tables)));
    return md5(implode("\n", array_map(static fn ($l) => (string) preg_replace('/^\S+?\./', '', $l), $lines)));
}
function startServer(): void
{
    global $server, $web, $port, $siteUrl;
    $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . " -d opcache.enable=0 -S 127.0.0.1:{$port} -t " . escapeshellarg($web) . ' ' . escapeshellarg($web . '/index.php');
    $server = proc_open($cmd, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        if (@fsockopen('127.0.0.1', $port)) {
            return;
        }
    }
    throw new RuntimeException('test server did not start');
}
function cleanup(): void
{
    global $server, $dbs, $tmp, $mysql;
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach ($dbs as $db) {
        sh($mysql . ' -e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$db}`"));
    }
    sh('rm -rf ' . escapeshellarg($tmp));
}
register_shutdown_function('cleanup');

foreach ($dbs as $db) {
    sh($mysql . ' -e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$db}`; CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON `{$db}`.* TO '{$dbUser}'@'localhost'"), $code);
    if ($code !== 0) {
        fwrite(STDERR, "Cannot create test databases with '{$mysql}'. Set TEST_MYSQL to a MySQL command allowed to create databases.\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------
echo "Release zip\n";
// ---------------------------------------------------------------------
mkdir($tmp, 0755, true);
$out = sh(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/bin/package.php') . ' --out=' . escapeshellarg($tmp), $code);
$zips = glob($tmp . '/rental-site-hostinger-*.zip') ?: [];
check('bin/package.php builds the zip', $code === 0 && count($zips) === 1, $out);
$zip = new ZipArchive();
$zip->open($zips[0] ?? '');
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = (string) $zip->getNameIndex($i);
}
$top = array_unique(array_map(static fn ($n) => explode('/', $n)[0], $names));
sort($top);
check('two top folders: public_html and rental-app', $top === ['public_html', 'rental-app'], implode(',', $top));
$missing = array_diff(['public_html/index.php', 'public_html/.htaccess', 'public_html/uploads/.htaccess', 'public_html/assets/css/app.min.css',
          'rental-app/app/bootstrap.php', 'rental-app/config/env.example.php', 'rental-app/database/install.sql', 'rental-app/storage/.htaccess',
          'rental-app/bin/backup.php', 'rental-app/bin/restore-db.php', 'rental-app/bin/check.php', 'rental-app/bin/cron.php',
          'rental-app/docs/DEPLOYMENT.md', 'rental-app/docs/TESTING.md', 'rental-app/storage/sessions/.gitkeep', 'public_html/uploads/properties/.gitkeep'], $names);
check('zip contains every file a fresh install needs', $missing === [], implode(', ', $missing));
$bad = array_values(array_filter($names, static fn ($n) => preg_match('#config/env\.php$|^rental-app/tests/|seed-demo|storage/(logs|sessions|cache|tmp)/[^.]|uploads/(properties|sliders)/[^.]|/backups/|\.log$#', $n)));
check('no secrets, tests, demo seeder, logs, sessions or uploaded media', $bad === [], implode(', ', array_slice($bad, 0, 5)));
$leak = [];
foreach ($names as $n) {
    $c = (string) $zip->getFromName($n);
    foreach (array_filter([$dbPass, (string) Config::env('APP_KEY'), (string) Config::env('SETUP_TOKEN')]) as $secret) {
        if (strlen($secret) >= 8 && str_contains($c, $secret)) {
            $leak[] = $n;
        }
    }
}
check('no development password, key or token inside any file', $leak === [], implode(', ', $leak));
check('database/install.sql is up to date with the migrations',
    (string) file_get_contents(APP_ROOT . '/database/install.sql') === InstallSql::build(APP_ROOT . '/database/migrations'));

// ---------------------------------------------------------------------
echo "\nFresh install on an empty database (production mode, no SSH)\n";
// ---------------------------------------------------------------------
mkdir($home, 0755, true);
$zip->extractTo($home);
$zip->close();
$env = "{$app}/config/env.php";
copy("{$app}/config/env.example.php", $env);
$setupToken = bin2hex(random_bytes(20));
foreach ([
    'APP_ENV' => "'production'", 'APP_DEBUG' => 'false', 'APP_URL' => "'{$siteUrl}'", 'APP_KEY' => "'" . bin2hex(random_bytes(32)) . "'",
    'DB_NAME' => "'{$dbs['fresh']}'", 'DB_USER' => "'{$dbUser}'", 'DB_PASSWORD' => var_export($dbPass, true),
    'MAIL_HOST' => "'127.0.0.1'", 'MAIL_USERNAME' => "'site@example.com'", 'MAIL_PASSWORD' => "'not-used-in-test'", 'MAIL_FROM' => "'site@example.com'",
    'SETUP_TOKEN' => "'{$setupToken}'",
] as $k => $v) {
    setEnv($env, $k, $v);
}
startServer();

$v = new Browser($siteUrl);
$v->get('/');
check('before setup the home page shows the friendly error, no SQL details', $v->status === 500 && !$v->has('SQLSTATE') && !$v->has('rental_p15'));
$v->get('/health');
check('/health says not ok before setup', $v->status === 503 && $v->json() === ['ok' => false]);

$s = new Browser($siteUrl);
$s->get('/setup');
check('/setup asks for the token, not indexed or cached', $s->status === 200 && $s->has('SETUP_TOKEN') && str_contains($s->body, 'noindex') && stripos($s->headers, 'no-store') !== false);
$s->post('/setup/run');
check('migrations cannot run before the token is given', $s->status === 302 && sql($dbs['fresh'], 'SHOW TABLES') === '');
$s->get('/setup');
$s->post('/setup', ['token' => 'wrong-' . $setupToken]);
check('a wrong token is refused (empty database: limit kept in a file)', $s->status === 403 && glob("{$app}/storage/cache/setup-limit-*.json"));
$s->post('/setup', ['token' => $setupToken]);
check('the right token unlocks setup', $s->status === 302 && str_ends_with($s->location, '/setup'));
$s->get('/setup');
check('setup lists 5 migrations to apply and the readiness check', $s->has('5 migrations to apply') && $s->has('Readiness check') && $s->has('Available after the migrations have run'));
$s->post('/setup/run');
$s->get('/setup');
check('Run migrations applies them all', $s->has('Applying 2026_10_08_000001_initial_schema') && $s->has('All migrations are applied')
    && (int) sql($dbs['fresh'], 'SELECT COUNT(*) FROM schema_migrations') === 5);
check('reference data is there, but no users or properties', (int) sql($dbs['fresh'], 'SELECT COUNT(*) FROM property_types') > 0
    && sql($dbs['fresh'], 'SELECT (SELECT COUNT(*) FROM users) + (SELECT COUNT(*) FROM properties)') === '0');
check('the first admin form is offered', $s->has('Create admin'));

$admin = ['first_name' => 'Site', 'last_name' => 'Owner', 'mobile' => '98765 43210', 'email' => 'Owner@Example.com'];
$s->post('/setup/admin', $admin + ['password' => 'password123', 'password_confirmation' => 'password123']);
check('a common password is refused', $s->status === 200 && $s->has('too common'));
$s->post('/setup/admin', $admin + ['password' => 'Short1pw', 'password_confirmation' => 'Short1pw']);
check('a password under 10 characters is refused', $s->has('at least 10 characters'));
$s->post('/setup/admin', $admin + ['password' => 'Lake-Silver-9081', 'password_confirmation' => 'Lake-Silver-9082']);
check('passwords that do not match are refused', $s->has('Passwords do not match'));
$s->post('/setup/admin', ['mobile' => '12345'] + $admin + ['password' => 'Lake-Silver-9081', 'password_confirmation' => 'Lake-Silver-9081']);
check('a bad mobile number is refused', $s->has('valid 10-digit'));
check('nothing was created by the refused attempts', sql($dbs['fresh'], 'SELECT COUNT(*) FROM users') === '0');
check('typed values are kept, passwords are not', $s->has('Owner@Example.com') && !$s->has('Lake-Silver-9081'));
$s->post('/setup/admin', $admin + ['password' => 'Lake-Silver-9081', 'password_confirmation' => 'Lake-Silver-9081']);
check('a good form creates the admin', $s->status === 302);
$s->get('/setup');
check('setup confirms and now says an admin exists', $s->has('Admin account created') && $s->has('An admin account exists') && !$s->has('Create admin'));
$row = sql($dbs['fresh'], "SELECT CONCAT_WS('|', u.mobile, u.email, u.status, LEFT(u.password_hash, 4), GROUP_CONCAT(r.role ORDER BY r.role)) FROM users u JOIN user_roles r ON r.user_id = u.id GROUP BY u.id");
check('admin saved with normalised mobile/email, hashed password and roles',
    str_starts_with($row, '919876543210|owner@example.com|active|$') && str_ends_with($row, '|viewer,owner,admin'), $row);
check('password is not stored or logged in plain text', !str_contains(sql($dbs['fresh'], 'SELECT password_hash FROM users'), 'Lake-Silver')
    && !str_contains(implode('', array_map('file_get_contents', glob("{$app}/storage/logs/*") ?: [])), 'Lake-Silver'));
$s->post('/setup/admin', ['mobile' => '9123456780', 'email' => 'second@example.com'] + $admin + ['password' => 'Lake-Silver-9081', 'password_confirmation' => 'Lake-Silver-9081']);
check('a second admin cannot be created from /setup', $s->has('already exists') && sql($dbs['fresh'], 'SELECT COUNT(*) FROM users') === '1');

$a = new Browser($siteUrl);
$a->get('/admin/login');
$a->post('/admin/login', ['identifier' => 'owner@example.com', 'password' => 'Lake-Silver-9081']);
check('the new admin can sign in', $a->status === 302 && str_ends_with($a->location, '/admin'));
$a->get('/admin');
check('admin dashboard opens with real (zero) numbers', $a->status === 200 && $a->has('Dashboard'));

$v->get('/');
check('home page works after setup', $v->status === 200);
$v->get('/health');
check('/health says ok and shows no details in production', $v->status === 200 && $v->json() === ['ok' => true]);
$v->get('/rent');
check('/rent works on the empty site', $v->status === 200);
foreach (['/sitemap.xml', '/robots.txt'] as $p) {
    $v->get($p);
    check("{$p} works", $v->status === 200);
}

// ---------------------------------------------------------------------
echo "\nCommand-line tools in the Hostinger layout\n";
// ---------------------------------------------------------------------
$php = escapeshellarg(PHP_BINARY);
$out = sh("{$php} " . escapeshellarg("{$app}/bin/check.php"), $code);
check('bin/check.php finds public_html by itself (uploads writable)', str_contains($out, 'uploads/properties   writable') && str_contains($out, 'Upload protection'), $out);
check('application folder is outside the web root', str_contains($out, 'Application folder   outside the web root'));
check('check sees the migrations and the admin', str_contains($out, 'Migrations           all applied') && str_contains($out, 'Admin accounts       1 active'));
check('check warns that SETUP_TOKEN is still set and cron has not run', str_contains($out, 'set - clear it') && str_contains($out, 'has never run'));
check('nothing must be fixed (exit code 0)', $code === 0 && str_contains($out, '0 to fix'), $out);
sh("{$php} " . escapeshellarg("{$app}/bin/cron.php"), $code);
$out = sh("{$php} " . escapeshellarg("{$app}/bin/check.php"));
check('after bin/cron.php the check says cron ran just now', $code === 0 && str_contains($out, 'last ran just now'));

$out = sh("{$php} " . escapeshellarg("{$app}/bin/create-admin.php") . ' </dev/null');
check('bin/create-admin.php refuses empty answers', str_contains($out, 'First name is required'));

// ---------------------------------------------------------------------
echo "\nBackup and restore\n";
// ---------------------------------------------------------------------
$out = sh("{$php} " . escapeshellarg("{$app}/bin/backup.php") . ' --files', $code);
$dbFiles = glob("{$app}/backups/db-*.sql.gz") ?: [];
$fileZips = glob("{$app}/backups/files-*.zip") ?: [];
check('bin/backup.php --files writes a database dump and a files zip', $code === 0 && count($dbFiles) === 1 && count($fileZips) === 1, $out);
check('backups are private: folder blocked from the web, files 0600', is_file("{$app}/backups/.htaccess")
    && substr(sprintf('%o', fileperms($dbFiles[0] ?? '/')), -3) === '600');
$fz = new ZipArchive();
$fz->open($fileZips[0] ?? '');
check('the files zip carries config/env.php', $fz->locateName('config/env.php') !== false);
$fz->close();
$dump = (string) gzdecode((string) file_get_contents($dbFiles[0] ?? '/dev/null'));
check('the dump has every table and the admin row', substr_count($dump, 'CREATE TABLE') === (int) sql($dbs['fresh'], "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$dbs['fresh']}'")
    && str_contains($dump, 'owner@example.com'));

setEnv($env, 'DB_NAME', "'{$dbs['restore']}'");
$out = sh("{$php} " . escapeshellarg("{$app}/bin/restore-db.php") . ' ' . escapeshellarg($dbFiles[0] ?? ''), $code);
check('bin/restore-db.php restores into an empty database', $code === 0 && str_contains($out, 'Restored'), $out);
check('restored data is identical (CHECKSUM TABLE on every table)', dataHash($dbs['fresh']) === dataHash($dbs['restore']));
check('restored schema is identical', schemaHash($dbs['fresh']) === schemaHash($dbs['restore']));
$out = sh("{$php} " . escapeshellarg("{$app}/bin/restore-db.php") . ' ' . escapeshellarg($dbFiles[0] ?? ''), $code);
check('restore refuses a database that already has tables', $code !== 0 && str_contains($out, 'already has'), $out);
file_put_contents("{$tmp}/other.sql", "CREATE TABLE x (id INT);\n");
setEnv($env, 'DB_NAME', "'{$dbs['import']}'");
$out = sh("{$php} " . escapeshellarg("{$app}/bin/restore-db.php") . ' ' . escapeshellarg("{$tmp}/other.sql"), $code);
check('restore refuses files it did not make', $code !== 0 && str_contains($out, 'not a backup made by') && sql($dbs['import'], 'SHOW TABLES') === '');
setEnv($env, 'DB_NAME', "'{$dbs['fresh']}'");
foreach ([1, 2] as $i) {
    sleep(1); // file names carry the second
    sh("{$php} " . escapeshellarg("{$app}/bin/backup.php") . ' --keep=2');
}
check('--keep removes older database backups', count(glob("{$app}/backups/db-*.sql.gz") ?: []) === 2);

// ---------------------------------------------------------------------
echo "\nOne-file import (phpMyAdmin route)\n";
// ---------------------------------------------------------------------
sh($mysql . ' ' . escapeshellarg($dbs['import']) . ' < ' . escapeshellarg("{$app}/database/install.sql"), $code);
check('install.sql imports into an empty database', $code === 0);
check('it gives exactly the schema the migrations give', schemaHash($dbs['import']) === schemaHash($dbs['fresh']));
check('same reference data (types, amenities, cities, settings, pages)', sql($dbs['import'], 'SELECT CONCAT_WS(",", (SELECT COUNT(*) FROM property_types), (SELECT COUNT(*) FROM amenities), (SELECT COUNT(*) FROM cities), (SELECT COUNT(*) FROM settings), (SELECT COUNT(*) FROM cms_pages))')
    === sql($dbs['fresh'], 'SELECT CONCAT_WS(",", (SELECT COUNT(*) FROM property_types), (SELECT COUNT(*) FROM amenities), (SELECT COUNT(*) FROM cities), (SELECT COUNT(*) FROM settings), (SELECT COUNT(*) FROM cms_pages))'));
setEnv($env, 'DB_NAME', "'{$dbs['import']}'");
$out = sh("{$php} " . escapeshellarg("{$app}/bin/migrate.php"));
check('after the import bin/migrate.php has nothing to do', str_contains($out, 'Nothing to migrate') && !str_contains($out, 'WARNING'), $out);
setEnv($env, 'DB_NAME', "'{$dbs['fresh']}'");

// ---------------------------------------------------------------------
echo "\nClosing setup\n";
// ---------------------------------------------------------------------
setEnv($env, 'SETUP_TOKEN', "'" . bin2hex(random_bytes(20)) . "'");
$s->get('/setup');
check('changing SETUP_TOKEN locks setup for browsers already unlocked', $s->status === 200 && $s->has('Paste the') && !$s->has('Readiness check'));
setEnv($env, 'SETUP_TOKEN', "''");
$s->get('/setup');
$st = $s->status;
$s->get('/setup/migrate');
check('with SETUP_TOKEN empty every /setup address is gone (404)', $st === 404 && $s->status === 404);
$s->post('/setup/admin', ['_token' => 'x'] + $admin);
check('even POSTs to /setup are refused', in_array($s->status, [404, 419], true));
$a->get('/admin');
check('the site keeps working after setup is closed', $a->status === 200);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
