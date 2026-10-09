<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Applies database/migrations/*.sql in filename order, once each.
 * Never drops or resets data: a migration that was applied is skipped,
 * and changes to the schema always go in a NEW migration file.
 */
final class Migrator
{
    public function __construct(private readonly string $dir)
    {
    }

    /** @return list<string> versions applied in this run */
    public function migrate(?callable $log = null): array
    {
        $log ??= static fn (string $m) => null;
        $pdo = Database::pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version     VARCHAR(100) NOT NULL PRIMARY KEY,
                checksum    CHAR(64) NOT NULL,
                applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $applied = array_column(Database::all('SELECT version, checksum FROM schema_migrations'), 'checksum', 'version');
        $ran = [];

        foreach ($this->pending() as $file) {
            $version = basename($file, '.sql');
            $sql = (string) file_get_contents($file);
            if (isset($applied[$version])) {
                if ($applied[$version] !== hash('sha256', $sql)) {
                    $log("WARNING: {$version} was edited after it was applied. Put changes in a new migration.");
                }
                continue;
            }
            $log("Applying {$version} ...");
            // DDL auto-commits in MySQL, so each statement runs on its own.
            foreach ($this->splitStatements($sql) as $statement) {
                $pdo->exec($statement);
            }
            Database::run('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)', [$version, hash('sha256', $sql)]);
            $ran[] = $version;
            $log("  done.");
        }

        if (!$ran) {
            $log('Nothing to migrate; database is up to date.');
        }
        return $ran;
    }

    /** @return list<string> */
    private function pending(): array
    {
        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * Splits on semicolons that end a line, ignoring ones inside quotes and
     * `--` comments. Good enough for our hand-written migrations (no
     * stored procedures / DELIMITER blocks).
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($quote === null && $ch === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $buffer .= "\n";
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                if ($quote === null) {
                    $quote = $ch;
                } elseif ($quote === $ch && ($sql[$i - 1] ?? '') !== '\\') {
                    $quote = null;
                }
            }
            if ($ch === ';' && $quote === null) {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return $statements;
    }
}
