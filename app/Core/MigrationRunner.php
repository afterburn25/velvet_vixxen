<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private const REGISTRY_SQL = "CREATE TABLE IF NOT EXISTS migrations (
        migration_key VARCHAR(190) PRIMARY KEY,
        description VARCHAR(255) NOT NULL DEFAULT '',
        checksum CHAR(64) NOT NULL,
        batch INT UNSIGNED NOT NULL,
        executed_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

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
        $this->pdo->exec(self::REGISTRY_SQL);
    }

    /** Inspect a reused database before authentication or configuration writes. */
    public function validateForResume(): void
    {
        $this->resumeSchema($this->discover());
    }

    /** @return list<array{id:string,description:string,file:string,checksum:string}> */
    public function pending(bool $ensureRegistry = true): array
    {
        if ($ensureRegistry) $this->ensureRegistry();
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
    public function migrate(bool $rollbackOnFailure = true, bool $resumeExisting = false): array
    {
        if ($resumeExisting) return $this->resume();
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
                    if ($rollbackOnFailure) $this->runDown($migration);
                    throw $e;
                }
            }
        } catch (Throwable $e) {
            // DDL can commit immediately. An installed site must preserve existing data and
            // completed migrations for review instead of running potentially destructive down SQL.
            if ($rollbackOnFailure) $this->rollbackKeys(array_reverse($appliedThisRun));
            throw new RuntimeException('Database migration failed: ' . $e->getMessage(), 0, $e);
        }

        return $appliedThisRun;
    }

    /** Complete only missing supported schema objects; never run down SQL. */
    private function resume(): array
    {
        $migrations = $this->discover();
        $schema = $this->resumeSchema($migrations);
        $this->ensureRegistry();
        $batch = (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')->fetchColumn();
        $applied = [];
        try {
            foreach ($migrations as $migration) {
                $query = $this->pdo->prepare('SELECT checksum FROM migrations WHERE migration_key=? LIMIT 1');
                $query->execute([$migration['id']]);
                $existing = $query->fetchColumn();
                if ($existing !== false && !hash_equals((string) $existing, $migration['checksum'])) {
                    throw new RuntimeException('Migration checksum mismatch for ' . $migration['id'] . '.');
                }
                $this->log('migration.start', ['id' => $migration['id'], 'description' => $migration['description'], 'resuming' => true]);
                foreach ($migration['up'] as $sql) {
                    if (trim($sql) !== '') $schema->execute($sql);
                }
                // A pre-existing ledger row is retained exactly, including its
                // original batch, description and execution time.
                if ($existing === false) {
                    $insert = $this->pdo->prepare('INSERT INTO migrations (migration_key,description,checksum,batch,executed_at) VALUES (?,?,?,?,NOW())');
                    $insert->execute([$migration['id'], $migration['description'], $migration['checksum'], $batch]);
                    $applied[] = $migration['id'];
                }
                $this->log('migration.complete', ['id' => $migration['id'], 'resuming' => true, 'recorded' => $existing === false]);
            }
            $schema->validate();
        } catch (Throwable $error) {
            $this->log('migration.failed', ['id' => $migration['id'] ?? 'schema_verification', 'error' => $error->getMessage(), 'resuming' => true]);
            throw new RuntimeException('Database continuation stopped. Existing records and completed migration steps were preserved; no automatic rollback ran. ' . $error->getMessage(), 0, $error);
        }
        return $applied;
    }

    private function resumeSchema(array $migrations): MigrationResumeSchema
    {
        // Some operational/test callers load MigrationRunner without an autoloader.
        require_once __DIR__ . '/MigrationResumeSchema.php';
        $schema = new MigrationResumeSchema($this->pdo, $migrations, self::REGISTRY_SQL);
        $schema->validate();
        if ($schema->hasTable('migrations')) {
            $known = array_column($migrations, 'checksum', 'id');
            foreach ($this->pdo->query('SELECT migration_key,checksum FROM migrations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = (string) $row['migration_key'];
                if (!isset($known[$key])) throw new RuntimeException('The database records an unsupported migration (' . $key . '). Use its matching or newer application source before continuing. Existing records were preserved.');
                if (!hash_equals($known[$key], (string) $row['checksum'])) throw new RuntimeException('Migration checksum mismatch for ' . $key . '. Restore the matching original migration file before continuing. Existing records were preserved.');
            }
        }
        return $schema;
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
