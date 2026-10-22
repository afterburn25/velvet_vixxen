<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

/** Restores only into a reviewed, empty, separate target; never overwrites live data. */
final class BackupRestore
{
    private readonly string $source;
    public function __construct(private readonly Config $config, ?string $root = null)
    {
        $this->source = OperationsFiles::root($root ?? dirname($config->path(), 2));
        OperationsGate::assertNotQuarantined($this->source);
    }

    public function review(string $archive, string $keyFile, string $target, ?string $databaseFile = null): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Restore review is available only in the hosting CLI.', 403);
        $this->config->reload();
        $target = $this->target($target); $key = OperationsFiles::key($keyFile, $this->source);
        try { $audit = $this->audit($archive, $key); }
        finally { sodium_memzero($key); }
        $binding = $this->databaseTarget($audit['header']['storage_mode'], $databaseFile);
        if ($audit['header']['storage_mode'] === 'mysql' && self::engine($audit['manifest']['database']['server']) !== $binding['engine']) throw new RuntimeException('Restore requires the same supported database engine family as the archive.');
        $review = ['schema'=>1,'archive_sha256'=>$audit['archive_sha256'],'archive_bytes'=>$audit['archive_bytes'],
            'backup_id'=>$audit['header']['id'],'installation_hash'=>$audit['header']['installation_hash'],
            'source_root_sha256'=>hash('sha256',$this->source),'source_config_sha256'=>$this->config->revision(),'target'=>$target,'target_database'=>$binding['identity'],
            'target_config_sha256'=>$binding['config_sha256'],'source_database_server'=>$audit['manifest']['database']['server'] ?? null,
            'target_database_server'=>$binding['server'],'target_database_engine'=>$binding['engine'],'files'=>$audit['files'],'directories'=>$audit['directories'],
            'database_tables'=>count($audit['manifest']['database']['tables'] ?? []),'database_rows'=>$audit['manifest']['database']['rows'] ?? 0,
            'restored_bytes'=>$audit['manifest']['bytes'],'inventory_sha256'=>$audit['manifest']['inventory_sha256'],
            'quarantine_required'=>true,'excluded'=>$audit['header']['excluded']];
        return $review + ['plan'=>hash('sha256', BackupArchive::json($review)),
            'activation'=>'The restored copy stays quarantined. Review connection, URL, SQL storage authority, sessions and provider credentials before any live activation.'];
    }

    public function apply(string $archive, string $keyFile, string $target, ?string $databaseFile, string $plan, bool $confirmed): array
    {
        if (PHP_SAPI !== 'cli' || !$confirmed) throw new RuntimeException('Review the exact restore plan and explicitly confirm its empty isolated target.', 403);
        // Keep the declared source connection fixed throughout the drill while
        // ordinary requests can continue. No configuration values are changed.
        return $this->config->update(function (array &$values) use ($archive,$keyFile,$target,$databaseFile,$plan,$confirmed): array {
            return $this->applyReviewed($archive,$keyFile,$target,$databaseFile,$plan,$confirmed);
        });
    }
    private function applyReviewed(string $archive, string $keyFile, string $target, ?string $databaseFile, string $plan, bool $confirmed): array
    {
        if (PHP_SAPI !== 'cli' || !$confirmed) throw new RuntimeException('Review the exact restore plan and explicitly confirm its empty isolated target.', 403);
        $review = $this->review($archive, $keyFile, $target, $databaseFile);
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $plan) || !hash_equals($review['plan'], $plan)) throw new RuntimeException('The restore inputs or target changed after review. Generate and inspect a new plan.', 409);
        $target = $review['target']; $binding = $this->databaseTarget($review['database_tables'] > 0 ? 'mysql' : 'file', $databaseFile);
        $key = OperationsFiles::key($keyFile, $this->source); $pdo = $binding['pdo'];
        $schemas = []; $rows = []; $files = []; $directories = []; $sink = null; $buffer = ''; $statement = null; $current = null;
        $quarantine = ['schema'=>1,'plan'=>$plan,'backup_id'=>$review['backup_id'],'archive_sha256'=>$review['archive_sha256'],
            'created_at'=>gmdate('c'),'status'=>'restoring','target_database'=>$binding['identity']];
        try {
            // Recheck immediately before the first mutation. Existing target
            // contents, including a previous failed drill, are never cleared.
            $this->target($target); $this->databaseTarget($review['database_tables'] > 0 ? 'mysql' : 'file', $databaseFile);
            OperationsFiles::directory($target, true);
            OperationsFiles::writeNew($target . '/.vvx-restore-quarantine.json', BackupArchive::json($quarantine) . "\n");
            if ($pdo !== null) {
                $pdo->exec("SET SESSION time_zone='+00:00'"); $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS=0');
                $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION'");
            }
            $consumeRows = static function (string $bytes, bool $end = false) use (&$buffer, &$current, &$schemas, &$rows, &$statement): void {
                $buffer .= $bytes;
                while (($at = strpos($buffer, "\n")) !== false) {
                    $encoded = substr($buffer, 0, $at); $buffer = substr($buffer, $at + 1); $schema = $schemas[$current['path']];
                    $row = BackupDatabase::decodeRow($encoded, $schema); $values = [];
                    foreach ($schema['insert_columns'] as $column) $values[] = $row[array_search($column, $schema['columns'], true)];
                    foreach ($values as $index => $value) $statement->bindValue($index + 1, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $statement->execute(); BackupDatabase::addDigest($rows[$current['path']]['sum'], $encoded); $rows[$current['path']]['count']++;
                }
                if (strlen($buffer) > BackupDatabase::MAX_ROW_BYTES || ($end && $buffer !== '')) throw new RuntimeException('An archived row is too large or incomplete. The restore stays quarantined.');
            };
            $scan = BackupArchive::scan($archive, $key, function (string $event, array $entry, string $data) use ($target, $pdo, &$schemas, &$rows, &$files, &$directories, &$sink, &$buffer, &$statement, &$current, $consumeRows): void {
                if ($event === 'header') return;
                if ($event === 'begin') {
                    $current = $entry; $buffer = '';
                    if (in_array($entry['kind'], ['file','directory'], true)) {
                        $path = $target . '/' . $entry['path']; OperationsFiles::noLinks($path);
                        if (file_exists($path)) throw new RuntimeException('A restored path already exists. No existing bytes were overwritten.');
                        $parent = dirname($path);
                        if ($parent !== $target && !isset($directories[substr($parent, strlen($target) + 1)])) throw new RuntimeException('The archive has an undeclared parent directory.');
                        if ($entry['kind'] === 'directory') {
                            if (!mkdir($path, 0700)) throw new RuntimeException('A restore directory could not be created.');
                            $directories[$entry['path']] = $entry['mode'];
                        } else {
                            $sink = fopen($path, 'xb');
                            if ($sink === false || !chmod($path, 0600)) throw new RuntimeException('A restore file could not be created privately.');
                        }
                    } elseif ($entry['kind'] === 'schema') {
                        if ($pdo === null || isset($schemas[$entry['path']])) throw new RuntimeException('The archive database schema sequence is invalid.');
                    } else {
                        if ($pdo === null || !isset($schemas[$entry['path']]) || isset($rows[$entry['path']])) throw new RuntimeException('The archive database row sequence is invalid.');
                        $schema = $schemas[$entry['path']];
                        $statement = $pdo->prepare('INSERT INTO ' . BackupDatabase::identifier($entry['path']) . ' ('
                            . implode(',', array_map(BackupDatabase::identifier(...), $schema['insert_columns'])) . ') VALUES (' . implode(',', array_fill(0,count($schema['insert_columns']),'?')) . ')');
                        $rows[$entry['path']] = ['count'=>0,'sum'=>str_repeat("\0",32)];
                        $pdo->beginTransaction();
                    }
                } elseif ($event === 'data') {
                    if ($entry['kind'] === 'file') OperationsFiles::writeAll($sink, $data);
                    elseif ($entry['kind'] === 'schema') { $buffer .= $data; if (strlen($buffer) > BackupArchive::MAX_METADATA) throw new RuntimeException('The archived schema exceeds its supported boundary.'); }
                    elseif ($entry['kind'] === 'rows') $consumeRows($data);
                } elseif ($event === 'end') {
                    if ($entry['kind'] === 'file') {
                        if (!fflush($sink) || (function_exists('fsync') && !fsync($sink))) throw new RuntimeException('A restored file could not be flushed.');
                        fclose($sink); $sink = null;
                        $path = $target . '/' . $entry['path'];
                        if (filesize($path) !== $entry['bytes'] || !hash_equals($entry['sha256'], (string) hash_file('sha256',$path))) throw new RuntimeException('A restored file failed its independent read-back checksum.');
                        // Keep owner read/write; retain execution when the original
                        // required it, but never restore group/world write bits.
                        if (!chmod($path, ($entry['mode'] & 0755) | 0600)) throw new RuntimeException('Restored file permissions could not be applied.');
                        $files[$entry['path']] = ['bytes'=>$entry['bytes'],'sha256'=>$entry['sha256']];
                    } elseif ($entry['kind'] === 'schema') {
                        $decoded = json_decode($buffer, true, 16, JSON_THROW_ON_ERROR);
                        if (!is_array($decoded)) throw new RuntimeException('The archived schema is invalid.');
                        $schema = BackupDatabase::schema($decoded);
                        if ($schema['table'] !== $entry['path']) throw new RuntimeException('The archived schema table name changed.');
                        // Native preparation accepts a single CREATE statement;
                        // multiple SQL statements and cross-database storage are refused.
                        $pdo->prepare($schema['create_sql'])->execute(); $schemas[$entry['path']] = $schema;
                    } elseif ($entry['kind'] === 'rows') {
                        $consumeRows('', true); $pdo->commit(); $statement = null;
                    }
                }
            });
            BackupJobs::manifest($scan['manifest']);
            if (!hash_equals($review['archive_sha256'],$scan['archive_sha256']) || $scan['manifest']['inventory_sha256'] !== $review['inventory_sha256']
                || count($files) !== $review['files'] || count($directories) !== $review['directories']) throw new RuntimeException('The archive changed after review. The target stays quarantined.');
            if ($pdo !== null) {
                $expected = $scan['manifest']['database']['tables'];
                if (array_keys($schemas) !== array_keys($rows) || array_keys($schemas) !== array_keys($expected)) throw new RuntimeException('The archived database coverage is incomplete.');
                foreach ($rows as $name => $row) if ($expected[$name] !== ['rows'=>$row['count'],'digest'=>bin2hex($row['sum'])]) throw new RuntimeException('An archived table failed its complete row manifest.');
                BackupDatabase::verifyRestored($pdo,$schemas,$expected);
                $actual = BackupDatabase::catalogue($pdo);
                if (!BackupDatabase::equivalentSchemas($schemas,$actual)) throw new RuntimeException('The restored database schema differs from the authenticated definitions.');
                $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS=1');
                $this->foreignKeys($pdo);
            }
            // A second pass reads every literal restored file, after all writes.
            foreach ($files as $relative => $expected) {
                $path = $target . '/' . $relative; OperationsFiles::noLinks($path);
                if (filesize($path) !== $expected['bytes'] || !hash_equals($expected['sha256'], (string) hash_file('sha256',$path))) throw new RuntimeException('The restored filesystem changed during final verification.');
            }
            $expectedPaths = array_merge(array_keys($files),array_keys($directories),['.vvx-restore-quarantine.json']); sort($expectedPaths,SORT_STRING);
            $actualPaths = [];
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
            foreach ($walk as $item) {
                $absolute = $item->getPathname(); OperationsFiles::noLinks($absolute); $stat = lstat($absolute);
                if ($stat === false || !in_array($stat['mode'] & 0170000,[0040000,0100000],true)
                    || (($stat['mode'] & 0170000)===0100000 && $stat['nlink']!==1)) throw new RuntimeException('An unexpected linked or special path entered the restore target.');
                $actualPaths[] = substr($absolute,strlen($target)+1);
                if (count($actualPaths)>OperationsFiles::MAX_ENTRIES+1) throw new RuntimeException('Unexpected paths exceeded the isolated restore boundary.');
            }
            sort($actualPaths,SORT_STRING);
            if ($actualPaths!==$expectedPaths) throw new RuntimeException('Unexpected files entered the isolated target. It remains quarantined.');
            foreach (array_reverse($directories, true) as $relative => $mode) if (!chmod($target . '/' . $relative, ($mode & 0755) | 0700)) throw new RuntimeException('Restored directory permissions could not be applied.');
            $quarantine['status'] = 'verified'; $quarantine['verified_at'] = gmdate('c');
            $quarantine['files'] = count($files); $quarantine['database_tables'] = count($schemas); $quarantine['database_rows'] = $review['database_rows'];
            OperationsFiles::replace($target . '/.vvx-restore-quarantine.json',BackupArchive::json($quarantine)."\n");
            return ['verified'=>true,'quarantined'=>true,'plan'=>$plan,'archive_sha256'=>$scan['archive_sha256'],'target'=>$target,
                'files'=>count($files),'directories'=>count($directories),'database_tables'=>count($schemas),'database_rows'=>$review['database_rows'],'source_database_server'=>$review['source_database_server'],
                'target_database_server'=>$review['target_database_server'],'target_database_engine'=>$review['target_database_engine'],
                'inventory_sha256'=>$scan['manifest']['inventory_sha256'],'activation'=>$review['activation']];
        } finally {
            if (is_resource($sink)) fclose($sink);
            if ($pdo !== null && $pdo->inTransaction()) $pdo->rollBack();
            if ($pdo !== null) { try { $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS=1'); } catch (Throwable) {} }
            sodium_memzero($key);
        }
    }

    /** Authenticate all payloads and validate the complete semantic row inventory. */
    private function audit(string $archive, string $key): array
    {
        $files = 0; $directories = []; $schemas = []; $rows = []; $buffer = ''; $current = null; $hasConfig = false; $hasLock = false;
        $consumeRows = static function (string $bytes, bool $end = false) use (&$buffer,&$current,&$schemas,&$rows): void {
            $buffer .= $bytes;
            while (($at = strpos($buffer,"\n")) !== false) {
                $encoded = substr($buffer,0,$at); $buffer = substr($buffer,$at+1);
                BackupDatabase::decodeRow($encoded,$schemas[$current['path']]); BackupDatabase::addDigest($rows[$current['path']]['sum'],$encoded); $rows[$current['path']]['count']++;
            }
            if (strlen($buffer) > BackupDatabase::MAX_ROW_BYTES || ($end && $buffer !== '')) throw new RuntimeException('The encrypted archive has an oversized or incomplete database row.');
        };
        $scan = BackupArchive::scan($archive,$key,function(string $event,array $entry,string $data) use (&$files,&$directories,&$schemas,&$rows,&$buffer,&$current,&$hasConfig,&$hasLock,$consumeRows): void {
            if ($event === 'header') return;
            if ($event === 'begin') {
                $current = $entry; $buffer = '';
                if (in_array($entry['kind'],['file','directory'],true)) {
                    $parent = dirname($entry['path']);
                    if ($parent !== '.' && !isset($directories[$parent])) throw new RuntimeException('The archive has an undeclared parent directory.');
                    if ($entry['path'] === '.vvx-restore-quarantine.json') throw new RuntimeException('A previously quarantined copy cannot be used as a production restore source.');
                    if ($entry['kind'] === 'directory') $directories[$entry['path']] = true;
                    else { $files++; $hasConfig = $hasConfig || $entry['path'] === 'config/app.php'; $hasLock = $hasLock || $entry['path'] === 'storage/install.lock'; }
                } elseif ($entry['kind'] === 'schema') {
                    if (isset($schemas[$entry['path']])) throw new RuntimeException('A database schema is duplicated.');
                } else {
                    if (!isset($schemas[$entry['path']]) || isset($rows[$entry['path']])) throw new RuntimeException('The archive database row order is invalid.');
                    $rows[$entry['path']] = ['count'=>0,'sum'=>str_repeat("\0",32)];
                }
            } elseif ($event === 'data') {
                if ($entry['kind'] === 'schema') { $buffer .= $data; if (strlen($buffer) > BackupArchive::MAX_METADATA) throw new RuntimeException('An archived schema is too large.'); }
                elseif ($entry['kind'] === 'rows') $consumeRows($data);
            } elseif ($event === 'end') {
                if ($entry['kind'] === 'schema') {
                    $decoded = json_decode($buffer,true,16,JSON_THROW_ON_ERROR); if (!is_array($decoded)) throw new RuntimeException('An archived schema is invalid.');
                    $schema = BackupDatabase::schema($decoded); if ($schema['table'] !== $entry['path']) throw new RuntimeException('An archived schema does not match its table.'); $schemas[$entry['path']] = $schema;
                } elseif ($entry['kind'] === 'rows') $consumeRows('',true);
            }
        });
        BackupJobs::manifest($scan['manifest']); $expected = $scan['manifest']['database']['tables'] ?? [];
        if (!$hasConfig || !$hasLock || array_keys($schemas) !== array_keys($rows) || array_keys($schemas) !== array_keys($expected)
            || ($scan['header']['storage_mode'] === 'mysql') !== ($expected !== [])) throw new RuntimeException('The archive lacks complete installation or database coverage.');
        foreach ($rows as $name => $row) if ($expected[$name] !== ['rows'=>$row['count'],'digest'=>bin2hex($row['sum'])]) throw new RuntimeException('The archive row manifest is inconsistent.');
        return $scan + ['files'=>$files,'directories'=>count($directories)];
    }

    private function target(string $target): string
    {
        $target = rtrim($target,'/'); OperationsFiles::noLinks($target);
        if ($target === '' || $target === $this->source || str_starts_with($target,$this->source.'/') || str_starts_with($this->source,$target.'/')) throw new RuntimeException('The restore target must be outside the live installation and its ancestors.');
        $parent = realpath(dirname($target));
        if ($parent === false || $parent !== dirname($target) || !is_writable($parent)) throw new RuntimeException('Choose an existing writable canonical parent directory for the isolated target.');
        if (file_exists($target) && (!is_dir($target) || scandir($target) !== ['.','..'])) throw new RuntimeException('The restore target must be empty. Existing files, including failed drills, are never overwritten.');
        return $target;
    }

    private function databaseTarget(string $mode, ?string $file): array
    {
        if ($mode === 'file') {
            if ($file !== null) throw new RuntimeException('A file-mode archive does not need a target database configuration.');
            return ['pdo'=>null,'identity'=>null,'config_sha256'=>null,'server'=>null,'engine'=>null];
        }
        if ($file === null) throw new RuntimeException('A MySQL archive needs a private target database JSON file for a separate empty schema.');
        OperationsFiles::noLinks($file); $stat = @lstat($file);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || ($stat['mode'] & 0077) !== 0
            || $stat['size'] < 2 || $stat['size'] > 16384 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) throw new RuntimeException('The target database JSON file must be private, owned by this process and at most 16 KiB.');
        $bytes = file_get_contents($file); $values = json_decode($bytes,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($values) || !is_string($values['name'] ?? null) || !is_string($values['user'] ?? null)) throw new RuntimeException('The target database JSON file is incomplete.');
        $pdo = Database::connect($values); $identity = $this->databaseIdentity($pdo);
        if ($this->config->get('storage_mode','mysql') === 'mysql') {
            $sourceName = $this->config->get('db.name');
            if (!is_string($sourceName) || $sourceName === '') throw new RuntimeException('The source database name must be declared before reviewing a separate restore.');
            if ($sourceName === (string)$pdo->query('SELECT DATABASE()')->fetchColumn()) throw new RuntimeException('The target database uses the current live database name. No restore was attempted.');
        }
        if ($pdo->query('SHOW FULL TABLES')->fetch() !== false || $pdo->query('SHOW TRIGGERS')->fetch() !== false
            || (int)$pdo->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn() !== 0
            || (int)$pdo->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn() !== 0) throw new RuntimeException('The isolated target database must be empty of tables, views, triggers, routines and events.');
        return ['pdo'=>$pdo,'identity'=>$identity,'config_sha256'=>hash('sha256',$bytes),'server'=>(string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'engine'=>self::engine((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION))];
    }
    private function databaseIdentity(PDO $pdo): string
    {
        $server = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION); $engine = self::engine($server);
        if ($engine === 'mysql8') {
            $row = $pdo->query('SELECT @@server_uuid AS server_id, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
            if (!is_string($row['server_id'] ?? null) || $row['server_id'] === '' || !is_string($row['database_name'] ?? null)) throw new RuntimeException('The MySQL target has no stable server identity.');
            return hash('sha256',BackupArchive::json([$engine,$row['server_id'],$row['database_name']]));
        }
        // MariaDB is positively identified by its actual version, then binds
        // all available server identity fields. Same schema names are refused
        // above even on another hostname, preventing an alias from bypassing
        // the separate-database rule when no global UUID is available.
        $row = $pdo->query('SELECT @@hostname AS server_host, @@port AS server_port, @@server_id AS server_id, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
        if (!is_string($row['server_host'] ?? null) || $row['server_host'] === '' || !is_string($row['database_name'] ?? null)) throw new RuntimeException('The MariaDB target identity could not be verified.');
        return hash('sha256',BackupArchive::json([$engine,$row['server_host'],(string)$row['server_port'],(string)$row['server_id'],$row['database_name']]));
    }
    private static function engine(string $server): string
    {
        if (preg_match('/(?:^|-)10\.11\.[0-9]+.*MariaDB/i',$server)) return 'mariadb10.11';
        if (preg_match('/^8\.(?:0|4)\.[0-9]+/',$server) && stripos($server,'MariaDB')===false) return 'mysql8';
        throw new RuntimeException('Logical restore supports MySQL 8.0/8.4 or MariaDB 10.11 using the same engine family. Use the hosting backup service for other engines.');
    }

    private function foreignKeys(PDO $pdo): void
    {
        $query = $pdo->query('SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION');
        $groups = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $groups[$row['TABLE_NAME'].'|'.$row['CONSTRAINT_NAME']][] = $row;
        foreach ($groups as $parts) {
            $left = []; $right = [];
            foreach ($parts as $part) {
                $child = 'c.'.BackupDatabase::identifier($part['COLUMN_NAME']); $parent = 'p.'.BackupDatabase::identifier($part['REFERENCED_COLUMN_NAME']);
                $left[] = $child.' IS NOT NULL'; $right[] = $parent.'='.$child;
            }
            $sql = 'SELECT 1 FROM '.BackupDatabase::identifier($parts[0]['TABLE_NAME']).' c WHERE '.implode(' AND ',$left)
                .' AND NOT EXISTS (SELECT 1 FROM '.BackupDatabase::identifier($parts[0]['REFERENCED_TABLE_NAME']).' p WHERE '.implode(' AND ',$right).') LIMIT 1';
            if ($pdo->query($sql)->fetchColumn() !== false) throw new RuntimeException('A restored foreign key has missing parent rows. The target stays quarantined.');
        }
    }
}
