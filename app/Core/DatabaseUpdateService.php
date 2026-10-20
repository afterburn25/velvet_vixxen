<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Apply pending site migrations only after checking the existing installation identity. */
final class DatabaseUpdateService
{
    private readonly string $root;
    private readonly string $stateDirectory;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) throw new RuntimeException('The installed site directory is unavailable.');
        $this->root = $real;
        $this->stateDirectory = $real . '/storage/private/database-updates';
    }

    /** This inspection does not create a registry, change configuration, or apply SQL. */
    public function preview(): array
    {
        [$config, $fingerprints] = $this->installedConfiguration();
        if (($config['storage_mode'] ?? 'mysql') === 'file') {
            return ['mode' => 'file', 'pending' => [], 'applied' => [], 'plan' => '', 'lastRun' => null,
                'database' => '', 'server' => '', 'identityVerified' => true];
        }
        $pdo = Database::connect((array) $config['db']);
        $this->verifyDatabaseIdentity($pdo, $config);
        $this->verifyMigrationPaths();
        $runner = new MigrationRunner($pdo, $this->root . '/database/migrations');
        try {
            $applied = $pdo->query('SELECT migration_key,description,checksum,batch,executed_at FROM migrations ORDER BY migration_key')->fetchAll();
            $pending = $runner->pending(ensureRegistry: false);
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '42S02') {
                throw new RuntimeException('The installed database has no migration registry. Restore or review its migration history before applying site updates; do not reinstall.');
            }
            throw $error;
        }
        $plan = hash('sha256', json_encode([$fingerprints, array_column($applied, 'checksum', 'migration_key'),
            array_column($pending, 'checksum', 'id')], JSON_THROW_ON_ERROR));
        foreach ($pending as &$migration) $migration['file'] = basename($migration['file']);
        unset($migration);
        $lastRun = $this->readRun();
        if (($lastRun['status'] ?? '') === 'running' && !$this->isRunning()) {
            $lastRun['status'] = 'interrupted';
            $lastRun['error'] = 'The previous process stopped before recording a result. Review the database before retrying.';
        }
        return ['mode' => 'mysql', 'database' => (string) $config['db']['name'],
            'server' => (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'identityVerified' => true,
            'pending' => $pending, 'applied' => $applied, 'plan' => $plan, 'lastRun' => $lastRun];
    }

    public function apply(string $expectedPlan, bool $backupConfirmed, string $backupReference, bool $reviewedFailure = false, string $operator = 'CLI operator'): array
    {
        $preview = $this->preview();
        if ($preview['mode'] === 'file') return ['status' => 'not_needed', 'applied' => []];
        if ($expectedPlan === '' || !hash_equals($preview['plan'], $expectedPlan)) throw new RuntimeException('The pending update plan changed. Review the current list before applying.');
        if ($preview['pending'] === []) return ['status' => 'current', 'applied' => []];
        $backupReference = trim($backupReference);
        if (!$backupConfirmed || $backupReference === '' || strlen($backupReference) > 190 || str_contains($backupReference, "\0")) {
            throw new RuntimeException('Confirm your verified backup and enter its location or hosting backup reference before applying updates.');
        }
        if (in_array($preview['lastRun']['status'] ?? '', ['failed', 'interrupted'], true) && !$reviewedFailure) {
            throw new RuntimeException('Review the previous partial or interrupted update and confirm that review before retrying.');
        }
        $this->prepareStateDirectory();
        $lockPath = $this->stateDirectory . '/apply.lock';
        if (!vvx_recovery_safe_path($this->root, $lockPath, true) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new RuntimeException('The database update lock path is not safe.');
        }
        if (is_file($lockPath) && (((int) fileperms($lockPath) & 0222) === 0 || !is_writable($lockPath))) {
            throw new RuntimeException('The database update lock is not writable. Check private storage permissions.');
        }
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) throw new RuntimeException('The database update lock could not be opened. Check private storage permissions.');
        $pdo = null;
        $databaseLock = '';
        $databaseLockHeld = false;
        try {
            if (is_link($lockPath) || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('A database update is already running, or its lock is unavailable.');
            @chmod($lockPath, 0600);
            // Recompute after the lock so an upload, configuration change, or another updater cannot invalidate the reviewed plan.
            $current = $this->preview();
            if (!hash_equals($expectedPlan, $current['plan'])) throw new RuntimeException('The update plan changed before the lock was acquired. Refresh and review it.');
            [$config, $fingerprints] = $this->installedConfiguration();
            $pdo = Database::connect((array) $config['db']);
            $this->verifyDatabaseIdentity($pdo, $config);
            $databaseLock = 'vvx-update-' . substr(hash('sha256', (string) $config['db']['name'] . '|' . (string) $config['installation_id']), 0, 48);
            $getLock = $pdo->prepare('SELECT GET_LOCK(?,0)');
            $getLock->execute([$databaseLock]);
            if ((int) $getLock->fetchColumn() !== 1) throw new RuntimeException('Another process is updating this database. Try again after it finishes.');
            $databaseLockHeld = true;
            $pendingCheck = (new MigrationRunner($pdo, $this->root . '/database/migrations'))->pending(ensureRegistry: false);
            if (array_column($pendingCheck, 'checksum', 'id') !== array_column($current['pending'], 'checksum', 'id')) {
                throw new RuntimeException('The pending database updates changed. Refresh and review the current list.');
            }
            $run = ['status' => 'running', 'startedAt' => gmdate('c'), 'finishedAt' => null, 'operator' => substr($operator, 0, 120),
                'backupReference' => $backupReference, 'backupConfirmation' => 'Operator confirmed a verified backup; its contents were not inspected by this updater.',
                'plan' => $expectedPlan, 'pendingAtStart' => array_column($current['pending'], 'id'), 'applied' => [],
                'currentMigration' => null, 'error' => null, 'automaticRollback' => false];
            $this->writeRun($run);
            $logger = function (string $event, array $context) use (&$run): void {
                if ($event === 'migration.start') $run['currentMigration'] = $context['id'];
                if ($event === 'migration.complete') $run['applied'][] = $context['id'];
                if ($event === 'migration.failed') $run['error'] = (string) ($context['error'] ?? 'Migration failed.');
                $this->writeRun($run);
            };
            try {
                $run['applied'] = (new MigrationRunner($pdo, $this->root . '/database/migrations', $logger))->migrate(rollbackOnFailure: false);
                [, $afterFingerprints] = $this->installedConfiguration();
                if ($fingerprints !== $afterFingerprints) throw new RuntimeException('The active configuration or installation lock changed while updates were running. Review that change before continuing.');
                $run['status'] = 'completed';
                $run['currentMigration'] = null;
                $run['finishedAt'] = gmdate('c');
                $this->writeRun($run);
                return $run;
            } catch (Throwable $error) {
                $run['status'] = 'failed';
                $run['finishedAt'] = gmdate('c');
                $run['error'] = $error->getMessage();
                try { $this->writeRun($run); } catch (Throwable) {
                    throw new RuntimeException('The update stopped and its result could not be saved. Partial schema changes may remain; review the database and private storage permissions. No automatic rollback ran.', 0, $error);
                }
                throw new RuntimeException('The database update stopped. Partial changes may remain; review the failed migration before retrying. No automatic rollback ran. ' . $error->getMessage(), 0, $error);
            }
        } finally {
            if ($databaseLockHeld && $pdo instanceof PDO) {
                try { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$databaseLock]); } catch (Throwable) {}
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{array,array} */
    private function installedConfiguration(): array
    {
        $configPath = $this->root . '/config/app.php';
        $lockPath = $this->root . '/storage/install.lock';
        if (!vvx_recovery_safe_path($this->root, $configPath) || !vvx_recovery_safe_path($this->root, $lockPath)) {
            throw new RuntimeException('A regular active configuration and installation lock are required. Use installation recovery before database updates.');
        }
        $config = vvx_recovery_load_config($configPath);
        $lock = vvx_recovery_load_lock($lockPath);
        if (!vvx_recovery_lock_matches($config, $lock)) throw new RuntimeException('The configuration and installation lock do not match. Database updates are blocked.');
        foreach (['app_key', 'signing_key', 'media_key'] as $key) {
            if (!is_string($config['security'][$key] ?? null) || strlen($config['security'][$key]) < 32) {
                throw new RuntimeException('The installed security keys could not be verified. Database updates are blocked.');
            }
        }
        if (($config['storage_mode'] ?? 'mysql') !== 'file' && !vvx_recovery_valid_config($config)) {
            throw new RuntimeException('The installed database configuration is incomplete.');
        }
        return [$config, ['config' => hash_file('sha256', $configPath), 'lock' => hash_file('sha256', $lockPath)]];
    }

    private function verifyDatabaseIdentity(PDO $pdo, array $config): void
    {
        foreach (['SELECT state_value FROM installation_state WHERE state_key=? LIMIT 1', 'SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1'] as $query) {
            try { $statement = $pdo->prepare($query); $statement->execute(['installation']); $raw = $statement->fetchColumn(); }
            catch (PDOException $error) { if ((string) $error->getCode() === '42S02') continue; throw $error; }
            $state = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($state) || !is_string($state['installation_id'] ?? null)) continue;
            if (!hash_equals((string) $config['installation_id'], $state['installation_id'])) throw new RuntimeException('The database belongs to a different installation. Updates are blocked.');
            return;
        }
        throw new RuntimeException('The database installation identity could not be verified. Updates are blocked.');
    }

    private function verifyMigrationPaths(): void
    {
        $directory = $this->root . '/database/migrations';
        if (!vvx_recovery_safe_path($this->root, $directory) || !is_dir($directory)) throw new RuntimeException('The site migration directory is unavailable or linked.');
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            if (!vvx_recovery_safe_path($this->root, $file) || !is_file($file) || !is_readable($file)) {
                throw new RuntimeException('Every migration must be a readable regular file inside this site.');
            }
        }
    }

    private function prepareStateDirectory(): void
    {
        if (!vvx_recovery_safe_path($this->root, $this->stateDirectory, true)
            || (!is_dir($this->stateDirectory) && !@mkdir($this->stateDirectory, 0700, true) && !is_dir($this->stateDirectory))) {
            throw new RuntimeException('The private database-update directory is unavailable or linked. Check storage permissions.');
        }
        if (!is_writable($this->stateDirectory) || ((int) fileperms($this->stateDirectory) & 0222) === 0) {
            throw new RuntimeException('Private database-update storage is not writable.');
        }
    }

    private function readRun(): ?array
    {
        $path = $this->stateDirectory . '/last-run.json';
        if (!file_exists($path) && !is_link($path)) return null;
        if (!vvx_recovery_safe_path($this->root, $path) || !is_file($path)) throw new RuntimeException('The database update status path is not safe.');
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) throw new RuntimeException('The previous database update status is unreadable. Review private update storage before continuing.');
        return $data;
    }

    private function writeRun(array $run): void
    {
        $path = $this->stateDirectory . '/last-run.json';
        if (!vvx_recovery_safe_path($this->root, $path, true)
            || !vvx_recovery_atomic_write($path, json_encode($run, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0600)) {
            throw new RuntimeException('The database update status could not be saved securely.');
        }
    }

    private function isRunning(): bool
    {
        $path = $this->stateDirectory . '/apply.lock';
        if (!file_exists($path)) return false;
        if (!vvx_recovery_safe_path($this->root, $path) || !is_file($path)) throw new RuntimeException('The database update lock path is not safe.');
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('The database update lock cannot be inspected.');
        try { $available = flock($handle, LOCK_EX | LOCK_NB); if ($available) flock($handle, LOCK_UN); return !$available; }
        finally { fclose($handle); }
    }
}
