<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;

/** The private journal is bounded and rejects unknown states without overwriting them. */
final class BackupJobs
{
    public const NAMESPACE = 'site_backups';
    public const MAX_JOBS = 1000;
    public const REASONS = ['owner_unavailable','snapshot_failed','interrupted_snapshot','archive_missing','archive_changed','integrity_failed'];

    public static function initial(): array
    {
        return ['schema'=>1, 'policy'=>['enabled'=>false,'utc_time'=>'03:00','keep_count'=>7,'keep_days'=>30,
            'owner'=>null,'not_before'=>0,'last_date'=>null], 'jobs'=>[]];
    }

    public static function state(array $state): array
    {
        if ($state === []) return self::initial();
        if (array_keys($state) !== ['schema','policy','jobs'] || $state['schema'] !== 1 || !is_array($state['policy'])
            || !is_array($state['jobs']) || count($state['jobs']) > self::MAX_JOBS) self::bad();
        $p = $state['policy'];
        if (array_keys($p) !== ['enabled','utc_time','keep_count','keep_days','owner','not_before','last_date']
            || !is_bool($p['enabled']) || !self::utcTime($p['utc_time'])
            || !is_int($p['keep_count']) || $p['keep_count'] < 1 || $p['keep_count'] > 100
            || !is_int($p['keep_days']) || $p['keep_days'] < 1 || $p['keep_days'] > 3650
            || !is_int($p['not_before']) || $p['not_before'] < 0
            || ($p['last_date'] !== null && (!is_string($p['last_date']) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $p['last_date'])))) self::bad();
        if ($p['owner'] !== null) self::owner($p['owner']);
        if ($p['enabled'] && $p['owner'] === null) self::bad();
        $open = 0;
        foreach ($state['jobs'] as $id => $job) {
            self::job($job);
            if ($id !== $job['id']) self::bad();
            if (in_array($job['status'], ['queued','processing'], true)) $open++;
        }
        if ($open > 1) self::bad();
        return $state;
    }

    public static function job(mixed $job): array
    {
        if (!is_array($job) || array_keys($job) !== ['id','status','source','owner','created_at','updated_at','started_at','completed_at',
            'claim','key_fingerprint','archive_sha256','archive_bytes','manifest','verified_at','deleted_at','reason']
            || !self::id($job['id']) || !in_array($job['status'], ['queued','processing','complete','failed','held','deleting','deleted'], true)
            || !in_array($job['source'], ['manual','scheduled'], true)) self::bad();
        self::owner($job['owner']);
        foreach (['created_at','updated_at'] as $key) if (!is_int($job[$key]) || $job[$key] < 1) self::bad();
        foreach (['started_at','completed_at','verified_at','deleted_at'] as $key) if ($job[$key] !== null && (!is_int($job[$key]) || $job[$key] < 1)) self::bad();
        foreach (['key_fingerprint','archive_sha256'] as $key) if ($job[$key] !== null && (!is_string($job[$key]) || !preg_match('/\A[a-f0-9]{64}\z/D', $job[$key]))) self::bad();
        if ($job['claim'] !== null && !self::id($job['claim'])) self::bad();
        if ($job['archive_bytes'] !== null && (!is_int($job['archive_bytes']) || $job['archive_bytes'] < 1)) self::bad();
        if ($job['manifest'] !== null) self::manifest($job['manifest']);
        if ($job['reason'] !== null && !in_array($job['reason'], self::REASONS, true)) self::bad();
        if ($job['status'] === 'queued' && ($job['claim'] !== null || $job['started_at'] !== null || $job['archive_sha256'] !== null || $job['manifest'] !== null)) self::bad();
        if ($job['status'] === 'processing' && ($job['claim'] === null || $job['started_at'] === null || $job['key_fingerprint'] === null || $job['completed_at'] !== null)) self::bad();
        if (in_array($job['status'], ['complete','deleting','deleted'], true) && ($job['claim'] !== null || $job['archive_sha256'] === null
            || $job['archive_bytes'] === null || $job['manifest'] === null || $job['completed_at'] === null || $job['verified_at'] === null)) self::bad();
        if ($job['status'] === 'deleted' && $job['deleted_at'] === null) self::bad();
        return $job;
    }

    public static function manifest(mixed $value): array
    {
        if (!is_array($value) || array_keys($value) !== ['entries','bytes','inventory_sha256','database','completed_at']
            || !is_int($value['entries']) || $value['entries'] < 1 || $value['entries'] > OperationsFiles::MAX_ENTRIES
            || !is_int($value['bytes']) || $value['bytes'] < 0 || $value['bytes'] > OperationsFiles::MAX_BYTES
            || !is_string($value['inventory_sha256']) || !preg_match('/\A[a-f0-9]{64}\z/D', $value['inventory_sha256'])
            || !is_string($value['completed_at']) || strtotime($value['completed_at']) === false) self::bad();
        $db = $value['database'];
        if (!is_array($db)) self::bad();
        if ($db !== []) {
            if (array_keys($db) !== ['server','tables','rows'] || !is_string($db['server']) || strlen($db['server']) > 100
                || !is_array($db['tables']) || $db['tables'] === [] || count($db['tables']) > 1000 || !is_int($db['rows']) || $db['rows'] < 0) self::bad();
            $count = 0;
            foreach ($db['tables'] as $name => $row) {
                BackupDatabase::identifier($name);
                if (!is_array($row) || array_keys($row) !== ['rows','digest'] || !is_int($row['rows']) || $row['rows'] < 0
                    || !is_string($row['digest']) || !preg_match('/\A[a-f0-9]{64}\z/D', $row['digest'])) self::bad();
                $count += $row['rows'];
            }
            if ($count !== $db['rows']) self::bad();
        }
        return $value;
    }

    public static function owner(mixed $owner): array
    {
        if (!is_array($owner) || array_keys($owner) !== ['id','email'] || !is_string($owner['id']) || $owner['id'] === '' || strlen($owner['id']) > 190
            || !is_string($owner['email']) || strlen($owner['email']) > 254 || !filter_var($owner['email'], FILTER_VALIDATE_EMAIL)) self::bad();
        return $owner;
    }
    public static function identity(array $owner): array { return self::owner(['id'=>(string) ($owner['id'] ?? ''),'email'=>$owner['email'] ?? null]); }
    public static function id(mixed $id): bool { return is_string($id) && preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1; }
    public static function utcTime(mixed $value): bool { return is_string($value) && preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $value) === 1; }
    public static function revision(array $state): string { return hash('sha256', BackupArchive::json(self::state($state))); }
    public static function expect(string $expected, array $state): void
    {
        if (!hash_equals(self::revision($state), $expected)) throw new RuntimeException('Backup settings or activity changed. Reload Operations before saving.', 409);
    }
    public static function newJob(array $owner, string $source, int $now): array
    {
        return self::job(['id'=>bin2hex(random_bytes(16)),'status'=>'queued','source'=>$source,'owner'=>self::identity($owner),
            'created_at'=>$now,'updated_at'=>$now,'started_at'=>null,'completed_at'=>null,'claim'=>null,'key_fingerprint'=>null,
            'archive_sha256'=>null,'archive_bytes'=>null,'manifest'=>null,'verified_at'=>null,'deleted_at'=>null,'reason'=>null]);
    }
    public static function open(array $state): ?array
    {
        foreach ($state['jobs'] as $job) if (in_array($job['status'], ['queued','processing'], true)) return $job;
        return null;
    }
    public static function reason(?string $reason): string
    {
        return match ($reason) {
            'owner_unavailable'=>'The authorizing owner is no longer active. Review access before creating a new backup.',
            'snapshot_failed'=>'The snapshot did not finish. Existing archives were preserved. Inspect storage and run diagnostics before trying again.',
            'interrupted_snapshot'=>'The worker stopped before a complete authenticated archive existed. Partial encrypted bytes were preserved for operator review.',
            'archive_missing'=>'The saved archive is missing. Restore its exact encrypted file from your off-site copy.',
            'archive_changed','integrity_failed'=>'The saved archive did not pass its authenticated checksum. It was preserved for operator review.',
            default=>'',
        };
    }
    private static function bad(): never { throw new RuntimeException('The saved backup journal needs review. Existing records and archives were preserved.', 503); }
}
