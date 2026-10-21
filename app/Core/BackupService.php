<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Queued whole-installation snapshots. Only the CLI worker holds the drain gate. */
final class BackupService
{
    private readonly string $root;
    private readonly PrivateState $journal;
    public function __construct(private readonly Config $config, ?string $root = null)
    {
        $this->root = OperationsFiles::root($root ?? dirname($config->path(), 2));
        OperationsGate::assertNotQuarantined($this->root);
        $this->journal = new PrivateState($config, $this->root);
    }

    public function snapshot(): array
    {
        $state = BackupJobs::state($this->journal->read(BackupJobs::NAMESPACE));
        return $state + ['revision'=>BackupJobs::revision($state)];
    }

    public function savePolicy(array $input, string $revision, array $owner, callable $authorize): array
    {
        if (!is_bool($input['enabled'] ?? null) || !BackupJobs::utcTime($input['utc_time'] ?? null)
            || !is_int($input['keep_count'] ?? null) || $input['keep_count'] < 1 || $input['keep_count'] > 100
            || !is_int($input['keep_days'] ?? null) || $input['keep_days'] < 1 || $input['keep_days'] > 3650) {
            throw new InvalidArgumentException('Choose a daily UTC time, 1–100 retained backups and 1–3,650 retention days.');
        }
        $owner = BackupJobs::identity($owner); $authorize(); $this->ready();
        return $this->journal->update(BackupJobs::NAMESPACE, function (array &$raw) use ($input, $revision, $owner, $authorize): array {
            $authorize(); $this->currentOwner($owner); $this->ready(); $state = BackupJobs::state($raw); BackupJobs::expect($revision, $state);
            $policy = $state['policy']; $changedSchedule = $input['enabled'] !== $policy['enabled'] || $input['utc_time'] !== $policy['utc_time'] || $owner !== $policy['owner'];
            $policy = array_replace($policy, array_intersect_key($input, array_flip(['enabled','utc_time','keep_count','keep_days'])), ['owner'=>$owner]);
            if ($changedSchedule) {
                $now = time(); $today = strtotime(gmdate('Y-m-d', $now) . ' ' . $policy['utc_time'] . ':00 UTC');
                $policy['not_before'] = $today > $now ? $today : $today + 86400;
            }
            $state['policy'] = $policy; $authorize(); $this->currentOwner($owner); $raw = BackupJobs::state($state);
            return $policy;
        });
    }

    public function queue(string $revision, array $owner, callable $authorize): array
    {
        $owner = BackupJobs::identity($owner); $authorize(); $this->ready();
        return $this->journal->update(BackupJobs::NAMESPACE, function (array &$raw) use ($revision, $owner, $authorize): array {
            $authorize(); $this->currentOwner($owner); $this->ready(); $state = BackupJobs::state($raw); BackupJobs::expect($revision, $state);
            if (BackupJobs::open($state) !== null) throw new RuntimeException('A backup is already queued or running. Review it before requesting another.', 409);
            $this->capacity($state); $job = BackupJobs::newJob($owner, 'manual', time()); $state['jobs'][$job['id']] = $job;
            $authorize(); $this->currentOwner($owner); $raw = BackupJobs::state($state); return $job;
        });
    }

