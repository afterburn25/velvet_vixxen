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
        $pdo->prepare('DELETE FROM addon_tasks WHERE addon_key = ?')->execute([$addonKey]);
        if ($purgeData) {
            $pdo->prepare('DELETE FROM addon_settings WHERE addon_key = ?')->execute([$addonKey]);
            $pdo->prepare('DELETE FROM addon_migrations WHERE addon_key = ?')->execute([$addonKey]);
        }
        $pdo->prepare('DELETE FROM addons WHERE addon_key = ?')->execute([$addonKey]);
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

    /** @param list<array{id:string,checksum:string,up:list<string>,down:list<string>}> $migrations @return list<string> */
    public function applyAddonMigrations(string $addonKey, array $migrations): array
    {
        $applied = $this->addonMigrations($addonKey);
        $batch = 1;
        foreach ($applied as $record) $batch = max($batch, (int) ($record['batch'] ?? 0) + 1);
        $keys = [];
        foreach ($migrations as $migration) {
            $id = (string) $migration['id'];
            if (isset($applied[$id])) {
                if (!hash_equals((string) $applied[$id]['checksum'], (string) $migration['checksum'])) {
                    throw new RuntimeException('Add-on migration checksum mismatch: ' . $id);
                }
                continue;
            }
            if ($this->mode === 'file') {
                $data = $this->readFileStore();
                $key = $addonKey . ':' . $id;
                $data['addonMigrations'][$key] = ['addonKey'=>$addonKey,'migrationKey'=>$id,'checksum'=>$migration['checksum'],'batch'=>$batch,'executedAt'=>now_iso()];
                $this->writeFileStore($data);
            } else {
                foreach ($migration['up'] as $sql) {
                    if (trim($sql) !== '') $this->pdo()->exec($sql);
                }
                $stmt = $this->pdo()->prepare('INSERT INTO addon_migrations (addon_key,migration_key,checksum,batch,executed_at) VALUES (?,?,?,?,NOW())');
                $stmt->execute([$addonKey,$id,$migration['checksum'],$batch]);
            }
            $keys[] = $id;
        }
        return $keys;
    }

    /** @param list<array{id:string,checksum:string,up:list<string>,down:list<string>}> $migrations */
    public function rollbackAddonMigrations(string $addonKey, array $migrations, bool $all = true): void
    {
        $applied = $this->addonMigrations($addonKey);
        foreach (array_reverse($migrations) as $migration) {
            $id = (string) $migration['id'];
            if (!isset($applied[$id])) continue;
            if ($this->mode === 'file') {
                $data = $this->readFileStore();
                unset($data['addonMigrations'][$addonKey . ':' . $id]);
                $this->writeFileStore($data);
            } else {
                foreach ($migration['down'] as $sql) {
                    if (trim($sql) !== '') $this->pdo()->exec($sql);
                }
                $this->pdo()->prepare('DELETE FROM addon_migrations WHERE addon_key = ? AND migration_key = ?')->execute([$addonKey,$id]);
            }
            if (!$all) break;
        }
    }


    /** @param list<array{id:string,checksum:string,up:list<string>,down:list<string>}> $migrations @param list<string> $keys */
    public function rollbackAddonMigrationKeys(string $addonKey, array $migrations, array $keys): void
    {
        if ($keys === []) return;
        $wanted = array_fill_keys(array_map('strval', $keys), true);
        $applied = $this->addonMigrations($addonKey);
        foreach (array_reverse($migrations) as $migration) {
            $id = (string) $migration['id'];
            if (!isset($wanted[$id], $applied[$id])) continue;
            if ($this->mode === 'file') {
                $data = $this->readFileStore();
                unset($data['addonMigrations'][$addonKey . ':' . $id]);
                $this->writeFileStore($data);
            } else {
                foreach ($migration['down'] as $sql) {
                    if (trim($sql) !== '') $this->pdo()->exec($sql);
                }
                $this->pdo()->prepare('DELETE FROM addon_migrations WHERE addon_key = ? AND migration_key = ?')->execute([$addonKey, $id]);
            }
        }
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
