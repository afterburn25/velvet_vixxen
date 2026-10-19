<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    /** @var callable(string,array):void|null */
    private $logger;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationPath,
        ?callable $logger = null,
    ) {
        $this->logger = $logger;
    }

    public function ensureRegistry(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS migrations (
                migration_key VARCHAR(190) PRIMARY KEY,
                description VARCHAR(255) NOT NULL DEFAULT '',
                checksum CHAR(64) NOT NULL,
                batch INT UNSIGNED NOT NULL,
                executed_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return list<array{id:string,description:string,file:string,checksum:string}> */
    public function pending(): array
    {
        $this->ensureRegistry();
        $applied = [];
        foreach ($this->pdo->query('SELECT migration_key, checksum FROM migrations')->fetchAll() as $row) {
            $applied[(string) $row['migration_key']] = (string) $row['checksum'];
        }

        $pending = [];
        foreach ($this->discover() as $migration) {
            if (isset($applied[$migration['id']])) {
                if (!hash_equals($applied[$migration['id']], $migration['checksum'])) {
                    throw new RuntimeException('Migration checksum mismatch for ' . $migration['id'] . '. The installed migration file has been changed.');
                }
                continue;
            }
            $pending[] = [
                'id' => $migration['id'],
                'description' => $migration['description'],
                'file' => $migration['file'],
                'checksum' => $migration['checksum'],
            ];
        }

        return $pending;
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $this->ensureRegistry();
        $batch = (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')->fetchColumn();
        $appliedThisRun = [];

        try {
            foreach ($this->discover() as $migration) {
                $stmt = $this->pdo->prepare('SELECT checksum FROM migrations WHERE migration_key = ? LIMIT 1');
                $stmt->execute([$migration['id']]);
                $existingChecksum = $stmt->fetchColumn();
                if ($existingChecksum !== false) {
                    if (!hash_equals((string) $existingChecksum, $migration['checksum'])) {
                        throw new RuntimeException('Migration checksum mismatch for ' . $migration['id'] . '.');
                    }
                    continue;
                }

                $this->log('migration.start', ['id' => $migration['id'], 'description' => $migration['description']]);
                $executed = [];
                try {
                    foreach ($migration['up'] as $sql) {
                        $sql = trim((string) $sql);
                        if ($sql === '') {
                            continue;
                        }
                        $this->pdo->exec($sql);
                        $executed[] = $sql;
                    }
                    $insert = $this->pdo->prepare('INSERT INTO migrations (migration_key, description, checksum, batch, executed_at) VALUES (?, ?, ?, ?, NOW())');
                    $insert->execute([$migration['id'], $migration['description'], $migration['checksum'], $batch]);
                    $appliedThisRun[] = $migration['id'];
                    $this->log('migration.complete', ['id' => $migration['id'], 'statements' => count($executed)]);
                } catch (Throwable $e) {
                    $this->log('migration.failed', ['id' => $migration['id'], 'error' => $e->getMessage()]);
                    $this->runDown($migration);
                    throw $e;
                }
            }
        } catch (Throwable $e) {
            $this->rollbackKeys(array_reverse($appliedThisRun));
            throw new RuntimeException('Database migration failed: ' . $e->getMessage(), 0, $e);
        }

        return $appliedThisRun;
    }

    /** @param list<string> $keys */
    public function rollbackKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }
        $all = [];
        foreach ($this->discover() as $migration) {
            $all[$migration['id']] = $migration;
        }

        foreach ($keys as $key) {
            if (!isset($all[$key])) {
                continue;
            }
            $this->runDown($all[$key]);
            $delete = $this->pdo->prepare('DELETE FROM migrations WHERE migration_key = ?');
            $delete->execute([$key]);
            $this->log('migration.rolled_back', ['id' => $key]);
        }
    }

    /** @return list<array{id:string,description:string,up:list<string>,down:list<string>,file:string,checksum:string}> */
    private function discover(): array
    {
        if (!is_dir($this->migrationPath)) {
            throw new RuntimeException('Migration directory is missing: ' . $this->migrationPath);
        }

        $files = glob(rtrim($this->migrationPath, '/\\') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $migrations = [];
        $ids = [];

        foreach ($files as $file) {
            $definition = require $file;
            if (!is_array($definition)) {
                throw new RuntimeException('Invalid migration definition: ' . basename($file));
            }
            $id = trim((string) ($definition['id'] ?? ''));
            if ($id === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $id)) {
                throw new RuntimeException('Migration has an invalid id: ' . basename($file));
            }
            if (isset($ids[$id])) {
                throw new RuntimeException('Duplicate migration id: ' . $id);
            }
            $ids[$id] = true;
            $up = array_values(array_filter((array) ($definition['up'] ?? []), 'is_string'));
            $down = array_values(array_filter((array) ($definition['down'] ?? []), 'is_string'));
            if ($up === []) {
                throw new RuntimeException('Migration contains no up statements: ' . $id);
            }
            $checksum = hash_file('sha256', $file);
            if (!is_string($checksum)) {
                throw new RuntimeException('Could not checksum migration: ' . basename($file));
            }
            $migrations[] = [
                'id' => $id,
                'description' => trim((string) ($definition['description'] ?? '')),
                'up' => $up,
                'down' => $down,
                'file' => $file,
                'checksum' => $checksum,
            ];
        }

        if ($migrations === []) {
            throw new RuntimeException('No database migrations were found.');
        }

        return $migrations;
    }

    /** @param array{id:string,down:list<string>} $migration */
    private function runDown(array $migration): void
    {
        foreach ($migration['down'] as $sql) {
            try {
                $this->pdo->exec((string) $sql);
            } catch (Throwable $rollbackError) {
                $this->log('migration.rollback_warning', [
                    'id' => $migration['id'],
                    'error' => $rollbackError->getMessage(),
                ]);
            }
        }
    }

    private function log(string $event, array $context = []): void
    {
        if (is_callable($this->logger)) {
            ($this->logger)($event, $context);
        }
    }
}
