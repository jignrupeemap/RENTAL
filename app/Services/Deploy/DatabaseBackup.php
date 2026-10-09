<?php
declare(strict_types=1);

namespace App\Services\Deploy;

use App\Core\Config;
use PDO;

/**
 * Database backup and restore in plain PHP (spec 46), so it works on any
 * Hostinger plan from cron or SSH without mysqldump.
 *
 * Backup: one consistent InnoDB snapshot, written as a gzip'd .sql file
 * that phpMyAdmin (hPanel → Databases → phpMyAdmin → Import) can also load.
 * Each statement ends a line with ";" and row values never contain a raw
 * newline, so restore() can stream the file without loading it whole.
 *
 * Restore only runs into an EMPTY database: it never overwrites live data.
 */
final class DatabaseBackup
{
    public const HEADER = '-- Rental site database backup';
    private const ROWS_PER_INSERT = 200;

    /** @return array{tables: int, rows: int, bytes: int} */
    public static function dump(string $file): array
    {
        $pdo = self::connect();
        // Stream rows instead of buffering whole tables in PHP memory.
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        $tmp = $file . '.part';
        $gz = gzopen($tmp, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Cannot write ' . $tmp);
        }
        $w = static function (string $s) use ($gz): void {
            if (gzwrite($gz, $s) === false) {
                throw new \RuntimeException('Write failed (disk full?)');
            }
        };

        $db = (string) Config::env('DB_NAME');
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $w(self::HEADER . "\n-- Database: {$db} | Server: {$version} | Created (UTC): " . gmdate('Y-m-d H:i:s') . "\n"
            . "-- Restore into an EMPTY database: php bin/restore-db.php <file>, or phpMyAdmin → Import.\n\n"
            . "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        $rows = 0;
        foreach ($tables as $table) {
            $q = self::ident($table);
            $create = (string) $pdo->query("SHOW CREATE TABLE {$q}")->fetch(PDO::FETCH_NUM)[1];
            // AUTO_INCREMENT counters are kept so restored ids continue where they were.
            $w("-- Table {$table}\nDROP TABLE IF EXISTS {$q};\n{$create};\n");

            $cols = $pdo->query("SELECT column_name, data_type FROM information_schema.columns
                                  WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($table) . ' ORDER BY ordinal_position')->fetchAll(PDO::FETCH_NUM);
            $binary = [];
            foreach ($cols as $i => [$name, $type]) {
                $binary[$i] = (bool) preg_match('/binary|blob/i', (string) $type);
            }
            $prefix = "INSERT INTO {$q} (" . implode(', ', array_map(static fn ($c) => self::ident((string) $c[0]), $cols)) . ') VALUES ';

            $stmt = $pdo->query("SELECT * FROM {$q}");
            $batch = [];
            while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                $vals = [];
                foreach ($row as $i => $v) {
                    $vals[] = match (true) {
                        $v === null => 'NULL',
                        $binary[$i] => $v === '' ? "''" : '0x' . bin2hex((string) $v),
                        is_int($v) || is_float($v) => (string) $v,
                        default => $pdo->quote((string) $v), // escapes \n and \r, so one statement = one line
                    };
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                $rows++;
                if (count($batch) >= self::ROWS_PER_INSERT) {
                    $w($prefix . implode(',', $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                $w($prefix . implode(',', $batch) . ";\n");
            }
            $stmt->closeCursor();
            $w("\n");
        }
        $w("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- End of backup ({$rows} rows in " . count($tables) . " tables)\n");
        $pdo->exec('COMMIT');
        gzclose($gz);
        if (!rename($tmp, $file)) {
            throw new \RuntimeException('Cannot move backup into place: ' . $file);
        }
        @chmod($file, 0600);
        return ['tables' => count($tables), 'rows' => $rows, 'bytes' => (int) filesize($file)];
    }

    /**
     * Load a backup made by dump() (gzip'd or plain) into the configured
     * database, which must be empty.
     * @return array{statements: int}
     */
    public static function restore(string $file): array
    {
        $pdo = self::connect();
        $existing = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        if ($existing > 0) {
            throw new \RuntimeException("The database '" . Config::env('DB_NAME') . "' already has {$existing} tables. "
                . 'Restore only goes into an empty database: create a new one in hPanel, put its name in config/env.php, then run this again.');
        }
        $gz = gzopen($file, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('Cannot read ' . $file);
        }
        $first = (string) gzgets($gz);
        if (!str_starts_with($first, self::HEADER)) {
            gzclose($gz);
            throw new \RuntimeException('This is not a backup made by bin/backup.php. Import other SQL files with phpMyAdmin.');
        }
        $n = 0;
        $buffer = '';
        while (($line = gzgets($gz)) !== false) {
            if ($buffer === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                continue;
            }
            $buffer .= $line;
            if (str_ends_with(rtrim($line, "\r\n"), ';')) {
                $pdo->exec(substr(rtrim($buffer), 0, -1));
                $buffer = '';
                $n++;
            }
        }
        gzclose($gz);
        if (trim($buffer) !== '') {
            throw new \RuntimeException('The backup file is incomplete (it ends in the middle of a statement).');
        }
        return ['statements' => $n];
    }

    /** Delete the oldest db-*.sql.gz files beyond $keep. @return int files removed */
    public static function prune(string $dir, int $keep): int
    {
        $files = glob(rtrim($dir, '/') . '/db-*.sql.gz') ?: [];
        rsort($files, SORT_STRING); // names carry the date, newest first
        $removed = 0;
        foreach (array_slice($files, max(1, $keep)) as $old) {
            if (@unlink($old)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** A separate connection, so the snapshot and session settings do not touch the app's. */
    private static function connect(): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::env('DB_HOST', 'localhost'), (int) Config::env('DB_PORT', 3306), Config::env('DB_NAME', ''));
        $pdo = new PDO($dsn, (string) Config::env('DB_USER'), (string) Config::env('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    private static function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
