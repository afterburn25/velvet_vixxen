<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

trait AddonStoreTrait
{
    /** @return list<array<string,mixed>> */
    public function addons(): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['addons'] ?? []));
            usort($rows, static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
            return $rows;
        }
        $rows = $this->pdo()->query('SELECT * FROM addons ORDER BY name, addon_key')->fetchAll();
        return array_map([$this, 'mapAddonRow'], $rows);
    }

    public function addon(string $addonKey): ?array
    {
        $addonKey = strtolower(trim($addonKey));
        if ($addonKey === '') {
            return null;
        }
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $row = $data['addons'][$addonKey] ?? null;
            return is_array($row) ? $row : null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM addons WHERE addon_key = ? LIMIT 1');
        $stmt->execute([$addonKey]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->mapAddonRow($row) : null;
    }

    /** @param array<string,mixed> $addon @return array<string,mixed> */
    public function saveAddon(array $addon): array
    {
        $manifest = (array) ($addon['manifest'] ?? []);
        $key = strtolower(trim((string) ($addon['id'] ?? $addon['addonKey'] ?? $manifest['id'] ?? '')));
        if ($key === '') {
            throw new RuntimeException('Add-on id is required.');
        }
        $existing = $this->addon($key) ?? [];
        $now = now_iso();
        $record = array_merge([
            'id' => $key,
            'addonKey' => $key,
            'name' => (string) ($manifest['name'] ?? $key),
            'vendor' => (string) ($manifest['vendor'] ?? ''),
            'version' => (string) ($manifest['version'] ?? '0.0.0'),
            'status' => 'installed',
            'manifest' => $manifest,
            'permissions' => array_values((array) ($manifest['permissions'] ?? [])),
            'packageSha256' => '',
            'signatureStatus' => 'unsigned',
            'installedPath' => 'addons/installed/' . $key,
            'previousVersion' => null,
            'failureCount' => 0,
            'lastError' => null,
            'installedAt' => $now,
            'activatedAt' => null,
            'updatedAt' => $now,
        ], $existing, $addon);
        $record['id'] = $record['addonKey'] = $key;
        $record['manifest'] = $manifest !== [] ? $manifest : (array) ($record['manifest'] ?? []);
        $record['permissions'] = array_values((array) ($record['permissions'] ?? ($record['manifest']['permissions'] ?? [])));
        $record['updatedAt'] = $now;
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['addons'][$key] = $record;
            $this->writeFileStore($data);
            return $record;
        }
        $stmt = $this->pdo()->prepare(
            'INSERT INTO addons (addon_key,name,vendor,version,status,manifest_json,permissions_json,package_sha256,signature_status,installed_path,previous_version,failure_count,last_error,installed_at,activated_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,COALESCE(?,NOW()),?,NOW())
             ON DUPLICATE KEY UPDATE name=VALUES(name),vendor=VALUES(vendor),version=VALUES(version),status=VALUES(status),manifest_json=VALUES(manifest_json),permissions_json=VALUES(permissions_json),package_sha256=VALUES(package_sha256),signature_status=VALUES(signature_status),installed_path=VALUES(installed_path),previous_version=VALUES(previous_version),failure_count=VALUES(failure_count),last_error=VALUES(last_error),activated_at=VALUES(activated_at),updated_at=NOW()'
        );
        $stmt->execute([
            $key, $record['name'], $record['vendor'] ?: null, $record['version'], $record['status'],
            json_encode($record['manifest'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($record['permissions'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $record['packageSha256'] ?: null, $record['signatureStatus'] ?: 'unsigned', $record['installedPath'] ?: null,
            $record['previousVersion'] ?: null, (int) $record['failureCount'], $record['lastError'] ?: null,
            $this->sqlDate($record['installedAt'] ?? null), $this->sqlDate($record['activatedAt'] ?? null),
        ]);
        return $record;
    }

    public function deleteAddon(string $addonKey, bool $purgeData = true): void
    {
        $addonKey = strtolower(trim($addonKey));
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            unset($data['addons'][$addonKey]);
            foreach ((array) ($data['addonTasks'] ?? []) as $taskKey => $task) {
                if (($task['addonKey'] ?? '') === $addonKey || str_starts_with((string) $taskKey, $addonKey . ':')) {
                    unset($data['addonTasks'][$taskKey]);
                }
            }
            if ($purgeData) {
                unset($data['addonSettings'][$addonKey]);
                foreach ((array) ($data['addonMigrations'] ?? []) as $migrationKey => $migration) {
                    if (($migration['addonKey'] ?? '') === $addonKey) unset($data['addonMigrations'][$migrationKey]);
                }
            }
            $this->writeFileStore($data);
            return;
        }
        $pdo = $this->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM addon_tasks WHERE addon_key = ?')->execute([$addonKey]);
            if ($purgeData) {
                $pdo->prepare('DELETE FROM addon_settings WHERE addon_key = ?')->execute([$addonKey]);
                $pdo->prepare('DELETE FROM addon_migrations WHERE addon_key = ?')->execute([$addonKey]);
            }
            $pdo->prepare('DELETE FROM addons WHERE addon_key = ?')->execute([$addonKey]);
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** @param array<string,mixed> $context */
    public function logAddonActivity(string $addonKey, string $event, array $context = [], string $level = 'info'): void
    {
        $entry = [
            'id' => bin2hex(random_bytes(8)),
            'addonKey' => $addonKey,
            'event' => $event,
            'level' => in_array($level, ['info', 'warning', 'error'], true) ? $level : 'info',
            'context' => $context,
            'createdAt' => now_iso(),
        ];
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['addonActivity'][] = $entry;
            $data['addonActivity'] = array_slice((array) $data['addonActivity'], -1000);
            $this->writeFileStore($data);
            return;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO addon_activity_log (addon_key,event_key,level,context_json,created_at) VALUES (?,?,?,?,NOW())');
        $stmt->execute([$addonKey ?: null, $event, $entry['level'], json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }

    /** @return list<array<string,mixed>> */
    public function addonActivity(int $limit = 50, ?string $addonKey = null): array
    {
        $limit = max(1, min(250, $limit));
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['addonActivity'] ?? []));
            if ($addonKey !== null) {
                $rows = array_values(array_filter($rows, static fn(array $row): bool => (string) ($row['addonKey'] ?? '') === $addonKey));
            }
            return array_reverse(array_slice($rows, -$limit));
        }
        if ($addonKey !== null) {
            $stmt = $this->pdo()->prepare('SELECT * FROM addon_activity_log WHERE addon_key = ? ORDER BY id DESC LIMIT ' . $limit);
            $stmt->execute([$addonKey]);
            $rows = $stmt->fetchAll();
        } else {
            $rows = $this->pdo()->query('SELECT * FROM addon_activity_log ORDER BY id DESC LIMIT ' . $limit)->fetchAll();
        }
        return array_map(static fn(array $row): array => [
            'id' => (string) $row['id'],
            'addonKey' => (string) ($row['addon_key'] ?? ''),
            'event' => (string) $row['event_key'],
            'level' => (string) $row['level'],
            'context' => safe_json_decode((string) ($row['context_json'] ?? '{}')),
            'createdAt' => (string) $row['created_at'],
        ], $rows);
    }

    /** @return array<string,mixed> */
    public function addonSettings(string $addonKey): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            return (array) ($data['addonSettings'][$addonKey] ?? []);
        }
        $stmt = $this->pdo()->prepare('SELECT setting_key,setting_value FROM addon_settings WHERE addon_key = ?');
        $stmt->execute([$addonKey]);
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode((string) $row['setting_value'], true);
            $settings[(string) $row['setting_key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row['setting_value'];
        }
        return $settings;
    }

    public function addonSetting(string $addonKey, string $key, mixed $default = null): mixed
    {
        $settings = $this->addonSettings($addonKey);
        return $settings[$key] ?? $default;
    }

    public function setAddonSetting(string $addonKey, string $key, mixed $value): void
    {
        if (preg_match('/^[a-zA-Z0-9._-]{1,190}$/', $key) !== 1) {
            throw new RuntimeException('Invalid add-on setting key.');
        }
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['addonSettings'][$addonKey][$key] = $value;
            $this->writeFileStore($data);
            return;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO addon_settings (addon_key,setting_key,setting_value,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()');
        $stmt->execute([$addonKey, $key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }

    /** @param array<string,mixed> $backup */
    public function saveAddonBackup(array $backup): array
    {
        $record = array_merge([
            'id' => bin2hex(random_bytes(8)), 'addonKey' => '', 'version' => '', 'path' => '',
            'checksum' => '', 'reason' => 'update', 'createdAt' => now_iso(),
        ], $backup);
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['addonBackups'][$record['id']] = $record;
            $this->writeFileStore($data);
            return $record;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO addon_backups (backup_key,addon_key,version,file_path,checksum,reason,created_at) VALUES (?,?,?,?,?,?,NOW())');
        $stmt->execute([$record['id'], $record['addonKey'], $record['version'], $record['path'], $record['checksum'], $record['reason']]);
        return $record;
    }

    /** @return list<array<string,mixed>> */
    public function addonBackups(string $addonKey): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values(array_filter((array) ($data['addonBackups'] ?? []), static fn(array $row): bool => (string) ($row['addonKey'] ?? '') === $addonKey));
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
            return $rows;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM addon_backups WHERE addon_key = ? ORDER BY id DESC');
        $stmt->execute([$addonKey]);
        return array_map(static fn(array $row): array => [
            'id' => (string) $row['backup_key'], 'addonKey' => (string) $row['addon_key'], 'version' => (string) $row['version'],
            'path' => (string) $row['file_path'], 'checksum' => (string) $row['checksum'], 'reason' => (string) $row['reason'], 'createdAt' => (string) $row['created_at'],
        ], $stmt->fetchAll());
    }


    /** @return list<array<string,mixed>> */
    public function addonTasks(?string $addonKey = null): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['addonTasks'] ?? []));
            if ($addonKey !== null) {
                $rows = array_values(array_filter($rows, static fn(array $row): bool => (string) ($row['addonKey'] ?? '') === $addonKey));
            }
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($a['addonKey'] ?? '') . ':' . (string) ($a['taskKey'] ?? ''), (string) ($b['addonKey'] ?? '') . ':' . (string) ($b['taskKey'] ?? '')));
            return $rows;
        }
        if ($addonKey !== null) {
            $stmt = $this->pdo()->prepare('SELECT * FROM addon_tasks WHERE addon_key = ? ORDER BY task_key');
            $stmt->execute([$addonKey]);
            $rows = $stmt->fetchAll();
        } else {
            $rows = $this->pdo()->query('SELECT * FROM addon_tasks ORDER BY addon_key, task_key')->fetchAll();
        }
        return array_map(fn(array $row): array => [
            'addonKey' => (string) $row['addon_key'], 'taskKey' => (string) $row['task_key'],
            'schedule' => (string) $row['schedule_expression'], 'handler' => (string) $row['handler'],
            'enabled' => (bool) $row['enabled'], 'lastRunAt' => $row['last_run_at'] ?? null,
            'nextRunAt' => $row['next_run_at'] ?? null, 'lastStatus' => $row['last_status'] ?? null,
            'lastError' => $row['last_error'] ?? null, 'updatedAt' => (string) $row['updated_at'],
        ], $rows);
    }

    /** @return array<string,mixed>|null */
    public function addonTask(string $addonKey, string $taskKey): ?array
    {
        foreach ($this->addonTasks($addonKey) as $task) {
            if ((string) ($task['taskKey'] ?? '') === $taskKey) return $task;
        }
        return null;
    }

    /** @param array<string,mixed> $task */
    public function saveAddonTask(array $task): array
    {
        $addonKey = (string) ($task['addonKey'] ?? '');
        $taskKey = (string) ($task['taskKey'] ?? '');
        if ($addonKey === '' || $taskKey === '') {
            throw new RuntimeException('Scheduled add-on tasks require add-on and task keys.');
        }
        $record = array_merge([
            'addonKey' => $addonKey, 'taskKey' => $taskKey, 'schedule' => 'manual', 'handler' => '', 'enabled' => true,
            'lastRunAt' => null, 'nextRunAt' => null, 'lastStatus' => null, 'lastError' => null, 'updatedAt' => now_iso(),
        ], $task);
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $existing = (array) ($data['addonTasks'][$addonKey . ':' . $taskKey] ?? []);
            $record = array_merge($existing, $record);
            $record['updatedAt'] = now_iso();
            $data['addonTasks'][$addonKey . ':' . $taskKey] = $record;
            $this->writeFileStore($data);
            return $record;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO addon_tasks (addon_key,task_key,schedule_expression,handler,enabled,last_run_at,next_run_at,last_status,last_error,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE schedule_expression=VALUES(schedule_expression),handler=VALUES(handler),enabled=VALUES(enabled),last_run_at=VALUES(last_run_at),next_run_at=VALUES(next_run_at),last_status=VALUES(last_status),last_error=VALUES(last_error),updated_at=NOW()');
        $stmt->execute([$addonKey, $taskKey, $record['schedule'], $record['handler'], $record['enabled'] ? 1 : 0, $this->sqlDate($record['lastRunAt']), $this->sqlDate($record['nextRunAt']), $record['lastStatus'], $record['lastError']]);
        return $record;
    }


    /** @return array<string,array<string,mixed>> */
    public function addonMigrations(string $addonKey): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $result = [];
            foreach ((array) ($data['addonMigrations'] ?? []) as $key => $record) {
                if (($record['addonKey'] ?? '') === $addonKey) $result[(string) ($record['migrationKey'] ?? $key)] = $record;
            }
            return $result;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM addon_migrations WHERE addon_key = ? ORDER BY migration_key');
        $stmt->execute([$addonKey]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['migration_key']] = [
                'addonKey' => (string) $row['addon_key'], 'migrationKey' => (string) $row['migration_key'],
                'checksum' => (string) $row['checksum'], 'batch' => (int) $row['batch'], 'executedAt' => (string) $row['executed_at'],
            ];
        }
        return $result;
    }

    /** Validate all checksums and owned-table SQL before any migration in this package executes. */
    public function preflightAddonMigrations(string $addonKey, array $migrations): void
    {
        AddonMigrationPlan::validate($addonKey, $migrations, $this->addonMigrations($addonKey), $this->mode === 'mysql' ? $this->pdo() : null);
    }

    public function hasPendingAddonMigrationRecovery(string $addonKey): bool
    {
        return is_file(root_path('storage/backups/addons/migrations/' . hash('sha256', $addonKey) . '.json'));
    }

    public function hasPendingAddonRecovery(string $addonKey): bool
    {
        return $this->hasPendingAddonMigrationRecovery($addonKey)
            || is_file(root_path('storage/backups/addons/lifecycle/' . hash('sha256', $addonKey) . '.json'));
    }

    /** @param list<array{id:string,checksum:string,up:list<string>,down:list<string>}> $migrations @return list<string> */
    public function applyAddonMigrations(string $addonKey, array $migrations): array
    {
        $recovery = new AddonMigrationRecovery($addonKey);
        $recovery->lock();
        if ($recovery->read() !== null) throw new RuntimeException('This add-on has pending migration recovery. Recover the recorded operation before installing another package.');
        $applied = $this->addonMigrations($addonKey);
        $plan = AddonMigrationPlan::validate($addonKey, $migrations, $applied, $this->mode === 'mysql' ? $this->pdo() : null);
        $batch = 1;
        foreach ($applied as $record) $batch = max($batch, (int) ($record['batch'] ?? 0) + 1);
        $keys = [];
        $started = [];
        try {
            foreach ($plan as $id => $migration) {
                if (isset($applied[$id])) continue;
                $started[$id] = 0;
                foreach ($migration['up'] as $index => $sql) {
                    // DDL commits independently. Record intent before SQL and its
                    // completed boundary afterwards; interrupted intent is held.
                    $recovery->write(['operation' => 'apply', 'migrations' => $plan, 'started' => $started, 'current' => $id, 'pendingStatement' => $index]);
                    if ($this->mode === 'mysql') $this->pdo()->exec(AddonMigrationPlan::executionSql($sql));
                    $started[$id]++;
                    $recovery->write(['operation' => 'apply', 'migrations' => $plan, 'started' => $started, 'current' => $id, 'pendingStatement' => null]);
                }
                $this->recordAddonMigration($addonKey, $migration, $batch);
                $keys[] = $id;
            }
            $recovery->clear();
            return $keys;
        } catch (\Throwable $error) {
            $steps = [];
            foreach (array_reverse($started, true) as $id => $count) {
                if ($count > 0 || in_array($id, $keys, true)) $steps[] = ['migration' => AddonMigrationPlan::compensation($plan[$id], $count), 'completedUp' => $count, 'next' => 0];
            }
            $state = ['operation' => 'compensate', 'migrations' => $plan, 'steps' => $steps, 'position' => 0, 'pendingStatement' => false];
            try {
                if ($this->addonMigrationOutcomeUnknown($error)) {
                    throw new RuntimeException('The database connection failed with an unknown statement outcome.');
                }
                $recovery->write($state);
                $this->continueAddonMigrationRollback($addonKey, $recovery, $state);
            } catch (\Throwable $rollbackError) {
                throw new AddonMigrationException('Add-on migration failed and recovery is incomplete. The private recovery record and prior package were retained. Restore or recover the database before activation. ' . $error->getMessage(), true, $rollbackError);
            }
            throw new AddonMigrationException('Add-on migration failed; this attempt\'s completed changes were rolled back. ' . $error->getMessage(), false, $error);
        }
    }

    /** Resume only a definite failed down statement, using the same reviewed migration definitions. */
    public function recoverAddonMigrations(string $addonKey, array $migrations): void
    {
        $recovery = new AddonMigrationRecovery($addonKey);
        $recovery->lock();
        $state = $recovery->read();
        if ($state === null) return;
        $plan = AddonMigrationPlan::validate($addonKey, $migrations, $this->addonMigrations($addonKey));
        foreach ((array) ($state['migrations'] ?? []) as $id => $migration) {
            if (!isset($plan[$id]) || !hash_equals((string) $migration['checksum'], $plan[$id]['checksum']) || $migration !== $plan[$id]) {
                throw new RuntimeException('Recovery requires the exact original add-on migration definitions: ' . $id);
            }
        }
        if (($state['operation'] ?? '') === 'package_activation') {
            throw new RuntimeException('Package activation failed after database or file changes committed. Restore or reconcile the recorded prior state from a verified backup before clearing recovery.');
        }
        if (!in_array($state['operation'] ?? '', ['compensate', 'rollback'], true) || !empty($state['pendingStatement'])) {
            throw new RuntimeException('An interrupted add-on migration has an unknown statement outcome. Preserve its recovery record and restore or reconcile the database from a verified backup before proceeding.');
        }
        if (!isset($state['steps'], $state['position']) || !is_array($state['steps']) || !array_is_list($state['steps'])
            || !is_int($state['position']) || $state['position'] < 0 || $state['position'] > count($state['steps'])) {
            throw new RuntimeException('Invalid add-on recovery progress; preserve it for review.');
        }
        foreach ($state['steps'] as $step) {
            $migration = $step['migration'] ?? [];
            $id = $migration['id'] ?? '';
            if (!isset($plan[$id]) || !is_int($step['next'] ?? null) || $step['next'] < 0 || $step['next'] > count((array) ($migration['down'] ?? []))) {
                throw new RuntimeException('Invalid add-on recovery step; preserve it for review.');
            }
            $expected = $plan[$id];
            if ($state['operation'] === 'compensate') {
                if (!is_int($step['completedUp'] ?? null) || $step['completedUp'] < 0 || $step['completedUp'] > count($expected['up'])) {
                    throw new RuntimeException('Invalid completed add-on migration boundary; preserve it for review.');
                }
                $expected = AddonMigrationPlan::compensation($expected, $step['completedUp']);
            }
            if ($migration !== $expected) throw new RuntimeException('Recovery SQL differs from the exact reviewed migration plan.');
        }
        try {
            $this->continueAddonMigrationRollback($addonKey, $recovery, $state);
        } catch (\Throwable $error) {
            throw new AddonMigrationException('Add-on migration recovery is still incomplete; its progress was retained.', true, $error);
        }
    }

    /** @param list<array{id:string,checksum:string,up:list<string>,down:list<string>}> $migrations */
    public function rollbackAddonMigrations(string $addonKey, array $migrations, bool $all = true): void
    {
        $applied = $this->addonMigrations($addonKey);
        $keys = array_keys($applied);
        if (!$all) {
            $keys = [];
            foreach (array_reverse($migrations) as $migration) {
                if (isset($applied[$migration['id']])) { $keys[] = $migration['id']; break; }
            }
        }
        $this->rollbackAddonMigrationKeys($addonKey, $migrations, $keys);
    }

    /** @param list<array{id:string,checksum:string,up:list<string>,down:list<string>}> $migrations @param list<string> $keys */
    public function rollbackAddonMigrationKeys(string $addonKey, array $migrations, array $keys): void
    {
        if ($keys === []) return;
        $recovery = new AddonMigrationRecovery($addonKey);
        $recovery->lock();
        if ($recovery->read() !== null) throw new RuntimeException('This add-on has pending migration recovery. Recover it before rolling back another package.');
        $applied = $this->addonMigrations($addonKey);
        $plan = AddonMigrationPlan::validate($addonKey, $migrations, $applied, $this->mode === 'mysql' ? $this->pdo() : null);
        $wanted = array_fill_keys(array_map('strval', $keys), true);
        foreach ($wanted as $id => $_) {
            if (isset($applied[$id]) && !isset($plan[$id])) throw new RuntimeException('The installed package is missing an applied migration needed for rollback: ' . $id);
        }
        $steps = [];
        foreach (array_reverse($plan, true) as $id => $migration) {
            if (isset($wanted[$id], $applied[$id])) $steps[] = ['migration' => $migration, 'next' => 0];
        }
        if ($steps === []) return;
        $state = ['operation' => 'rollback', 'migrations' => $plan, 'steps' => $steps, 'position' => 0, 'pendingStatement' => false];
        $recovery->write($state);
        try {
            $this->continueAddonMigrationRollback($addonKey, $recovery, $state);
        } catch (\Throwable $error) {
            throw new AddonMigrationException('Add-on rollback is incomplete. The ledger, prior files and private recovery progress were retained; recover the database before activation.', true, $error);
        }
    }

    private function continueAddonMigrationRollback(string $addonKey, AddonMigrationRecovery $recovery, array &$state): void
    {
        while ($state['position'] < count($state['steps'])) {
            $position = $state['position'];
            $migration = $state['steps'][$position]['migration'];
            for ($index = $state['steps'][$position]['next']; $index < count($migration['down']); $index++) {
                $state['pendingStatement'] = true;
                $recovery->write($state);
                try {
                    if ($this->mode === 'mysql') $this->pdo()->exec($migration['down'][$index]);
                } catch (\Throwable $error) {
                    // A normal MySQL statement rejection can be retried. Lost
                    // connections and process interruption remain ambiguous.
                    $state['pendingStatement'] = $this->addonMigrationOutcomeUnknown($error);
                    $recovery->write($state);
                    throw $error;
                }
                $state['steps'][$position]['next'] = $index + 1;
                $state['pendingStatement'] = false;
                $recovery->write($state);
            }
            $this->forgetAddonMigration($addonKey, $migration['id']);
            $state['position']++;
            $recovery->write($state);
        }
        $recovery->clear();
    }

    private function addonMigrationOutcomeUnknown(\Throwable $error): bool
    {
        return $error instanceof \PDOException && (str_starts_with((string) ($error->errorInfo[0] ?? $error->getCode()), '08')
            || in_array((int) ($error->errorInfo[1] ?? 0), [2006, 2013, 2055], true));
    }

    private function recordAddonMigration(string $addonKey, array $migration, int $batch): void
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['addonMigrations'][$addonKey . ':' . $migration['id']] = ['addonKey' => $addonKey, 'migrationKey' => $migration['id'], 'checksum' => $migration['checksum'], 'batch' => $batch, 'executedAt' => now_iso()];
            $this->writeFileStore($data);
            return;
        }
        $this->pdo()->prepare('INSERT INTO addon_migrations (addon_key,migration_key,checksum,batch,executed_at) VALUES (?,?,?,?,NOW())')
            ->execute([$addonKey, $migration['id'], $migration['checksum'], $batch]);
    }

    private function forgetAddonMigration(string $addonKey, string $id): void
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            unset($data['addonMigrations'][$addonKey . ':' . $id]);
            $this->writeFileStore($data);
            return;
        }
        $this->pdo()->prepare('DELETE FROM addon_migrations WHERE addon_key = ? AND migration_key = ?')->execute([$addonKey, $id]);
    }

    public function addonsSafeMode(): bool
    {
        return (bool) $this->setting('addons.safe_mode', false);
    }

    public function setAddonsSafeMode(bool $enabled): void
    {
        $this->setSetting('addons.safe_mode', $enabled);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapAddonRow(array $row): array
    {
        return [
            'id' => (string) $row['addon_key'], 'addonKey' => (string) $row['addon_key'], 'name' => (string) $row['name'],
            'vendor' => (string) ($row['vendor'] ?? ''), 'version' => (string) $row['version'], 'status' => (string) $row['status'],
            'manifest' => safe_json_decode((string) ($row['manifest_json'] ?? '{}')), 'permissions' => safe_json_decode((string) ($row['permissions_json'] ?? '[]')),
            'packageSha256' => (string) ($row['package_sha256'] ?? ''), 'signatureStatus' => (string) ($row['signature_status'] ?? 'unsigned'),
            'installedPath' => (string) ($row['installed_path'] ?? ''), 'previousVersion' => $row['previous_version'] ?? null,
            'failureCount' => (int) ($row['failure_count'] ?? 0), 'lastError' => $row['last_error'] ?? null,
            'installedAt' => (string) ($row['installed_at'] ?? ''), 'activatedAt' => $row['activated_at'] ?? null, 'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function sqlDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $timestamp = strtotime((string) $value);
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }
}
