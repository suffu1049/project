<?php

declare(strict_types=1);

namespace App\Core;

use PDOException;
use RuntimeException;

/**
 * Migration runner.
 *
 * Reads ordered `*.sql` files from database/migrations and applies the ones not
 * yet recorded in the `migrations` table. Each file contains a single statement.
 *
 * Only the migration runner performs DDL. Application repositories use
 * prepared statements and never issue schema changes.
 */
final class Migrator
{
    /** Tables in reverse dependency order (safe to drop children first). */
    private const DROP_ORDER = [
        'emergency_attachments',
        'request_events',
        'notifications',
        'audit_logs',
        'matches',
        'resources',
        'organizations',
        'volunteer_profiles',
        'emergency_requests',
        'users',
        'migrations',
    ];

    public function __construct(
        private readonly string $path,
        private readonly ?\Closure $output = null,
    ) {
    }

    /**
     * Create the migration tracking table if it does not exist.
     */
    public function ensureMigrationsTable(): void
    {
        Database::execute(
            'CREATE TABLE IF NOT EXISTS migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT UNSIGNED NOT NULL DEFAULT 1,
                executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Files available on disk, ordered by filename.
     *
     * @return array<int, string>
     */
    public function files(): array
    {
        $files = glob(rtrim($this->path, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        return array_map('basename', $files);
    }

    /**
     * Already-applied migration names.
     *
     * @return array<string, int> migration => batch
     */
    public function applied(): array
    {
        $this->ensureMigrationsTable();
        $rows = Database::select('SELECT migration, batch FROM migrations ORDER BY migration');
        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['migration']] = (int) $row['batch'];
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->files(),
            static fn (string $file): bool => !array_key_exists($file, $applied)
        ));
    }

    /**
     * Apply all pending migrations. Returns the names that ran.
     *
     * @return array<int, string>
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->applied();
        $batch = ($applied === [] ? 0 : max($applied)) + 1;
        $ran = [];

        foreach ($this->files() as $file) {
            if (array_key_exists($file, $applied)) {
                continue;
            }

            $sql = trim((string) file_get_contents(rtrim($this->path, '/\\') . '/' . $file));

            if ($sql === '') {
                throw new RuntimeException("Migration {$file} is empty.");
            }

            try {
                Database::pdo()->exec($sql);
            } catch (PDOException $exception) {
                throw new RuntimeException("Migration {$file} failed: " . $exception->getMessage(), 0, $exception);
            }

            Database::execute(
                'INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)',
                ['migration' => $file, 'batch' => $batch]
            );

            $this->say("  migrated: {$file}");
            $ran[] = $file;
        }

        return $ran;
    }

    /**
     * Drop every application table (and the migrations table). DESTRUCTIVE.
     */
    public function fresh(): void
    {
        $pdo = Database::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::DROP_ORDER as $table) {
            // Table names are from a fixed internal whitelist, not user input.
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            $this->say("  dropped: {$table}");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Existing tables in the current schema.
     *
     * @return array<int, string>
     */
    public function existingTables(): array
    {
        $rows = Database::select('SHOW TABLES');
        $tables = [];

        foreach ($rows as $row) {
            $tables[] = (string) array_values($row)[0];
        }

        sort($tables);

        return $tables;
    }

    private function say(string $message): void
    {
        if ($this->output !== null) {
            ($this->output)($message);
        }
    }
}
