<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Resume only the additive SQL understood by this application's migrations.
 * Inspection is read-only. Existing data, compatible objects and migration
 * definitions are never rewritten merely to adopt an interrupted installation.
 */
final class MigrationResumeSchema
{
    private array $catalog = [];
    private array $operations = [];
    private array $tables = [];
    private readonly string $database;
    private readonly bool $mariaDb;

    public function __construct(private readonly PDO $pdo, array $migrations, string $registrySql)
    {
        $this->database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $this->mariaDb = stripos((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'mariadb') !== false;
        $this->parseStatement($registrySql);
        foreach ($migrations as $migration) {
            foreach ($migration['up'] as $sql) {
                if (trim($sql) !== '') $this->parseStatement($sql);
            }
        }
    }

    public function validate(): void
    {
        $this->readSchema();
        foreach ($this->catalog as $table => $expected) {
            $this->validateTable($table, $expected);
        }
    }

    public function hasTable(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /** Execute only the absent, verified portions of an original up statement. */
    public function execute(string $sql): void
    {
        $operation = $this->operations[trim($sql)] ?? null;
        if (!is_array($operation)) throw new RuntimeException('This migration statement is not supported for safe installation continuation.');
        $table = $operation['table'];
        // Recheck actual state, including changes committed by the preceding DDL.
        $this->readSchema($table);
        $this->validateTable($table, $this->catalog[$table]);
        if ($operation['kind'] === 'create') {
            if (!$this->hasTable($table)) $this->pdo->exec($sql);
            return;
        }
        if (!$this->hasTable($table)) $this->conflict($table, 'the required table is missing before its update');
        if ($operation['kind'] === 'seed_sandbox') {
            $exists = $this->pdo->query("SELECT 1 FROM payment_providers WHERE provider_key='sandbox' LIMIT 1")->fetchColumn();
            if ($exists === false) $this->pdo->exec($operation['insert']);
            return;
        }
        $clauses = [];
        foreach ($operation['clauses'] as $clause) {
            if ($clause['kind'] === 'column') {
                $current = $this->tables[$table]['columns'][$clause['name']] ?? null;
                if ($current === null) {
                    if ($clause['action'] === 'modify') $this->conflict($table . '.' . $clause['name'], 'the column to update is missing');
                    $clauses[] = $clause['sql'];
                } elseif ($clause['action'] === 'modify' && !$this->columnMatches($current, $clause['definition'])) {
                    // The only supported MODIFY is a known, non-narrowing ENUM
                    // extension. MySQL preserves the text values of existing rows.
                    $this->assertEnumExpansion($table, $clause['name'], $current, $clause['definition']);
                    $clauses[] = $clause['sql'];
                }
            } elseif ($clause['kind'] === 'index') {
                if (!isset($this->tables[$table]['indexes'][$clause['name']])) $clauses[] = $clause['sql'];
            } elseif (!$this->matchingForeignKey($table, $clause['name'], $clause['definition'])) {
                $clauses[] = $clause['sql'];
            }
        }
        if ($clauses !== []) $this->pdo->exec('ALTER TABLE ' . $this->quote($table) . ' ' . implode(', ', $clauses));
    }

    private function parseStatement(string $sql): void
    {
        $sql = trim($sql);
        if (isset($this->operations[$sql])) return;
        if (preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s*\((.*)\)\s*ENGINE\s*=\s*InnoDB\s+DEFAULT\s+CHARSET\s*=\s*utf8mb4\s+COLLATE\s*=\s*utf8mb4_unicode_ci\s*;?$/is', $sql, $match)) {
            $table = $match[1];
            if (isset($this->catalog[$table])) throw new RuntimeException('Duplicate table declaration in resumable migrations: ' . $table);
            $entry = ['columns' => [], 'indexes' => [], 'foreign' => [], 'baseColumns' => [], 'baseIndexes' => [], 'baseForeign' => []];
            foreach ($this->splitClauses($match[2]) as $part) {
                $parsed = $this->parseDefinition($part);
                $kind = $parsed['kind'];
                $name = $parsed['name'];
                if ($kind === 'column') {
                    $entry['columns'][$name] = [$parsed['definition']];
                    $entry['baseColumns'][] = $name;
                    if ($parsed['primary']) {
                        $entry['indexes']['PRIMARY'] = ['columns' => [$name], 'unique' => true];
                        $entry['baseIndexes'][] = 'PRIMARY';
                    }
                    if ($parsed['unique']) {
                        $entry['indexes'][$name] = ['columns' => [$name], 'unique' => true];
                        $entry['baseIndexes'][] = $name;
                    }
                } elseif ($kind === 'index') {
                    $entry['indexes'][$name] = $parsed['definition'];
                    $entry['baseIndexes'][] = $name;
                } else {
                    $entry['foreign'][$name] = $parsed['definition'];
                    $entry['baseForeign'][] = $name;
                }
            }
            foreach ($entry['indexes']['PRIMARY']['columns'] ?? [] as $column) {
                if (!isset($entry['columns'][$column])) throw new RuntimeException('Unknown primary-key column in migration: ' . $table . '.' . $column);
                $entry['columns'][$column][0]['nullable'] = false;
            }
            $this->catalog[$table] = $entry;
            $this->operations[$sql] = ['kind' => 'create', 'table' => $table];
            return;
        }
        if (preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+(.+?)\s*;?$/is', $sql, $match)) {
            $table = $match[1];
            if (!isset($this->catalog[$table])) throw new RuntimeException('An update references an undeclared table: ' . $table);
            $clauses = [];
            foreach ($this->splitClauses($match[2]) as $part) {
                if (!preg_match('/^(ADD|MODIFY)\s+(?:COLUMN\s+)?(.+)$/is', $part, $clauseMatch)) {
                    throw new RuntimeException('The ' . $table . ' migration contains an unsupported continuation operation. Existing data was preserved.');
                }
                $action = strtolower($clauseMatch[1]);
                $parsed = $this->parseDefinition($clauseMatch[2]);
                $name = $parsed['name'];
                if ($action === 'modify' && ($parsed['kind'] !== 'column' || !str_starts_with($parsed['definition']['type'], 'enum('))) {
                    throw new RuntimeException('Only compatible ENUM extensions can be resumed automatically: ' . $table . '.' . $name);
                }
                if ($parsed['kind'] === 'column') {
                    if ($action === 'modify' && !isset($this->catalog[$table]['columns'][$name])) {
                        throw new RuntimeException('An update modifies an undeclared column: ' . $table . '.' . $name);
                    }
                    if ($action === 'modify') {
                        $prior = $this->catalog[$table]['columns'][$name];
                        $this->assertEnumExpansion($table, $name, $prior[array_key_last($prior)], $parsed['definition']);
                    } elseif (isset($this->catalog[$table]['columns'][$name])) {
                        throw new RuntimeException('Duplicate column declaration: ' . $table . '.' . $name);
                    }
                    $this->catalog[$table]['columns'][$name][] = $parsed['definition'];
                } else {
                    if ($action !== 'add') throw new RuntimeException('Unsupported non-additive schema update: ' . $table . '.' . $name);
                    $bucket = $parsed['kind'] === 'index' ? 'indexes' : 'foreign';
                    if (isset($this->catalog[$table][$bucket][$name])) throw new RuntimeException('Duplicate schema object: ' . $table . '.' . $name);
                    $this->catalog[$table][$bucket][$name] = $parsed['definition'];
                }
                $clauses[] = array_merge($parsed, ['action' => $action, 'sql' => $part]);
            }
            $this->operations[$sql] = ['kind' => 'alter', 'table' => $table, 'clauses' => $clauses];
            return;
        }
        // The bundled gateway is the sole data seed in the migration source.
        // Preserve every existing provider field, including customized metadata.
        if (preg_match('/^INSERT\s+INTO\s+payment_providers\s*\(provider_key,name,is_enabled,test_mode,supports_recurring,settings_json,sort_order,created_at,updated_at\)\s*VALUES\s*\(\x27sandbox\x27,\x27Sandbox Test Gateway\x27,1,1,1,\x27\{\}\x27,10,NOW\(\),NOW\(\)\)\s*ON DUPLICATE KEY UPDATE\s+.+$/is', $sql)) {
            $insert = preg_replace('/\s+ON DUPLICATE KEY UPDATE\s+.+$/is', '', $sql);
            $this->operations[$sql] = ['kind' => 'seed_sandbox', 'table' => 'payment_providers', 'insert' => $insert];
            return;
        }
        throw new RuntimeException('A migration uses SQL that is not supported for data-preserving installation continuation. Existing data was preserved.');
    }

    private function parseDefinition(string $sql): array
    {
        $sql = trim($sql);
        if (preg_match('/^PRIMARY\s+KEY\s*\((.+)\)$/is', $sql, $match)) {
            return ['kind' => 'index', 'name' => 'PRIMARY', 'definition' => ['columns' => $this->columnList($match[1]), 'unique' => true]];
        }
        if (preg_match('/^(UNIQUE\s+)?(?:KEY|INDEX)\s+`?([A-Za-z0-9_]+)`?\s*\((.+)\)$/is', $sql, $match)) {
            return ['kind' => 'index', 'name' => $match[2], 'definition' => ['columns' => $this->columnList($match[3]), 'unique' => trim($match[1]) !== '']];
        }
        if (preg_match('/^CONSTRAINT\s+`?([A-Za-z0-9_]+)`?\s+FOREIGN\s+KEY\s*\(([^)]+)\)\s+REFERENCES\s+`?([A-Za-z0-9_]+)`?\s*\(([^)]+)\)\s+ON\s+DELETE\s+(CASCADE|SET NULL|RESTRICT|NO ACTION)(?:\s+ON\s+UPDATE\s+(CASCADE|SET NULL|RESTRICT|NO ACTION))?$/is', $sql, $match)) {
            return ['kind' => 'foreign', 'name' => $match[1], 'definition' => ['columns' => $this->columnList($match[2]),
                'table' => $match[3], 'references' => $this->columnList($match[4]),
                'delete' => $this->rule($match[5]), 'update' => $this->rule($match[6] ?? 'RESTRICT')]];
        }
        if (!preg_match('/^`?([A-Za-z0-9_]+)`?\s+([A-Za-z]+)(\s*\([^()]*\))?(\s+UNSIGNED)?(.*)$/is', $sql, $match)) {
            throw new RuntimeException('A migration has an unsupported schema definition. Existing data was preserved.');
        }
        $attributes = trim($match[5]);
        $primary = preg_match('/\bPRIMARY\s+KEY\b/i', $attributes) === 1;
        $default = null;
        if (preg_match('/\bDEFAULT\s+(\x27(?:[^\x27]|\x27\x27)*\x27|CURRENT_TIMESTAMP(?:\(\))?|[-+]?\d+(?:\.\d+)?|NULL)/i', $attributes, $defaultMatch)) {
            $default = $this->normalizeDefault($defaultMatch[1], true);
        }
        return ['kind' => 'column', 'name' => $match[1], 'primary' => $primary,
            'unique' => preg_match('/\bUNIQUE\b/i', $attributes) === 1,
            'definition' => ['type' => $this->normalizeType($match[2] . ($match[3] ?? '') . ($match[4] ?? '')),
                'nullable' => !$primary && preg_match('/\bNOT\s+NULL\b/i', $attributes) !== 1,
                'default' => $default, 'auto' => preg_match('/\bAUTO_INCREMENT\b/i', $attributes) === 1]];
    }

    /** Commas inside type arguments, SQL literals or quoted identifiers are not separators. */
    private function splitClauses(string $sql): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $character = $sql[$i];
            if ($quote !== '') {
                if ($character === '\\') { $i++; continue; }
                if ($character === $quote) {
                    if (($sql[$i + 1] ?? '') === $quote) { $i++; continue; }
                    $quote = '';
                }
            } elseif ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
                if ($depth < 0) throw new RuntimeException('A migration has unbalanced schema syntax.');
            } elseif ($character === ',' && $depth === 0) {
                $parts[] = trim(substr($sql, $start, $i - $start));
                $start = $i + 1;
            }
        }
        if ($depth !== 0 || $quote !== '') throw new RuntimeException('A migration has incomplete schema syntax.');
        $parts[] = trim(substr($sql, $start));
        return $parts;
    }

    private function columnList(string $sql): array
    {
        $columns = [];
        foreach ($this->splitClauses($sql) as $column) {
            if (!preg_match('/^`?([A-Za-z0-9_]+)`?$/', $column, $match)) throw new RuntimeException('Unsupported migration index or reference column.');
            $columns[] = $match[1];
        }
        return $columns;
    }

    private function readSchema(?string $only = null): void
    {
        if ($only === null) $this->tables = [];
        else unset($this->tables[$only]);
        $suffix = $only === null ? '' : ' AND LOWER(TABLE_NAME)=LOWER(?)';
        $parameters = $only === null ? [] : [$only];
        $statement = $this->pdo->prepare('SELECT TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()' . $suffix);
        $statement->execute($parameters);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['TABLE_NAME'];
            if (!isset($this->catalog[strtolower($name)])) continue;
            if ($name !== strtolower($name)) $this->conflict($name, 'table capitalization does not match the application table name');
            if ($row['TABLE_TYPE'] !== 'BASE TABLE' || strtolower((string) $row['ENGINE']) !== 'innodb') $this->conflict($name, 'an InnoDB table is required, but a different object or engine exists');
            if (!str_starts_with(strtolower((string) $row['TABLE_COLLATION']), 'utf8mb4_')) $this->conflict($name, 'the existing table must use the application utf8mb4 character set');
            $this->tables[$name] = ['columns' => [], 'indexes' => [], 'foreign' => []];
        }
        $statement = $this->pdo->prepare('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()' . $suffix . ' ORDER BY TABLE_NAME,ORDINAL_POSITION');
        $statement->execute($parameters);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['TABLE_NAME'];
            if (!isset($this->tables[$table])) continue;
            $extra = strtolower((string) $row['EXTRA']);
            $this->tables[$table]['columns'][(string) $row['COLUMN_NAME']] = [
                'type' => $this->normalizeType((string) $row['COLUMN_TYPE']), 'nullable' => $row['IS_NULLABLE'] === 'YES',
                'default' => $this->normalizeDefault($row['COLUMN_DEFAULT']), 'auto' => str_contains($extra, 'auto_increment'),
                'generated' => trim((string) $row['GENERATION_EXPRESSION']) !== '',
                'onUpdate' => str_contains($extra, 'on update'), 'collation' => $row['COLLATION_NAME'],
            ];
        }
        $statement = $this->pdo->prepare('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SEQ_IN_INDEX,SUB_PART,INDEX_TYPE,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()' . $suffix . ' ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX');
        $statement->execute($parameters);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['TABLE_NAME'];
            if (!isset($this->tables[$table])) continue;
            $name = (string) $row['INDEX_NAME'];
            if (!isset($this->tables[$table]['indexes'][$name])) $this->tables[$table]['indexes'][$name] = ['columns' => [], 'unique' => (int) $row['NON_UNIQUE'] === 0, 'standard' => true];
            $this->tables[$table]['indexes'][$name]['columns'][] = (string) $row['COLUMN_NAME'];
            if ($row['SUB_PART'] !== null || strtoupper((string) $row['INDEX_TYPE']) !== 'BTREE' || ($row['COLLATION'] !== 'A' && $row['COLLATION'] !== null)) {
                $this->tables[$table]['indexes'][$name]['standard'] = false;
            }
        }
        $foreignSuffix = $only === null ? '' : ' AND LOWER(k.TABLE_NAME)=LOWER(?)';
        $statement = $this->pdo->prepare('SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL' . $foreignSuffix . ' ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION');
        $statement->execute($parameters);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['TABLE_NAME'];
            if (!isset($this->tables[$table])) continue;
            $name = (string) $row['CONSTRAINT_NAME'];
            if (!isset($this->tables[$table]['foreign'][$name])) {
                $this->tables[$table]['foreign'][$name] = ['columns' => [], 'table' => (string) $row['REFERENCED_TABLE_NAME'],
                    'references' => [], 'delete' => $this->rule((string) $row['DELETE_RULE']), 'update' => $this->rule((string) $row['UPDATE_RULE']),
                    'local' => $row['REFERENCED_TABLE_SCHEMA'] === $this->database];
            }
            $this->tables[$table]['foreign'][$name]['columns'][] = (string) $row['COLUMN_NAME'];
            $this->tables[$table]['foreign'][$name]['references'][] = (string) $row['REFERENCED_COLUMN_NAME'];
        }
    }

    private function validateTable(string $table, array $expected): void
    {
        if (!$this->hasTable($table)) return;
        $current = $this->tables[$table];
        foreach ($expected['baseColumns'] as $name) {
            if (!isset($current['columns'][$name])) $this->conflict($table . '.' . $name, 'a required base column is missing; restore its matching definition before continuing');
        }
        foreach ($expected['columns'] as $name => $versions) {
            if (!isset($current['columns'][$name])) continue;
            $compatible = false;
            foreach ($versions as $version) $compatible = $compatible || $this->columnMatches($current['columns'][$name], $version);
            if (!$compatible) $this->conflict($table . '.' . $name, 'the column type, default, nullability or generated behavior differs from every supported application definition');
        }
        foreach ($expected['baseIndexes'] as $name) {
            if (!isset($current['indexes'][$name])) $this->conflict($table . '.' . $name, 'a required base index is missing; restore its matching definition before continuing');
        }
        foreach ($expected['indexes'] as $name => $definition) {
            if (!isset($current['indexes'][$name])) continue;
            $index = $current['indexes'][$name];
            if (!$index['standard'] || $index['columns'] !== $definition['columns'] || $index['unique'] !== $definition['unique']) {
                $this->conflict($table . '.' . $name, 'the existing index has different columns, ordering or uniqueness');
            }
        }
        foreach ($expected['foreign'] as $name => $definition) {
            $matches = $this->matchingForeignKey($table, $name, $definition);
            if (isset($current['foreign'][$name]) && !$matches) $this->conflict($table . '.' . $name, 'the existing foreign key has different references or delete/update behavior');
            if (in_array($name, $expected['baseForeign'], true) && !$matches) $this->conflict($table . '.' . $name, 'a required base foreign key is missing; restore its matching definition before continuing');
        }
    }

    private function matchingForeignKey(string $table, string $name, array $expected): bool
    {
        $keys = $this->tables[$table]['foreign'];
        // A contradictory named constraint is never hidden by another matching one.
        if (isset($keys[$name])) $keys = [$keys[$name]];
        foreach ($keys as $key) {
            if (!$key['local']) continue;
            unset($key['local']);
            if ($key === $expected) return true;
        }
        return false;
    }

    private function columnMatches(array $current, array $expected): bool
    {
        if (($current['generated'] ?? false) || ($current['onUpdate'] ?? false)) return false;
        if (isset($current['collation']) && !str_starts_with(strtolower((string) $current['collation']), 'utf8mb4_')) return false;
        foreach (['type', 'nullable', 'default', 'auto'] as $key) if ($current[$key] !== $expected[$key]) return false;
        return true;
    }

    private function assertEnumExpansion(string $table, string $column, array $current, array $expected): void
    {
        $old = $this->enumValues($current['type']);
        $new = $this->enumValues($expected['type']);
        if ($old === [] || $new === [] || array_values(array_intersect($new, $old)) !== $old
            || $current['nullable'] !== $expected['nullable'] || $current['default'] !== $expected['default'] || $current['auto'] !== $expected['auto']) {
            $this->conflict($table . '.' . $column, 'the requested change would narrow or reinterpret existing ENUM values');
        }
    }

    private function enumValues(string $type): array
    {
        if (!preg_match('/^enum\((.*)\)$/s', $type, $match)) return [];
        return $this->splitClauses($match[1]);
    }

    private function normalizeType(string $type): string
    {
        // SQL keywords are case-insensitive; ENUM labels are application values
        // and must retain their original spelling and whitespace.
        $parts = preg_split('/(\x27(?:[^\x27]|\x27\x27)*\x27)/', trim($type), -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => &$part) {
            if ($index % 2 !== 0) continue;
            $part = strtolower($part);
            $part = preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $part);
            $part = preg_replace('/\binteger\b/', 'int', $part);
            $part = preg_replace('/\s+/', ' ', $part);
            $part = preg_replace('/\s*([(),])\s*/', '$1', $part);
        }
        unset($part);
        return implode('', $parts);
    }

    private function normalizeDefault(mixed $value, bool $fromSql = false): ?string
    {
        if ($value === null) return null;
        $value = (string) $value;
        if (($fromSql || $this->mariaDb) && str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }
        if (strtoupper($value) === 'NULL') return null;
        if (preg_match('/^current_timestamp(?:\(\))?$/i', $value)) return 'current_timestamp';
        if (preg_match('/^[-+]?\d+(?:\.\d+)?$/', $value)) {
            if (str_contains($value, '.')) $value = rtrim(rtrim($value, '0'), '.');
            $value = ltrim($value, '+');
            if ($value === '-0') $value = '0';
        }
        return $value;
    }

    private function rule(string $rule): string
    {
        $rule = strtoupper(trim($rule));
        return $rule === 'NO ACTION' ? 'RESTRICT' : $rule;
    }

    private function quote(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) throw new RuntimeException('Unsupported schema identifier.');
        return '`' . $identifier . '`';
    }

    private function conflict(string $object, string $reason): never
    {
        throw new RuntimeException('Database compatibility check failed for ' . $object . ': ' . $reason . '. Existing tables and records were preserved.');
    }
}
