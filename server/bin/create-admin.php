<?php
declare(strict_types=1);

/**
 * Create the first admin (or grant admin to an existing account) without
 * hard-coding any credentials:
 *   php bin/create-admin.php
 * You are prompted for name, mobile, email and password; nothing is echoed
 * or logged.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\Deploy\AdminBootstrap;

function ask(string $label, bool $hidden = false): string
{
    echo $label, ': ';
    if ($hidden && DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
        $value = trim((string) fgets(STDIN));
        system('stty echo');
        echo PHP_EOL;
        return $value;
    }
    return trim((string) fgets(STDIN));
}

$in = [
    'first_name' => ask('First name'),
    'last_name'  => ask('Last name'),
    'mobile'     => ask('Mobile (10 digits)'),
    'email'      => ask('Email'),
];
$found = Database::one('SELECT id FROM users WHERE mobile = ? OR email = ?', [(string) normalize_mobile($in['mobile']), strtolower($in['email'])]);
if ($found === null) {
    $in['password'] = ask('Password (min ' . AdminBootstrap::MIN_PASSWORD . ' chars, a letter and a number)', true);
    $in['password_confirmation'] = ask('Repeat password', true);
}

$result = AdminBootstrap::create($in, true);
unset($in);
if ($result['errors']) {
    fwrite(STDERR, implode(PHP_EOL, $result['errors']) . PHP_EOL);
    exit(1);
}
echo $result['existing'] ? "Account exists (id {$result['user_id']}); granted admin role.\n" : "Admin created (id {$result['user_id']}).\n";
echo "Sign in at /admin/login.\n";
