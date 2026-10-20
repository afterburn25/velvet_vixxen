<?php
declare(strict_types=1);

namespace Velvet\Core;

use PDO;
use PDOException;
use Throwable;

require_once __DIR__ . '/MarketingStorageException.php';

/** Lossless per-record SQL persistence; callers hold the short database data mutex. */
final class MarketingSqlRepository
{
    public const MIGRATION = '202610200015_marketing_sql_storage';
    public const AUTHORITY_KEY = 'marketing_sql_storage';
    public const COLLECTIONS = ['suppressions', 'posts', 'banners', 'landing_pages', 'links',
        'referral_codes', 'contacts', 'segments', 'campaigns', 'campaign_queue', 'events', 'consents'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $installationId,
        private readonly string $databaseHash,
        private readonly string $migrationChecksum,
    ) {
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** SQL errors never become a reason to select the old file backend. */
    public function inspect(bool $forUpdate = false): array
    {
        $ledger = $this->pdo->prepare('SELECT checksum FROM migrations WHERE migration_key=? LIMIT 1');
        $ledger->execute([self::MIGRATION]);
        $checksum = $ledger->fetchColumn();
        $applied = is_string($checksum);
        if ($applied && !hash_equals($this->migrationChecksum, $checksum)) {
            throw new MarketingStorageException('The marketing database migration does not match this release. Review Database Updates before continuing.');
        }
        $statement = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
        $statement->execute([self::AUTHORITY_KEY]);
        $rawAuthority = $statement->fetchColumn();
        $authority = null;
        if ($rawAuthority !== false) {
            try { $authority = json_decode((string) $rawAuthority, true, 512, JSON_THROW_ON_ERROR); }
            catch (Throwable) { throw new MarketingStorageException('The marketing storage authority record is invalid. Existing data has been held for review.'); }
            if (!$this->validAuthority($authority)) {
                throw new MarketingStorageException('The marketing storage authority does not match this installation. Existing data has been held for review.');
            }
        }
        $statement = $this->pdo->prepare('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?)');
        $statement->execute(['marketing_storage_state', 'marketing_storage_records', 'settings']);
        $engines = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
        if (strtolower((string) ($engines['settings'] ?? '')) !== 'innodb') {
            throw new MarketingStorageException('The marketing authority table must use transactional InnoDB storage. Existing data has been held for review.');
        }
        unset($engines['settings']);
        if (count($engines) !== 2) {
            if ($applied || $authority !== null || $engines !== []) {
                throw new MarketingStorageException('Required marketing database tables are missing. Restore or review the database; the old file has not been reactivated.');
            }
            return ['schema' => 'needed', 'authority' => null, 'state' => null];
        }
        if (array_filter($engines, static fn($engine): bool => strtolower((string) $engine) !== 'innodb')) {
            throw new MarketingStorageException('Marketing database tables must use transactional InnoDB storage. Existing data has been held for review.');
        }
        $rows = $this->pdo->query('SELECT id,storage_id,installation_id,status,header_json,layout_json,revision,source_sha256,snapshot_sha256,migrated_at FROM marketing_storage_state ORDER BY id' . ($forUpdate ? ' FOR UPDATE' : ''))->fetchAll();
        $state = count($rows) === 1 ? $rows[0] : null;
        if (count($rows) > 1 || ($state !== null && !$this->validState($state))) {
            throw new MarketingStorageException('The marketing database state is invalid. Existing data has been held for review.');
        }
        if (!$applied) {
            if ($authority !== null || $state !== null || (int) $this->pdo->query('SELECT COUNT(*) FROM marketing_storage_records')->fetchColumn() !== 0) {
                throw new MarketingStorageException('Marketing database records exist without their verified migration. Review Database Updates and storage before continuing.');
            }
            return ['schema' => 'needed', 'authority' => null, 'state' => null];
        }
        if ($authority === null && $state === null) {
            if ((int) $this->pdo->query('SELECT COUNT(*) FROM marketing_storage_records')->fetchColumn() !== 0) {
                throw new MarketingStorageException('Unclaimed marketing database records already exist. They were preserved; review them before moving any data.');
            }
            return ['schema' => 'current', 'authority' => null, 'state' => null];
        }
        if ($authority === null || $state === null
            || !hash_equals($authority['storage_id'], (string) $state['storage_id'])
            || !hash_equals($authority['source_sha256'], (string) $state['source_sha256'])
            || $authority['migrated_at'] !== $state['migrated_at']) {
            throw new MarketingStorageException('Marketing database authority and state disagree. The old file has not been reactivated; review or restore the database.');
        }
        return ['schema' => 'current', 'authority' => $authority, 'state' => $state];
    }

    public function read(array $state): array
    {
        try {
            $header = json_decode((string) $state['header_json'], true, 512, JSON_THROW_ON_ERROR);
            $layout = json_decode((string) $state['layout_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($header) || !is_array($layout) || !array_is_list($layout)) throw new \UnexpectedValueException();
            $collections = array_fill_keys(self::COLLECTIONS, []);
            $positions = array_fill_keys(self::COLLECTIONS, 0);
            $rows = $this->pdo->query('SELECT collection,entry_key_hash,entry_key_json,position,payload_json FROM marketing_storage_records ORDER BY collection,position')->fetchAll();
            foreach ($rows as $row) {
                $collection = (string) $row['collection'];
                if (!array_key_exists($collection, $collections) || (int) $row['position'] !== $positions[$collection]++) throw new \UnexpectedValueException();
                $key = json_decode((string) $row['entry_key_json'], true, 512, JSON_THROW_ON_ERROR);
                if ((!is_int($key) && !is_string($key)) || array_key_exists($key, $collections[$collection])
                    || !hash_equals(hash('sha256', self::encode($key)), (string) $row['entry_key_hash'])) throw new \UnexpectedValueException();
                $collections[$collection][$key] = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            }
            $data = [];
            foreach ($layout as $key) {
                if ((!is_int($key) && !is_string($key)) || array_key_exists($key, $data)) throw new \UnexpectedValueException();
                if (array_key_exists($key, $collections)) {
                    if (array_key_exists($key, $header)) throw new \UnexpectedValueException();
                    $data[$key] = $collections[$key];
                    unset($collections[$key]);
                } else {
                    if (!array_key_exists($key, $header)) throw new \UnexpectedValueException();
                    $data[$key] = $header[$key];
                    unset($header[$key]);
                }
            }
            if ($header !== [] || $collections !== [] || !is_array($data['meta'] ?? null)
                || !is_array($data['settings'] ?? null)
                || (int) ($data['meta']['revision'] ?? -1) !== (int) $state['revision']
                || !hash_equals((string) $state['snapshot_sha256'], hash('sha256', self::encode($data)))) throw new \UnexpectedValueException();
            return $data;
        } catch (PDOException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new MarketingStorageException('Marketing database records failed their integrity check. Existing data has been held for review.', 0, $error);
        }
    }

    /** Called inside the same transaction as initial records and activation. */
    public function import(array $data, string $sourceHash, string $storageId, string $migratedAt): array
    {
        if (!$this->pdo->inTransaction()) throw new MarketingStorageException('Marketing data could not be moved without a database transaction.');
        $before = $this->inspect(true);
        if ($before['schema'] !== 'current' || $before['state'] !== null || $before['authority'] !== null) {
            throw new MarketingStorageException('The marketing storage destination changed. Preview the move again.');
        }
        [$header, $layout, $records] = $this->split($data);
        $this->writeRows([], $records);
        $state = ['id' => 1, 'storage_id' => $storageId, 'installation_id' => $this->installationId,
            'status' => 'active', 'header_json' => self::encode($header), 'layout_json' => self::encode($layout),
            'revision' => (int) $data['meta']['revision'], 'source_sha256' => $sourceHash,
            'snapshot_sha256' => hash('sha256', self::encode($data)), 'migrated_at' => $migratedAt];
        $statement = $this->pdo->prepare('INSERT INTO marketing_storage_state (id,storage_id,installation_id,status,header_json,layout_json,revision,source_sha256,snapshot_sha256,migrated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $statement->execute(array_values($state));
        $authority = ['version' => 1, 'storage_id' => $storageId, 'installation_id' => $this->installationId,
            'database_hash' => $this->databaseHash, 'source_sha256' => $sourceHash, 'migrated_at' => $migratedAt];
        // Plain INSERT deliberately refuses any concurrently established or unknown value.
        $statement = $this->pdo->prepare('INSERT INTO settings (setting_key,setting_value,updated_at) VALUES (?,?,NOW())');
        $statement->execute([self::AUTHORITY_KEY, self::encode($authority)]);
        if ($this->read($state) !== $data) {
            throw new MarketingStorageException('The database copy did not exactly preserve the marketing records. The move was stopped.');
        }
        return ['state' => $state, 'authority' => $authority];
    }

    public function write(array $before, array $after): void
    {
        if (!$this->pdo->inTransaction()) throw new MarketingStorageException('Marketing records could not be changed without a database transaction.');
        [, , $oldRows] = $this->split($before);
        [$header, $layout, $newRows] = $this->split($after);
        $this->writeRows($oldRows, $newRows);
        $statement = $this->pdo->prepare('UPDATE marketing_storage_state SET header_json=?,layout_json=?,revision=?,snapshot_sha256=? WHERE id=1');
        $statement->execute([self::encode($header), self::encode($layout), (int) $after['meta']['revision'],
            hash('sha256', self::encode($after))]);
        if ($statement->rowCount() !== 1) throw new MarketingStorageException('The marketing database state changed unexpectedly. Existing data has been held for review.');
    }

    private function split(array $data): array
    {
        if (!is_array($data['meta'] ?? null) || !is_array($data['settings'] ?? null)
            || !is_int($data['meta']['revision'] ?? null) || $data['meta']['revision'] < 0) {
            throw new MarketingStorageException('Marketing metadata is invalid. Existing data has been preserved.');
        }
        $header = $data;
        $records = [];
        foreach (self::COLLECTIONS as $collection) {
            if (!array_key_exists($collection, $data) || !is_array($data[$collection])) {
                throw new MarketingStorageException('Marketing records are invalid. Existing data has been preserved.');
            }
            unset($header[$collection]);
            $position = 0;
            foreach ($data[$collection] as $key => $payload) {
                $encodedKey = self::encode($key);
                $hash = hash('sha256', $encodedKey);
                $records[$collection . ':' . $hash] = [$collection, $hash, $encodedKey, $position++, self::encode($payload)];
            }
        }
        return [$header, array_keys($data), $records];
    }

    /** Batches keep an imported 20,000-event history from making one query per record. */
    private function writeRows(array $before, array $after): void
    {
        $removed = array_diff_key($before, $after);
        foreach (array_chunk($removed, 100, true) as $chunk) {
            $clauses = []; $parameters = [];
            foreach ($chunk as $row) { $clauses[] = '(collection=? AND entry_key_hash=?)'; array_push($parameters, $row[0], $row[1]); }
            $statement = $this->pdo->prepare('DELETE FROM marketing_storage_records WHERE ' . implode(' OR ', $clauses));
            $statement->execute($parameters);
        }
        $batch = []; $bytes = 0;
        foreach ($after as $identity => $row) {
            if (($before[$identity] ?? null) === $row) continue;
            $size = strlen($row[2]) + strlen($row[4]);
            if ($batch !== [] && (count($batch) >= 100 || $bytes + $size > 262144)) {
                $this->upsertRows($batch); $batch = []; $bytes = 0;
            }
            $batch[] = $row; $bytes += $size;
        }
        if ($batch !== []) $this->upsertRows($batch);
    }

    private function upsertRows(array $rows): void
    {
        $sql = 'INSERT INTO marketing_storage_records (collection,entry_key_hash,entry_key_json,position,payload_json) VALUES '
            . implode(',', array_fill(0, count($rows), '(?,?,?,?,?)'))
            . ' ON DUPLICATE KEY UPDATE entry_key_json=VALUES(entry_key_json),position=VALUES(position),payload_json=VALUES(payload_json)';
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_merge(...$rows));
    }

    private function validAuthority(mixed $value): bool
    {
        return is_array($value) && ($value['version'] ?? null) === 1
            && is_string($value['storage_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $value['storage_id']) === 1
            && ($value['installation_id'] ?? null) === $this->installationId
            && ($value['database_hash'] ?? null) === $this->databaseHash
            && is_string($value['source_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $value['source_sha256']) === 1
            && is_string($value['migrated_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $value['migrated_at']) === 1;
    }

    private function validState(array $state): bool
    {
        return (int) $state['id'] === 1 && $state['status'] === 'active'
            && $state['installation_id'] === $this->installationId
            && preg_match('/^[a-f0-9]{32}$/D', (string) $state['storage_id']) === 1
            && ctype_digit((string) $state['revision'])
            && preg_match('/^[a-f0-9]{64}$/D', (string) $state['source_sha256']) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) $state['snapshot_sha256']) === 1;
    }
}
