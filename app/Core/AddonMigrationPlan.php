<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/** Validate the entire package before allowing its first database statement. */
final class AddonMigrationPlan
{
    /** @return array<string,array{id:string,checksum:string,up:list<string>,down:list<string>}> */
    public static function validate(string $addonKey, array $migrations, array $applied, ?PDO $pdo = null): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,189}$/', $addonKey) !== 1) {
            throw new RuntimeException('Invalid add-on migration owner.');
        }
        $plan = [];
        $declared = [];
        foreach ($migrations as $migration) {
            if (!is_array($migration)) throw new RuntimeException('Invalid add-on migration definition.');
            $id = $migration['id'] ?? null;
            $checksum = $migration['checksum'] ?? null;
            if (!is_string($id) || preg_match('/^[A-Za-z0-9_.-]{1,190}$/', $id) !== 1 || isset($plan[$id])) {
                throw new RuntimeException('Invalid or duplicate add-on migration id.');
            }
            if (!is_string($checksum) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
                throw new RuntimeException('Invalid add-on migration checksum: ' . $id);
            }
            foreach (['up', 'down'] as $direction) {
                if (!isset($migration[$direction]) || !is_array($migration[$direction]) || !array_is_list($migration[$direction]) || $migration[$direction] === []) {
                    throw new RuntimeException('Add-on migration requires explicit up and down statements: ' . $id);
                }
                foreach ($migration[$direction] as $sql) {
                    if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('Invalid add-on migration statement: ' . $id);
                }
            }
            if (isset($applied[$id]) && !hash_equals((string) $applied[$id]['checksum'], $checksum)) {
                throw new RuntimeException('Add-on migration checksum mismatch: ' . $id);
            }
            $plan[$id] = ['id' => $id, 'checksum' => $checksum, 'up' => $migration['up'], 'down' => $migration['down']];
            foreach ($migration['up'] as $sql) {
                $statement = self::statement($sql);
                if ($statement['kind'] === 'create') {
                    $table = $statement['table'];
                    if (isset($declared[$table])) throw new RuntimeException('Duplicate add-on-owned table declaration: ' . $table);
                    $declared[$table] = $id;
                }
            }
        }

        // The package must own a table through its retained CREATE migration.
        // Naming a core or pre-existing unrelated table does not confer ownership.
        $coreTables = ['migrations' => true];
        foreach (glob(root_path('database/migrations/*.php')) ?: [] as $file) {
            $definition = require $file;
            foreach ((array) ($definition['up'] ?? []) as $sql) {
                if (preg_match('/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $match)) {
                    $coreTables[strtolower($match[1])] = true;
                }
            }
        }
        foreach ($declared as $table => $id) {
            if (isset($coreTables[$table])) throw new RuntimeException('Add-on migrations cannot own a core table: ' . $table);
        }
        foreach ($plan as $migration) {
            foreach (['up', 'down'] as $direction) {
                foreach ($migration[$direction] as $sql) {
                    $statement = self::statement($sql);
                    foreach ($statement['tables'] as $table) {
                        if (!isset($declared[$table]) || isset($coreTables[$table])) {
                            throw new RuntimeException('Add-on migration references a table it does not own: ' . $table);
                        }
                    }
                }
            }
        }
        self::validateAutomaticRecovery($plan, $applied);
        if ($pdo !== null && $declared !== []) {
            if ($pdo->inTransaction()) throw new RuntimeException('Add-on DDL cannot run inside another database transaction.');
            $stmt = $pdo->query('SELECT TABLE_NAME, ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()');
            $existing = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $existing[strtolower((string) $row['TABLE_NAME'])] = (string) $row['ENGINE'];
            foreach ($declared as $table => $id) {
                if (isset($existing[$table]) && !isset($applied[$id])) {
                    throw new RuntimeException('Add-on migration cannot adopt a pre-existing table: ' . $table);
                }
                if (isset($existing[$table]) && strcasecmp($existing[$table], 'InnoDB') !== 0) {
                    throw new RuntimeException('Add-on migration recovery requires InnoDB tables: ' . $table);
                }
            }
        }
        return $plan;
    }

    /** Only reverse objects created by statements that actually completed. */
    public static function compensation(array $migration, int $completedStatements): array
    {
        $created = [];
        foreach (array_slice($migration['up'], 0, $completedStatements) as $sql) {
            $statement = self::statement($sql);
            foreach (self::schemaObjects($sql, 'up') as $object) $created[$object] = true;
            if ($statement['kind'] === 'create') $created['table:' . $statement['table']] = true;
        }
        $down = [];
        foreach ($migration['down'] as $sql) {
            $statement = self::statement($sql);
            if (isset($created['table:' . $statement['table']])) {
                $down[] = $sql;
            } elseif ($statement['kind'] === 'alter') {
                $clauses = [];
                foreach (self::alterClauses($sql) as $clause) {
                    $candidate = 'ALTER TABLE `' . $statement['table'] . '` ' . $clause;
                    $objects = self::schemaObjects($candidate, 'down');
                    if ($objects !== [] && isset($created[$objects[0]])) $clauses[] = $clause;
                }
                if ($clauses !== []) $down[] = 'ALTER TABLE `' . $statement['table'] . '` ' . implode(', ', $clauses);
            } else {
                $objects = self::schemaObjects($sql, 'down');
                if ($objects !== [] && isset($created[$objects[0]])) $down[] = $sql;
            }
        }
        return array_merge($migration, ['down' => $down]);
    }

    /** A concurrent unrelated CREATE must fail, never be silently adopted. */
    public static function executionSql(string $sql): string
    {
        return preg_replace('/^(\s*CREATE\s+TABLE\s+)IF\s+NOT\s+EXISTS\s+/i', '$1', $sql) ?? $sql;
    }

    /**
     * Automatic failure recovery is deliberately bounded: a new migration can
     * create its own table or add named schema objects, and its down SQL must
     * remove exactly those objects. Rewriting retained rows or narrowing an
     * existing column requires a separate reviewed database migration/backup.
     */
    private static function validateAutomaticRecovery(array $plan, array $applied): void
    {
        foreach ($plan as $id => $migration) {
            if (isset($applied[$id])) continue;
            $createdTables = [];
            foreach ($migration['up'] as $sql) {
                $statement = self::statement($sql);
                if ($statement['kind'] === 'create') {
                    if (preg_match('/\bENGINE\s*=\s*InnoDB\b/i', self::withoutLiterals($sql)) !== 1) {
                        throw new RuntimeException('New add-on tables must explicitly declare ENGINE=InnoDB: ' . $id);
                    }
                    $createdTables[$statement['table']] = true;
                }
            }
            $created = []; $removed = [];
            foreach ($migration['up'] as $sql) {
                $statement = self::statement($sql);
                $table = $statement['table'];
                if (isset($createdTables[$table])) { $created['table:' . $table] = true; continue; }
                $objects = self::schemaObjects($sql, 'up');
                if ($objects === []) {
                    throw new RuntimeException('Automatic add-on migration recovery supports additive schema changes only; existing data was preserved: ' . $id);
                }
                foreach ($objects as $object) {
                    if (isset($created[$object])) throw new RuntimeException('Duplicate additive schema object in add-on migration: ' . $object);
                    $created[$object] = true;
                }
            }
            foreach ($migration['down'] as $sql) {
                $statement = self::statement($sql);
                $table = $statement['table'];
                if (isset($createdTables[$table])) {
                    if ($statement['kind'] === 'drop') $removed['table:' . $table] = true;
                    continue;
                }
                $objects = self::schemaObjects($sql, 'down');
                if ($objects === []) throw new RuntimeException('Down SQL must reverse only this migration\'s added objects: ' . $id);
                foreach ($objects as $object) {
                    if (!isset($created[$object]) || isset($removed[$object])) {
                        throw new RuntimeException('Down SQL would alter a pre-existing or unrelated add-on object: ' . $object);
                    }
                    $removed[$object] = true;
                }
            }
            if (array_diff_key($created, $removed) !== []) throw new RuntimeException('Down SQL does not reverse every added schema object: ' . $id);
        }
    }

    /** @return list<string> */
    private static function schemaObjects(string $sql, string $direction): array
    {
        $statement = self::statement($sql);
        $table = $statement['table'];
        if (($direction === 'up' && $statement['kind'] === 'create') || ($direction === 'down' && $statement['kind'] === 'drop')) return ['table:' . $table];
        $identifier = '`?([A-Za-z_][A-Za-z0-9_]*)`?';
        if ($statement['kind'] === 'index') {
            $pattern = $direction === 'up' ? '/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+' . $identifier . '\s+ON\s+/i' : '/^DROP\s+INDEX\s+' . $identifier . '\s+ON\s+/i';
            if (preg_match($pattern, self::withoutLiterals($sql), $match)) return ['index:' . $table . ':' . strtolower($match[1])];
            return [];
        }
        if ($statement['kind'] !== 'alter') return [];
        $objects = [];
        foreach (self::alterClauses($sql) as $clause) {
            $plain = self::withoutLiterals($clause);
            $patterns = $direction === 'up' ? [
                'foreign' => '/^ADD\s+CONSTRAINT\s+' . $identifier . '\s+FOREIGN\s+KEY\b/i',
                'index' => '/^ADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+' . $identifier . '\s*\(/i',
                'column' => '/^ADD\s+(?:COLUMN\s+)?' . $identifier . '\s+(?!KEY\b)[A-Za-z]+\b/i',
            ] : [
                'foreign' => '/^DROP\s+FOREIGN\s+KEY\s+' . $identifier . '\s*$/i',
                'index' => '/^DROP\s+(?:KEY|INDEX)\s+' . $identifier . '\s*$/i',
                'column' => '/^DROP\s+(?:COLUMN\s+)?' . $identifier . '\s*$/i',
            ];
            $object = null;
            foreach ($patterns as $kind => $pattern) {
                if (preg_match($pattern, $plain, $match)) {
                    if ($kind === 'column' && in_array(strtolower($match[1]), ['constraint', 'primary', 'unique', 'key', 'index', 'foreign', 'check'], true)) continue;
                    $object = $kind . ':' . $table . ':' . strtolower($match[1]); break;
                }
            }
            if ($object === null) return [];
            $objects[] = $object;
        }
        return $objects;
    }

    /** Split ALTER clauses without splitting type arguments or literal values. */
    private static function alterClauses(string $sql): array
    {
        if (!preg_match('/^\s*ALTER\s+TABLE\s+`?[A-Za-z_][A-Za-z0-9_]*`?\s+(.+?)\s*;?\s*$/is', $sql, $match)) return [];
        $body = $match[1]; $parts = []; $start = 0; $depth = 0; $quote = null;
        for ($i = 0, $length = strlen($body); $i < $length; $i++) {
            $char = $body[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    if ($quote === "'" && ($body[$i + 1] ?? '') === "'") { $i++; continue; }
                    $quote = null;
                }
            } elseif ($char === "'" || $char === '`') { $quote = $char; }
            elseif ($char === '(') { $depth++; }
            elseif ($char === ')') { $depth--; }
            elseif ($char === ',' && $depth === 0) { $parts[] = trim(substr($body, $start, $i - $start)); $start = $i + 1; }
        }
        $parts[] = trim(substr($body, $start));
        return $parts;
    }

    /** @return array{kind:string,table:string,tables:list<string>} */
    private static function statement(string $sql): array
    {
        $plain = self::withoutLiterals($sql);
        $identifier = '`?([A-Za-z_][A-Za-z0-9_]*)`?';
        if (preg_match('/\b(?:SELECT|CALL|PREPARE|EXECUTE|TRIGGER|PROCEDURE|FUNCTION|VIEW|RENAME|TRUNCATE|LOAD|OUTFILE|INFILE|GRANT|REVOKE|TEMPORARY|TABLESPACE|DIRECTORY|JOIN|USING)\b/i', $plain)
            || preg_match('/[A-Za-z_`]\s*\./', $plain) || str_contains($plain, '@')) {
            throw new RuntimeException('Add-on migrations support only single statements on their own declared tables.');
        }
        if (preg_match('/\bENGINE\s*=\s*(?!InnoDB\b)[A-Za-z0-9_]+/i', $plain)) {
            throw new RuntimeException('Add-on migrations require InnoDB tables.');
        }
        $kind = '';
        $table = '';
        foreach ([
            'create' => '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . $identifier . '\s*\(/i',
            'alter' => '/^ALTER\s+TABLE\s+' . $identifier . '\s+(?:ADD|DROP|MODIFY|CHANGE|ALTER)\b/i',
            'drop' => '/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?' . $identifier . '\s*$/i',
            'insert' => '/^INSERT\s+INTO\s+' . $identifier . '\s*(?:\([^)]*\)\s*)?VALUES\s*\(/i',
            'update' => '/^UPDATE\s+' . $identifier . '\s+SET\s+/i',
            'delete' => '/^DELETE\s+FROM\s+' . $identifier . '(?:\s+WHERE\b|\s*$)/i',
            'index' => '/^(?:CREATE\s+(?:UNIQUE\s+)?INDEX\s+`?[A-Za-z_][A-Za-z0-9_]*`?|DROP\s+INDEX\s+`?[A-Za-z_][A-Za-z0-9_]*`?)\s+ON\s+' . $identifier . '\b/i',
        ] as $candidate => $pattern) {
            if (preg_match($pattern, $plain, $match)) {
                $kind = $candidate; $table = strtolower($match[1]); break;
            }
        }
        if ($table === '') throw new RuntimeException('Unsupported add-on migration statement. Use explicit owned-table SQL.');
        $tables = [$table];
        if (preg_match_all('/\bREFERENCES\s+' . $identifier . '\s*\(/i', $plain, $matches)) {
            foreach ($matches[1] as $reference) $tables[] = strtolower($reference);
        }
        return ['kind' => $kind, 'table' => $table, 'tables' => array_values(array_unique($tables))];
    }

    /** Ignore SQL values without accepting comments, multiple statements or SQL-mode-dependent quoting. */
    private static function withoutLiterals(string $sql): string
    {
        $sql = trim($sql);
        $plain = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === "'") {
                $closed = false;
                for ($i++; $i < $length; $i++) {
                    if ($sql[$i] === '\\') throw new RuntimeException('Use doubled quotes in add-on SQL values; backslash escaping is SQL-mode dependent.');
                    if ($sql[$i] !== "'") continue;
                    if ($i + 1 < $length && $sql[$i + 1] === "'") { $i++; continue; }
                    $closed = true; break;
                }
                if (!$closed) throw new RuntimeException('Unclosed add-on SQL value.');
                $plain .= "''";
            } elseif ($char === '`') {
                $end = strpos($sql, '`', $i + 1);
                if ($end === false || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', substr($sql, $i + 1, $end - $i - 1)) !== 1) {
                    throw new RuntimeException('Unsafe add-on SQL identifier.');
                }
                $plain .= substr($sql, $i, $end - $i + 1); $i = $end;
            } else {
                if ($char === ';') {
                    if ($i !== $length - 1) throw new RuntimeException('Each add-on SQL entry must contain exactly one statement.');
                    continue;
                }
                if ($char === "\0" || $char === '"' || $char === '#' || $char === '\\'
                    || ($char === '-' && ($sql[$i + 1] ?? '') === '-')
                    || ($char === '/' && ($sql[$i + 1] ?? '') === '*')) {
                    throw new RuntimeException('Comments and ambiguous quoting are not allowed in add-on migration SQL.');
                }
                $plain .= $char;
            }
        }
        return trim($plain);
    }
}
