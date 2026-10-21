<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

/** A logical, prepared-statement restore format for ordinary InnoDB application tables. */
final class BackupDatabase
{
    public const MAX_ROW_BYTES = 33554432;
    private array $tables = [];
    private bool $active = false;

    public function __construct(private readonly PDO $pdo) {}

    public function begin(): void
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('A separate database connection is required for a backup snapshot.');
        $this->pdo->exec("SET SESSION time_zone='+00:00'");
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->tables = self::catalogue($this->pdo);
        if ($this->tables === []) throw new RuntimeException('The installed database contains no application tables.');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY'); $this->active = true;
    }

    public function export(BackupArchive $archive): array
    {
        if (!$this->active) throw new RuntimeException('Start the consistent database snapshot first.');
        $summary = ['server' => (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'tables' => [], 'rows' => 0];
        foreach ($this->tables as $name => $schema) {
            $archive->entry($name, 'schema', [BackupArchive::json($schema)]);
            $digest = str_repeat("\0", 32); $count = 0;
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $query = $this->pdo->query('SELECT ' . implode(',', array_map(self::identifier(...), $schema['columns'])) . ' FROM ' . self::identifier($name));
            try {
                $chunks = static function () use ($query, &$digest, &$count): \Generator {
                    while ($row = $query->fetch(PDO::FETCH_NUM)) {
                        $encoded = self::encodeRow($row); self::addDigest($digest, $encoded); $count++;
                        yield $encoded . "\n";
                    }
                };
                $archive->entry($name, 'rows', $chunks());
            } finally { $query->closeCursor(); $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); }
            $summary['tables'][$name] = ['rows' => $count, 'digest' => bin2hex($digest)]; $summary['rows'] += $count;
        }
        return $summary;
    }

    public function finish(): void
    {
        if (!$this->active) return;
        try {
            if (self::catalogue($this->pdo) !== $this->tables) throw new RuntimeException('The database schema changed during backup. The snapshot was not completed.');
            $this->pdo->commit();
        } finally { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); $this->active = false; }
    }

    public function __destruct() { if ($this->active && $this->pdo->inTransaction()) $this->pdo->rollBack(); }

    public static function catalogue(PDO $pdo): array
    {
        $rows = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        if (count($rows) > 1000) throw new RuntimeException('More than 1,000 database tables require the hosting backup service.');
        if ($pdo->query('SHOW TRIGGERS')->fetch() !== false
            || (int) $pdo->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn() !== 0
            || (int) $pdo->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn() !== 0) throw new RuntimeException('Database triggers, routines or events require a hosting-native backup; this snapshot will not omit them silently.');
        $tables = [];
        foreach ($rows as [$name, $kind]) {
            self::identifier($name);
            if ($kind !== 'BASE TABLE') throw new RuntimeException('Database views require a hosting-native backup; this snapshot will not omit them silently.');
            $engine = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $engine->execute([$name]);
            if (strtolower((string) $engine->fetchColumn()) !== 'innodb') throw new RuntimeException('Every backed-up table must use InnoDB. Use the hosting backup service for mixed storage engines.');
            $created = $pdo->query('SHOW CREATE TABLE ' . self::identifier($name))->fetch(PDO::FETCH_NUM);
            $columns = $pdo->prepare('SELECT COLUMN_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
            $columns->execute([$name]); $all = []; $insert = [];
            foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $column) {
                self::identifier($column['COLUMN_NAME']); $all[] = $column['COLUMN_NAME'];
                if (!preg_match('/\b(?:VIRTUAL|STORED) GENERATED\b/i', $column['EXTRA'])) $insert[] = $column['COLUMN_NAME'];
            }
            $tables[$name] = self::schema(['table' => $name, 'create_sql' => $created[1], 'columns' => $all, 'insert_columns' => $insert]);
        }
        ksort($tables); return $tables;
    }

    public static function schema(array $schema): array
    {
        if (array_keys($schema) !== ['table','create_sql','columns','insert_columns'] || !is_string($schema['table'])
            || !is_string($schema['create_sql']) || strlen($schema['create_sql']) > BackupArchive::MAX_METADATA - 4096
            || !is_array($schema['columns']) || !array_is_list($schema['columns']) || $schema['columns'] === [] || count($schema['columns']) > 1024
            || !is_array($schema['insert_columns']) || !array_is_list($schema['insert_columns']) || $schema['insert_columns'] === []) throw new RuntimeException('An archived database schema is unsupported.');
        $quoted = self::identifier($schema['table']);
        if (!str_starts_with($schema['create_sql'], 'CREATE TABLE ' . $quoted . ' (') || !preg_match('/\) ENGINE=InnoDB\b/', $schema['create_sql'])
            || preg_match('/\b(?:DATA\s+DIRECTORY|INDEX\s+DIRECTORY|TABLESPACE)\b/i', $schema['create_sql'])
            || preg_match('/\bREFERENCES\s+`[^`]+`\s*\./i', $schema['create_sql'])) throw new RuntimeException('An archived table uses an unsupported storage or cross-database definition.');
        foreach ($schema['columns'] as $column) self::identifier($column);
        if (count(array_unique($schema['columns'])) !== count($schema['columns'])
            || count(array_unique($schema['insert_columns'])) !== count($schema['insert_columns'])
            || array_diff($schema['insert_columns'], $schema['columns']) !== []) throw new RuntimeException('Archived table columns are inconsistent.');
        return $schema;
    }

    /** MySQL may add an explicit character set when recreating a COLLATE
     * definition. Compare only that redundant token outside SQL literals;
     * identifiers, defaults, comments, indexes and all other syntax stay exact. */
    public static function equivalentSchemas(array $left, array $right): bool
    {
        if (array_keys($left) !== array_keys($right)) return false;
        foreach ($left as $name => $schema) {
            $other = $right[$name];
            $a = $schema['create_sql']; $b = $other['create_sql'];
            unset($schema['create_sql'], $other['create_sql']);
            if ($schema !== $other || self::canonicalCreate($a) !== self::canonicalCreate($b)) return false;
        }
        return true;
    }
    private static function canonicalCreate(string $sql): string
    {
        $result = ''; $plain = ''; $length = strlen($sql);
        $flush = static function () use (&$result, &$plain): void {
            $result .= preg_replace('/\bCHARACTER SET ([A-Za-z0-9_]+) (?=COLLATE \1_[A-Za-z0-9_]+\b)/', '', $plain); $plain = '';
        };
        for ($at=0;$at<$length;$at++) {
            $char = $sql[$at];
            if (!in_array($char, ["'", '"', '`'], true)) { $plain .= $char; continue; }
            $flush(); $quote = $char; $result .= $char;
            while (++$at < $length) {
                $char = $sql[$at]; $result .= $char;
                if ($char === "\\" && $quote !== '`' && $at + 1 < $length) { $result .= $sql[++$at]; continue; }
                if ($char === $quote) {
                    if ($at + 1 < $length && $sql[$at+1] === $quote) { $result .= $sql[++$at]; continue; }
                    break;
                }
            }
        }
        $flush(); return $result;
    }

    public static function identifier(mixed $name): string
    {
        if (!is_string($name) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name)) throw new RuntimeException('A database identifier is outside the supported backup format.');
        return '`' . $name . '`';
    }

    public static function encodeRow(array $row): string
    {
        $encoded = BackupArchive::json(array_map(static fn($value) => $value === null ? null : base64_encode((string) $value), $row));
        if (strlen($encoded) > self::MAX_ROW_BYTES) throw new RuntimeException('A database row exceeds the 32 MiB encoded backup boundary. Use the hosting backup service.');
        return $encoded;
    }

    public static function decodeRow(string $encoded, array $schema): array
    {
        if (strlen($encoded) > self::MAX_ROW_BYTES) throw new RuntimeException('An archived database row exceeds its supported boundary.');
        $values = json_decode($encoded, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($values) || !array_is_list($values) || count($values) !== count($schema['columns'])) throw new RuntimeException('An archived database row does not match its columns.');
        foreach ($values as &$value) {
            if ($value === null) continue;
            if (!is_string($value) || ($decoded = base64_decode($value, true)) === false || base64_encode($decoded) !== $value) throw new RuntimeException('An archived database value is invalid.');
            $value = $decoded;
        }
        unset($value); return $values;
    }

    /** Commutative 256-bit sum of row hashes preserves multiplicity without sorting full tables. */
    public static function addDigest(string &$sum, string $encoded): void
    {
        $hash = hash('sha256', $encoded, true); $carry = 0;
        for ($at = 31; $at >= 0; $at--) { $next = ord($sum[$at]) + ord($hash[$at]) + $carry; $sum[$at] = chr($next & 255); $carry = $next >> 8; }
    }

    public static function verifyRestored(PDO $pdo, array $schemas, array $expected): void
    {
        if (array_keys($schemas) !== array_keys($expected)) throw new RuntimeException('Restored database table coverage differs from the authenticated manifest.');
        foreach ($schemas as $name => $schema) {
            $sum = str_repeat("\0", 32); $count = 0;
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $read = $pdo->query('SELECT ' . implode(',', array_map(self::identifier(...), $schema['columns'])) . ' FROM ' . self::identifier($name));
            try { while ($row = $read->fetch(PDO::FETCH_NUM)) { self::addDigest($sum, self::encodeRow($row)); $count++; } }
            finally { $read->closeCursor(); $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); }
            if ($expected[$name] !== ['rows' => $count, 'digest' => bin2hex($sum)]) throw new RuntimeException('Restored database rows failed their complete read-back checksum. The target remains quarantined.');
        }
    }
}
