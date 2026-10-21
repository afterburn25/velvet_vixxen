<?php
declare(strict_types=1);

namespace Velvet\Core;

use PDO;
use RuntimeException;

require_once __DIR__ . '/MarketingAnalytics.php';

/** Secondary reporting index. Original marketing records remain the only authority. */
final class MarketingMeasurementSqlRepository
{
    public const MIGRATION = '202610210016_marketing_measurements';

    public function __construct(private readonly PDO $pdo) {}

    public function available(): bool
    {
        $query = $this->pdo->prepare('SELECT checksum FROM migrations WHERE migration_key=?');
        $query->execute([self::MIGRATION]); $checksum = $query->fetchColumn();
        if ($checksum === false) return false;
        $path = dirname(__DIR__, 2) . '/database/migrations/' . self::MIGRATION . '.php';
        if (!is_string($checksum) || !is_file($path) || !hash_equals(hash_file('sha256', $path), $checksum)) throw new MarketingStorageException('The measurement migration does not match this release. Review Database Updates.');
        $query = $this->pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('marketing_measurement_state','marketing_measurement_records')");
        $tables = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($tables) !== 2 || array_filter($tables, static fn($engine): bool => strtolower((string) $engine) !== 'innodb')) throw new MarketingStorageException('Required measurement index tables are missing or nontransactional. Review Database Updates.');
        return true;
    }

    public function current(array $state): bool
    {
        if (!$this->available()) throw new MarketingStorageException('Apply the measurement reporting migration in Database Updates before opening SQL reports. Original measurements remain preserved.');
        $rows = $this->pdo->query('SELECT * FROM marketing_measurement_state ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || (int) $rows[0]['id'] !== 1 || (int) $rows[0]['revision'] !== (int) $state['revision']
            || !hash_equals((string) $rows[0]['snapshot_sha256'], (string) $state['snapshot_sha256'])
            || !hash_equals((string) $rows[0]['header_sha256'], hash('sha256', (string) $state['header_json']))) return false;
        // Digest only compact index metadata, never all event JSON. Edited/deleted index
        // rows are repaired from the checksum-verified authority before any report.
        return hash_equals((string) $rows[0]['projection_sha256'], $this->digest());
    }

    public function sync(array $data, bool $force = false): void
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Measurement indexing requires the source transaction.');
        if (!$this->available()) return;
        $rows = [];
        foreach ($data['events'] as $event) {
            if (!is_array($event) || !array_key_exists('measurement_version', $event)) continue;
            $event = MarketingAnalytics::event($event);
            $key = hash('sha256', $event['id']);
            if (isset($rows['event:' . $key])) throw new RuntimeException('Duplicate measurement identities need review.');
            $rows['event:' . $key] = $this->row('event', $key, $event['epoch'], $event, $event['actor']);
        }
        foreach (MarketingAnalytics::state($data)['rollups'] as $key => $rollup) {
            $rollup = MarketingAnalytics::rollup($rollup);
            if (!is_string($key) || !hash_equals(MarketingAnalytics::rollupKey($rollup), $key)) throw new RuntimeException('Saved measurement total identities need review.');
            $rows['rollup:' . $key] = $this->row('rollup', $key, (new \DateTimeImmutable($rollup['date'], new \DateTimeZone('UTC')))->getTimestamp(), $rollup, '');
        }
        $old = $this->pdo->query('SELECT kind,entry_key,payload_sha256 FROM marketing_measurement_records')->fetchAll(PDO::FETCH_ASSOC);
        $oldMap = [];
        foreach ($old as $record) $oldMap[$record['kind'] . ':' . $record['entry_key']] = $record;
        $remove = $this->pdo->prepare('DELETE FROM marketing_measurement_records WHERE kind=? AND entry_key=?');
        foreach (array_diff_key($oldMap, $rows) as $record) $remove->execute([$record['kind'], $record['entry_key']]);
        $upsert = $this->pdo->prepare('INSERT INTO marketing_measurement_records (kind,entry_key,occurred_at,event_name,campaign_id,referral_code,actor_hash,payload_sha256,payload_json) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE occurred_at=VALUES(occurred_at),event_name=VALUES(event_name),campaign_id=VALUES(campaign_id),referral_code=VALUES(referral_code),actor_hash=VALUES(actor_hash),payload_sha256=VALUES(payload_sha256),payload_json=VALUES(payload_json)');
        foreach ($rows as $key => $row) {
            if ($force || ($oldMap[$key]['payload_sha256'] ?? null) !== $row[7]) $upsert->execute($row);
        }
        $header = array_diff_key($data, array_flip(MarketingSqlRepository::COLLECTIONS));
        $query = $this->pdo->prepare('INSERT INTO marketing_measurement_state (id,revision,snapshot_sha256,header_sha256,projection_sha256) VALUES (1,?,?,?,?) ON DUPLICATE KEY UPDATE revision=VALUES(revision),snapshot_sha256=VALUES(snapshot_sha256),header_sha256=VALUES(header_sha256),projection_sha256=VALUES(projection_sha256)');
        $query->execute([(int) $data['meta']['revision'], hash('sha256', MarketingSqlRepository::encode($data)), hash('sha256', MarketingSqlRepository::encode($header)), $this->digest()]);
    }

    public function report(array $filters, int $retentionDays): array
    {
        $report = MarketingAnalytics::report($this->select('rollup', $filters), $this->select('event', $filters), $filters, 'mysql-indexed', $retentionDays);
        if ($filters['compare']) {
            $previous = MarketingAnalytics::previous($filters);
            $report['previous'] = MarketingAnalytics::report($this->select('rollup', $previous), $this->select('event', $previous), $previous, 'mysql-indexed', $retentionDays);
        }
        return $report;
    }

    public function capacity(): array
    {
        $counts = $this->pdo->query('SELECT kind,COUNT(*) FROM marketing_measurement_records GROUP BY kind')->fetchAll(PDO::FETCH_KEY_PAIR);
        $raw = (int) $this->pdo->query("SELECT COUNT(*) FROM marketing_storage_records WHERE collection='events'")->fetchColumn();
        $measured = (int) ($counts['event'] ?? 0);
        return ['raw_events' => $raw, 'measured_events' => $measured, 'legacy_events' => max(0, $raw - $measured), 'raw_limit' => MarketingAnalytics::MAX_EVENTS,
            'daily_totals' => (int) ($counts['rollup'] ?? 0), 'daily_limit' => MarketingAnalytics::MAX_ROLLUPS];
    }

    private function select(string $kind, array $filters): array
    {
        $where = ['kind=?','occurred_at>=?','occurred_at<?']; $values = [$kind, $filters['from'], $filters['until']];
        foreach (['campaign' => 'campaign_id', 'referral' => 'referral_code', 'event' => 'event_name'] as $key => $column) {
            if ($filters[$key] !== '') { $where[] = $column . '=?'; $values[] = $filters[$key]; }
        }
        $query = $this->pdo->prepare('SELECT payload_json,payload_sha256 FROM marketing_measurement_records WHERE ' . implode(' AND ', $where) . ' ORDER BY occurred_at,entry_key');
        $query->execute($values); $records = [];
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            if (!hash_equals((string) $row['payload_sha256'], hash('sha256', (string) $row['payload_json']))) throw new MarketingStorageException('The measurement index payload changed unexpectedly. Rebuild it before reporting.', 1001);
            $records[] = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        }
        return $records;
    }

    private function row(string $kind, string $key, int $epoch, array $row, string $actor): array
    {
        $json = MarketingSqlRepository::encode($row);
        return [$kind, $key, $epoch, $row['name'], $row['campaign'] ?? '', $row['referral'] ?? '', $actor, hash('sha256', $json), $json];
    }

    private function digest(): string
    {
        $hash = hash_init('sha256');
        $query = $this->pdo->query('SELECT kind,entry_key,occurred_at,event_name,campaign_id,referral_code,actor_hash,payload_sha256 FROM marketing_measurement_records ORDER BY kind,entry_key');
        while ($row = $query->fetch(PDO::FETCH_NUM)) hash_update($hash, MarketingSqlRepository::encode(array_map('strval', $row)) . "\n");
        return hash_final($hash);
    }
}