    /** A live request never captures a snapshot or starts retention as a side effect. */
    public function run(?string $keyFile = null): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Run the backup worker from the hosting scheduler.', 403);
        $gate = OperationsGate::exclusive($this->root, $this->config);
        $key = null;
        try {
            $context = $this->context();
            $key = $this->readKey($keyFile); $fingerprint = hash('sha256', $key);
            OperationsFiles::directory($this->directory(), true);
            $job = $this->claim($fingerprint);
            $result = ['completed'=>0,'recovered'=>0,'held'=>0,'failed'=>0,'pruned'=>0,'job_id'=>$job['id'] ?? null];
            if ($job !== null) {
                if ($job['status'] === 'held') $result['held']++;
                else {
                    try {
                        $this->currentOwner($job['owner']);
                        if ($job['recovering']) {
                            $this->recover($job, $key, $context); $result['recovered']++;
                        } else $this->capture($job, $key, $context);
                        $result['completed']++;
                    } catch (Throwable $error) {
                        // Connection errors are not permission changes. Incomplete work
                        // becomes a visible failed attempt, never a silent fresh snapshot.
                        if (!$job['recovering'] && $error->getCode() !== 403 && is_file($this->path($job['id']))) throw $error;
                        $reason = $error->getCode() === 403 ? 'owner_unavailable'
                            : ($job['recovering'] ? 'interrupted_snapshot' : 'snapshot_failed');
                        $this->fail($job, $reason); $result[$reason === 'owner_unavailable' ? 'held' : 'failed']++;
                    }
                }
            }
            $result['pruned'] = $this->prune($key);
            return $result;
        } finally { if (is_string($key)) sodium_memzero($key); flock($gate, LOCK_UN); fclose($gate); }
    }

    public function verify(string $id, ?string $keyFile = null): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Verify archives with the hosting CLI.', 403);
        $gate = OperationsGate::exclusive($this->root, $this->config); $key = null;
        try {
            $key = $this->readKey($keyFile); $state = BackupJobs::state($this->journal->read(BackupJobs::NAMESPACE)); $job = $this->known($state, $id);
            if ($job['status'] !== 'complete') throw new RuntimeException('Choose a completed backup to verify.');
            $this->currentOwner($job['owner']); $scan = $this->scanKnown($job, $key);
            $this->journal->update(BackupJobs::NAMESPACE, function (array &$raw) use ($id, $job): void {
                $state = BackupJobs::state($raw);
                if (($state['jobs'][$id] ?? null) !== $job) throw new RuntimeException('The backup changed during verification.', 409);
                $this->currentOwner($job['owner']); $state['jobs'][$id]['verified_at'] = time(); $state['jobs'][$id]['updated_at'] = time(); $raw = BackupJobs::state($state);
            });
            return ['id'=>$id,'archive_sha256'=>$scan['archive_sha256'],'archive_bytes'=>$scan['archive_bytes'],'manifest'=>$scan['manifest'],'verified'=>true];
        } finally { if (is_string($key)) sodium_memzero($key); flock($gate, LOCK_UN); fclose($gate); }
    }

    /** Download is ciphertext only and is still current-owner protected by its controller. */
    public function archive(string $id, callable $authorize): array
    {
        $authorize(); $state = BackupJobs::state($this->journal->read(BackupJobs::NAMESPACE)); $job = $this->known($state, $id);
        if ($job['status'] !== 'complete') throw new RuntimeException('Only a completed backup can be downloaded.', 409);
        $path = $this->path($id); OperationsFiles::noLinks($path); $before = @lstat($path);
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('The encrypted backup is unavailable.', 404);
        try {
            $opened = fstat($handle);
            if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1
                || $opened === false || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino'] || $opened['size'] !== $job['archive_bytes']) throw new RuntimeException('The saved encrypted backup changed.', 409);
            $hash = hash_init('sha256'); hash_update_stream($hash, $handle);
            if (!hash_equals($job['archive_sha256'], hash_final($hash))) throw new RuntimeException('The saved encrypted backup changed.', 409);
            $authorize(); rewind($handle);
            return ['handle'=>$handle,'filename'=>'velvet-backup-' . $id . '.vvxbak','bytes'=>$job['archive_bytes'],'sha256'=>$job['archive_sha256']];
        } catch (Throwable $error) { fclose($handle); throw $error; }
    }

    public function diagnostics(): array
    {
        $checks = [];
        $check = static function (string $key, string $label, callable $probe) use (&$checks): void {
            try { $detail = $probe(); $checks[] = ['key'=>$key,'label'=>$label,'status'=>'ready','detail'=>$detail]; }
            catch (Throwable) { $checks[] = ['key'=>$key,'label'=>$label,'status'=>'needs_review','detail'=>'This check needs operator review. Existing state was not changed.']; }
        };
        $check('runtime','Encrypted archive support', static function (): string {
            if (PHP_INT_SIZE !== 8 || !function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) throw new RuntimeException();
            return '64-bit PHP and authenticated Sodium streams are available.';
        });
        $check('runtime_files','Deployed runtime files', function (): string {
            $status = (new RuntimeInventoryService($this->root))->inspect();
            if (!$status['files_match'] || !$status['running_build_matches']) throw new RuntimeException();
            return $status['files_checked'] . ' deployed runtime files match the installed package inventory.';
        });
        $check('migrations','Database migrations', function (): string {
            $context = $this->context();
            if ($context['mode'] === 'file') return 'File storage does not require SQL migrations.';
            $directory = $this->root . '/database/migrations'; OperationsFiles::noLinks($directory);
            if (!is_dir($directory)) throw new RuntimeException();
            $pending = (new MigrationRunner($context['pdo'],$directory))->pending(false);
            if ($pending !== []) throw new RuntimeException();
            return 'The installed database has every shipped migration with matching checksums.';
        });
        $check('identity','Installation identity', function (): string { $this->context(); return 'Configuration, installation lock and database identity match.'; });
        $check('gate','Request drain gate', function (): string { $status = OperationsGate::status($this->root, $this->config); if (!$status['configured'] || !$status['ready']) throw new RuntimeException(); return $status['recovery'] ? 'Ready; public recovery mode is enabled.' : 'Ready; requests participate in the shared drain lock.'; });
        $check('key','External backup key', function (): string { $key = $this->readKey(null); sodium_memzero($key); return 'A separate private 32-byte key file is configured.'; });
        $check('journal','Durable backup journal', function (): string { $state = $this->snapshot(); return count($state['jobs']) . ' of ' . BackupJobs::MAX_JOBS . ' journal slots used.'; });
        $free = @disk_free_space($this->root);
        $checks[] = ['key'=>'space','label'=>'Local free space','status'=>is_float($free) && $free > 0 ? 'ready' : 'needs_review',
            'detail'=>is_float($free) && $free > 0 ? number_format($free / 1073741824, 2) . ' GiB available. An archive needs room for the complete retained data.' : 'Free space could not be read.'];
        $checks[] = ['key'=>'hosting','label'=>'Host prerequisites','status'=>'operator_check','detail'=>'Confirm cron, off-site copies, key custody, static-file access rules and stopped external writers in your hosting control panel. This page does not test them.'];
        return $checks;
    }

    private function claim(string $fingerprint): ?array
    {
        return $this->journal->update(BackupJobs::NAMESPACE, function (array &$raw) use ($fingerprint): ?array {
            $state = BackupJobs::state($raw); $job = BackupJobs::open($state); $now = time();
            if ($job === null) {
                $p = $state['policy']; $today = gmdate('Y-m-d', $now); $due = strtotime($today . ' ' . $p['utc_time'] . ':00 UTC');
                if (!$p['enabled'] || $p['last_date'] === $today || $now < $p['not_before'] || $now < $due) return null;
                $this->capacity($state); $job = BackupJobs::newJob($p['owner'], 'scheduled', $now); $state['policy']['last_date'] = $today;
            }
            $recovering = $job['status'] === 'processing';
            try { $this->currentOwner($job['owner']); }
            catch (RuntimeException $error) {
                if ($error->getCode() !== 403) throw $error;
                $job['status'] = 'held'; $job['reason'] = 'owner_unavailable'; $job['claim'] = null; $job['updated_at'] = time();
                $state['jobs'][$job['id']] = $job; $raw = BackupJobs::state($state); return $job + ['recovering'=>false];
            }
            if ($recovering && !hash_equals($job['key_fingerprint'], $fingerprint)) throw new RuntimeException('The interrupted backup needs its original external key. Its archive was preserved.');
            if (!$recovering) {
                $job['status'] = 'processing'; $job['claim'] = bin2hex(random_bytes(16)); $job['key_fingerprint'] = $fingerprint;
                $job['started_at'] = time(); $job['updated_at'] = $job['started_at'];
            }
            $state['jobs'][$job['id']] = $job; $raw = BackupJobs::state($state); return $job + ['recovering'=>$recovering];
        });
    }

    private function capture(array $job, string $key, array $context): void
    {
        $partial = $this->path($job['id'], true); $final = $this->path($job['id']);
        if (file_exists($partial) || file_exists($final) || is_link($partial) || is_link($final)) throw new RuntimeException('A backup archive already occupies this identifier. It was preserved.');
        $before = OperationsFiles::inventory($this->root);
        $header = ['schema'=>1,'id'=>$job['id'],'created_at'=>gmdate('c', $job['created_at']),
            'version'=>defined('VELVET_VIXXEN_VERSION') ? VELVET_VIXXEN_VERSION : 'unknown',
            'build'=>defined('VELVET_VIXXEN_BUILD_ID') ? VELVET_VIXXEN_BUILD_ID : 'unknown',
            'installation_hash'=>$context['installation_hash'],'storage_mode'=>$context['mode'],'excluded'=>OperationsFiles::EXCLUDED];
        $database = $context['pdo'] === null ? null : new BackupDatabase($context['pdo']); $database?->begin();
        $archive = new BackupArchive($partial, $key, $header);
        foreach ($before as $relative => $entry) {
            if ($entry['kind'] === 'directory') $archive->entry($relative, 'directory', [], $entry['mode']);
            else $archive->file($relative, $this->root . '/' . $relative, $entry);
        }
        $db = $database?->export($archive) ?? [];
        if (OperationsFiles::inventory($this->root) !== $before || !$this->sameContext($context)) throw new RuntimeException('Source files or configuration changed during the snapshot.');
        $database?->finish(); $archive->finish($db); unset($archive, $database);
        $scan = BackupArchive::scan($partial, $key); $this->validateScan($job, $scan, $context); $this->currentOwner($job['owner']);
        if (!@rename($partial, $final)) throw new RuntimeException('The verified archive could not be published. Its encrypted partial file was preserved.');
        $this->complete($job, $scan);
    }

    private function recover(array $job, string $key, array $context): void
    {
        $final = $this->path($job['id']); $partial = $this->path($job['id'], true);
        if (file_exists($final) && file_exists($partial)) throw new RuntimeException('Conflicting interrupted archive files need review.');
        $path = file_exists($final) ? $final : $partial;
        $scan = BackupArchive::scan($path, $key); $this->validateScan($job, $scan, $context); $this->currentOwner($job['owner']);
        if ($path === $partial && !@rename($partial, $final)) throw new RuntimeException('The complete interrupted archive could not be published.');
        $this->complete($job, $scan);
    }

    private function complete(array $claimed, array $scan): void
    {
        $this->journal->update(BackupJobs::NAMESPACE, function (array &$raw) use ($claimed, $scan): void {
            $state = BackupJobs::state($raw); $job = $this->known($state, $claimed['id']);
            if ($job['status'] !== 'processing' || !hash_equals($claimed['claim'], (string) $job['claim'])) throw new RuntimeException('The saved backup claim changed.', 409);
            $this->currentOwner($job['owner']); $now = time(); $job = array_replace($job, ['status'=>'complete','claim'=>null,'updated_at'=>$now,
                'completed_at'=>$now,'verified_at'=>$now,'archive_sha256'=>$scan['archive_sha256'],'archive_bytes'=>$scan['archive_bytes'],'manifest'=>$scan['manifest'],'reason'=>null]);
            $state['jobs'][$job['id']] = $job; $raw = BackupJobs::state($state);
        });
    }

    private function fail(array $claimed, string $reason): void
    {
        $this->journal->update(BackupJobs::NAMESPACE, function (array &$raw) use ($claimed, $reason): void {
            $state = BackupJobs::state($raw); $job = $this->known($state, $claimed['id']);
            if ($job['status'] !== 'processing' || !hash_equals((string) $job['claim'], $claimed['claim'])) throw new RuntimeException('The saved backup claim changed.', 409);
            $job['status'] = $reason === 'owner_unavailable' ? 'held' : 'failed'; $job['claim'] = null; $job['reason'] = $reason; $job['updated_at'] = time();
            $state['jobs'][$job['id']] = $job; $raw = BackupJobs::state($state);
        });
    }

    /** Retention keeps both the newest N and every copy inside the time window. */
    private function prune(string $key): int
    {
        $state = BackupJobs::state($this->journal->read(BackupJobs::NAMESPACE)); $policy = $state['policy'];
        if ($policy['owner'] === null) return 0;
        try { $this->currentOwner($policy['owner']); }
        catch (RuntimeException $error) { if ($error->getCode() === 403) return 0; throw $error; }
        $complete = array_filter($state['jobs'], static fn(array $j): bool => in_array($j['status'], ['complete','deleting'], true));
        uasort($complete, static fn(array $a, array $b): int => [$b['completed_at'], $b['id']] <=> [$a['completed_at'], $a['id']]);
        $keep = array_fill_keys(array_slice(array_keys($complete), 0, $policy['keep_count']), true); $count = 0; $cutoff = time() - $policy['keep_days'] * 86400;
        $newest = reset($complete);
        if ($newest === false) return 0;
        $candidates = array_filter($complete, static fn(array $j): bool => $j['status'] === 'deleting' || (!isset($keep[$j['id']]) && $j['completed_at'] < $cutoff));
        if ($candidates === []) return 0;
        $this->scanKnown($newest, $key);
        foreach ($candidates as $id => $job) {
            $path = $this->path($id); OperationsFiles::noLinks($path);
            $exists = file_exists($path); $eligible = !isset($keep[$id]) && $job['completed_at'] < $cutoff;
            // A committed deletion intent can survive a killed worker. An absent
            // file is finalized once; a changed policy cancels an unstarted deletion.
            if ($job['status'] === 'deleting' && (!$exists || !$eligible)) {
                $this->journal->update(BackupJobs::NAMESPACE, function (array &$raw) use ($id, $job, $exists): void {
                    $current = BackupJobs::state($raw);
                    if (($current['jobs'][$id] ?? null) !== $job) throw new RuntimeException('Retention activity changed.', 409);
                    $job['status'] = $exists ? 'complete' : 'deleted'; $job['deleted_at'] = $exists ? null : time(); $job['updated_at'] = time();
                    $current['jobs'][$id] = $job; $raw = BackupJobs::state($current);
                });
                if (!$exists) $count++;
                continue;
            }
            $this->scanKnown($job, $key);
            $job = $this->journal->update(BackupJobs::NAMESPACE, function (array &$raw) use ($id, $job, $policy): array {
                $current = BackupJobs::state($raw); $this->currentOwner($policy['owner']);
                if ($current['policy'] !== $policy || ($current['jobs'][$id] ?? null) !== $job) throw new RuntimeException('Retention settings or archive activity changed.', 409);
                $job['status'] = 'deleting'; $job['updated_at'] = time(); $current['jobs'][$id] = $job; $raw = BackupJobs::state($current); return $job;
            });
            $this->currentOwner($policy['owner']); OperationsFiles::noLinks($path);
            $stat = lstat($path);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
                || !hash_equals($job['archive_sha256'], (string) hash_file('sha256', $path)) || !@unlink($path)) throw new RuntimeException('Retention could not remove the exact reviewed encrypted archive.');
            $this->journal->update(BackupJobs::NAMESPACE, function (array &$raw) use ($id, $job): void {
                $current = BackupJobs::state($raw);
                if (($current['jobs'][$id] ?? null) !== $job) throw new RuntimeException('Retention activity changed.', 409);
                $job['status'] = 'deleted'; $job['deleted_at'] = time(); $job['updated_at'] = $job['deleted_at'];
                $current['jobs'][$id] = $job; $raw = BackupJobs::state($current);
            });
            $count++;
        }
        return $count;
    }

    private function scanKnown(array $job, string $key): array
    {
        if (!hash_equals((string) $job['key_fingerprint'], hash('sha256', $key))) throw new RuntimeException('This archive needs the original backup key. No archive was removed.');
        $scan = BackupArchive::scan($this->path($job['id']), $key); $context = $this->context(); $this->validateScan($job, $scan, $context);
        if ($scan['archive_sha256'] !== $job['archive_sha256'] || $scan['archive_bytes'] !== $job['archive_bytes'] || $scan['manifest'] !== $job['manifest']) throw new RuntimeException('The saved encrypted archive differs from its durable record.');
        return $scan;
    }
    private function validateScan(array $job, array $scan, array $context): void
    {
        BackupJobs::manifest($scan['manifest']);
        if ($scan['header']['id'] !== $job['id'] || $scan['header']['installation_hash'] !== $context['installation_hash']
            || $scan['header']['storage_mode'] !== $context['mode'] || ($context['mode'] === 'mysql') !== ($scan['manifest']['database'] !== [])) throw new RuntimeException('The encrypted archive does not match this installation and backup job.');
    }
    private function readKey(?string $file): string
    {
        $this->config->reload(); $path = $file ?? $this->config->get('operations.backup_key_file');
        if (!is_string($path) || $path === '') throw new RuntimeException('Configure the external private backup key file before running snapshots.');
        return OperationsFiles::key($path, $this->root);
    }
    private function ready(): void
    {
        $status = OperationsGate::status($this->root, $this->config);
        if (!$status['configured'] || !$status['ready']) throw new RuntimeException('Complete Operations setup and the first reviewed CLI activation before changing backup jobs.');
    }
    private function currentOwner(array $identity): void
    {
        (new Store(new Config($this->config->path())))->requireSceneScheduleOwner($identity);
    }
    private function capacity(array $state): void
    {
        if (count($state['jobs']) >= BackupJobs::MAX_JOBS) throw new RuntimeException('The backup journal reached its 1,000-attempt limit. Export and review its history with your operator; no existing record was evicted.');
    }
    private function known(array $state, string $id): array
    {
        if (!BackupJobs::id($id) || !isset($state['jobs'][$id])) throw new RuntimeException('The saved backup was not found.', 404);
        return $state['jobs'][$id];
    }
    private function directory(): string { return $this->root . '/storage/private/operations/backups'; }
    private function path(string $id, bool $partial = false): string
    {
        if (!BackupJobs::id($id)) throw new RuntimeException('The backup identifier is invalid.');
        return $this->directory() . '/' . $id . ($partial ? '.partial' : '.vvxbak');
    }

    /** Read-only identity checks, including both persisted SQL identities when present. */
    private function context(): array
    {
        $this->config->reload(); $id = $this->config->get('installation_id'); $mode = $this->config->get('storage_mode', 'mysql');
        $configPath = $this->config->path(); $lockPath = $this->root . '/storage/install.lock'; OperationsFiles::noLinks($configPath); OperationsFiles::noLinks($lockPath);
        if ($this->config->get('installed') !== true || !is_string($id) || $id === '' || !in_array($mode, ['file','mysql'], true)) throw new RuntimeException('A verified installed configuration is required.');
        $lockBytes = @file_get_contents($lockPath); $lock = is_string($lockBytes) ? json_decode($lockBytes, true) : null;
        if (!is_array($lock) || ($lock['installation_id'] ?? null) !== $id) throw new RuntimeException('The installation lock does not match the saved configuration.');
        $pdo = null;
        if ($mode === 'mysql') {
            $pdo = Database::connect((array) $this->config->get('db')); $verified = false;
            foreach (['SELECT state_value FROM installation_state WHERE state_key=?','SELECT setting_value FROM settings WHERE setting_key=?'] as $sql) {
                try { $query = $pdo->prepare($sql); $query->execute(['installation']); $value = $query->fetchColumn(); }
                catch (PDOException $error) { if ($error->getCode() === '42S02') continue; throw $error; }
                if ($value === false) continue;
                $identity = json_decode((string) $value, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($identity)) throw new RuntimeException('The database installation identity needs review.');
                if (!array_key_exists('installation_id', $identity)) continue;
                if ($identity['installation_id'] !== $id) throw new RuntimeException('The database belongs to a different installation.');
                $verified = true;
            }
            if (!$verified) throw new RuntimeException('The database installation identity could not be verified.');
        }
        return ['mode'=>$mode,'pdo'=>$pdo,'installation_hash'=>hash('sha256',$id),
            'config_hash'=>(string) hash_file('sha256',$configPath),'lock_hash'=>hash('sha256',$lockBytes)];
    }
    private function sameContext(array $before): bool
    {
        return hash_equals($before['config_hash'], (string) hash_file('sha256', $this->config->path()))
            && hash_equals($before['lock_hash'], (string) hash_file('sha256', $this->root . '/storage/install.lock'));
    }
}
