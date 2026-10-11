<?php
declare(strict_types=1);

/**
 * Phase 1 self-check: run after migrations on any environment.
 *   php tests/phase1_schema_check.php
 * Read-only except for a rolled-back transaction.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '  PASS  ' : '  FAIL  '), $label, PHP_EOL;
    $fail += $ok ? 0 : 1;
};

$expected = ['users', 'user_roles', 'user_sessions', 'password_resets', 'otp_codes', 'rate_limits', 'login_activity',
    'property_categories', 'property_types', 'cities', 'localities', 'amenities', 'properties', 'property_locations',
    'property_media', 'property_amenities', 'property_pg_services', 'property_status_history', 'property_view_daily',
    'rental_change_history', 'property_limit_requests', 'shortlists', 'enquiries', 'contact_history', 'bookings',
    'booking_status_history', 'homepage_sliders', 'cms_pages', 'notifications', 'email_queue', 'admin_activity_logs',
    'activity_logs', 'cookie_consents', 'settings', 'schema_migrations'];
$tables = array_column(Database::all('SHOW TABLES'), array_key_first(Database::one('SHOW TABLES') ?? ['x' => 1]));
foreach ($expected as $t) {
    $check("table {$t} exists", in_array($t, $tables, true));
}

$check('3 categories (Residential, Commercial, Industrial)', (int) Database::value('SELECT COUNT(*) FROM property_categories') === 3);
$check('3 residential sub-types', (int) Database::value('SELECT COUNT(*) FROM property_types WHERE category_id = 1') === 3);
$check('11 commercial sub-types', (int) Database::value('SELECT COUNT(*) FROM property_types WHERE category_id = 2') === 11);
$check('5 industrial sub-types', (int) Database::value('SELECT COUNT(*) FROM property_types WHERE category_id = 3') === 5);
$check('PG allowed only on Flat and Bungalow', Database::value('SELECT GROUP_CONCAT(code ORDER BY code) FROM property_types WHERE allows_pg = 1') === 'bungalow,flat');
$check('default property limit is a DB setting (3)', (int) Database::value("SELECT setting_value FROM settings WHERE setting_key = 'default_property_limit'") === 3);
$check('rent approval mode setting present', Database::value("SELECT setting_value FROM settings WHERE setting_key = 'rent_change_approval_mode'") === 'increase_only');
$check('property ids start at 10001+', (int) Database::value("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties'") >= 10001);

// Constraint behaviour, inside a transaction that is always rolled back.
$pdo = Database::pdo();
$pdo->beginTransaction();
try {
    $uid = Database::insert("INSERT INTO users (first_name, last_name, mobile, email) VALUES ('T', 'Test', '910000000099', 't99@example.invalid')");
    try {
        Database::insert("INSERT INTO users (first_name, last_name, mobile) VALUES ('T', 'Dup', '910000000099')");
        $check('duplicate mobile rejected', false);
    } catch (PDOException) {
        $check('duplicate mobile rejected', true);
    }
    try {
        Database::insert("INSERT INTO users (first_name, last_name, mobile, email) VALUES ('T', 'Dup', '910000000098', 't99@example.invalid')");
        $check('duplicate email rejected', false);
    } catch (PDOException) {
        $check('duplicate email rejected', true);
    }
    $pid = Database::insert("INSERT INTO properties (owner_id, created_by, purpose, category_id, type_id) VALUES (?, ?, 'rent', 1, 1)", [$uid, $uid]);
    Database::run('INSERT INTO shortlists (user_id, property_id) VALUES (?, ?)', [$uid, $pid]);
    try {
        Database::run('INSERT INTO shortlists (user_id, property_id) VALUES (?, ?)', [$uid, $pid]);
        $check('duplicate shortlist rejected', false);
    } catch (PDOException) {
        $check('duplicate shortlist rejected', true);
    }
    try {
        Database::run('DELETE FROM properties WHERE id = ?', [$pid]);
        $check('property with shortlists cannot be hard-deleted (history protected)', false);
    } catch (PDOException) {
        $check('property with shortlists cannot be hard-deleted (history protected)', true);
    }
    try {
        Database::run("INSERT INTO properties (owner_id, created_by, purpose, category_id, type_id) VALUES (?, ?, 'rent', 1, 9999)", [$uid, $uid]);
        $check('unknown property type rejected', false);
    } catch (PDOException) {
        $check('unknown property type rejected', true);
    }
} finally {
    $pdo->rollBack();
}

echo $fail === 0 ? "\nAll Phase 1 checks passed.\n" : "\n{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
