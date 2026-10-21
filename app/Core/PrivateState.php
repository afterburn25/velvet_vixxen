<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

/** Small private operational journals. Reads never initialize storage. */
final class PrivateState
{
    private const MAX_BYTES = 67108864;
    private const LOCK_SECONDS = 5;
    /** Nested updates, including through a second instance, cannot commit atomically. */
    private static array $updating = [];
    private readonly string $root;

    public function __construct(private readonly Config $config, ?string $root = null)
    {
        $path = $root ?? dirname($config->path(), 2);
        $real = realpath($path);
        if ($real === false || !is_dir($real) || is_link(rtrim($path, '/\\'))) throw new RuntimeException('A real installation directory is required for private storage.');
        $this->root = rtrim($real, '/');
    }

    public function read(string $namespace): array
    {
        $this->validateNamespace($namespace);
        $context = $this->context();
        if ($context['mode'] === 'file') return $this->fileSnapshot($namespace)[0];
        $pdo = $this->database($context);
        $statement = $pdo->prepare('SELECT payload_json, revision FROM private_state WHERE namespace=?');
        $statement->execute([$namespace]);
        $row = $statement->fetch();
        if ($row === false) return [];
        $this->revision($row['revision']);
        return $this->decode($namespace, $row['payload_json']);
    }

    /** The callback may edit the supplied fresh array; exceptions roll it back. */
    public function update(string $namespace, callable $mutation): mixed
    {
        $this->validateNamespace($namespace);
        $context = $this->context();
        $key = $context['lock_key'];
        if (isset(self::$updating[$key])) throw new RuntimeException('Nested private-state updates are not supported. Finish the current update first.', 409);
        self::$updating[$key] = true;
        try {
            return $context['mode'] === 'file'
                ? $this->updateFile($namespace, $mutation, $context)
                : $this->updateSql($namespace, $mutation, $context);
        } finally { unset(self::$updating[$key]); }
    }

    private function updateFile(string $namespace, callable $mutation, array $context): mixed
    {
        $directory = $this->directory(true);
        $lockPath = $directory . '/' . $namespace . '.lock';
        $this->regularFile($lockPath, true);
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) throw new RuntimeException('Private storage cannot acquire its write lock.', 503);
        try {
            $this->sameFile($lockPath, $lock);
            $this->privateMode($lockPath, false);
            $deadline = microtime(true) + self::LOCK_SECONDS;
            do {
                if (flock($lock, LOCK_EX | LOCK_NB)) { $locked = true; break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if (empty($locked)) throw new RuntimeException('Another private-state update is finishing. Try again shortly.', 503);
            $this->sameFile($lockPath, $lock);
            [$data, $digest] = $this->fileSnapshot($namespace);
            $before = $data;
            $result = $mutation($data);
            $this->unchangedContext($context);
            if ($data !== $before) {
                if (!is_array($data)) throw new RuntimeException('A private-state mutation must preserve an array.');
                $bytes = $this->encode($namespace, $data);
                $path = $directory . '/' . $namespace . '.json';
                $temporary = $directory . '/.' . $namespace . '-' . bin2hex(random_bytes(12)) . '.tmp';
                $handle = @fopen($temporary, 'x+b');
                if ($handle === false) throw new RuntimeException('Private storage cannot create a checked temporary file.', 503);
                try {
                    $this->sameFile($temporary, $handle);
                    $this->privateMode($temporary, false);
                    $offset = 0;
                    while ($offset < strlen($bytes)) {
                        $written = fwrite($handle, substr($bytes, $offset));
                        if ($written === false || $written === 0) throw new RuntimeException('Private storage could not write all requested bytes.', 503);
                        $offset += $written;
                    }
                    if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('Private storage could not flush its requested bytes.', 503);
                    rewind($handle);
                    if (stream_get_contents($handle) !== $bytes) throw new RuntimeException('Private storage failed temporary-file verification.', 503);
                    $this->directory(false);
                    $this->sameFile($lockPath, $lock);
                    $this->sameFile($temporary, $handle);
                    if ($this->fileSnapshot($namespace)[1] !== $digest) throw new RuntimeException('Private state changed outside its write lock. The conflicting data was preserved.', 409);
                    if (!@rename($temporary, $path)) throw new RuntimeException('Private storage could not activate its checked write.', 503);
                    $this->sameFile($path, $handle);
                } finally {
                    fclose($handle);
                    if (is_file($temporary) && !is_link($temporary)) @unlink($temporary);
                }
            }
            return $result;
        } finally {
            if (!empty($locked)) flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function updateSql(string $namespace, callable $mutation, array $context): mixed
    {
        $pdo = $this->database($context);
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
        $pdo->beginTransaction();
        try {
            // The primary key serializes first creation as well as later updates.
            // A no-change callback rolls back its provisional row entirely.
            $insert = $pdo->prepare('INSERT INTO private_state (namespace,payload_json,revision) VALUES (?,?,0) ON DUPLICATE KEY UPDATE namespace=VALUES(namespace)');
            $insert->execute([$namespace, $this->encode($namespace, [])]);
            $created = $insert->rowCount() === 1;
            $read = $pdo->prepare('SELECT payload_json,revision FROM private_state WHERE namespace=? FOR UPDATE');
            $read->execute([$namespace]);
            $row = $read->fetch();
            if ($row === false) throw new RuntimeException('Private storage did not retain its claimed row.', 503);
            $revision = $this->revision($row['revision'], $created);
            $data = $this->decode($namespace, $row['payload_json']);
            $before = $data;
            $result = $mutation($data);
            $this->unchangedContext($context);
            if ($data === $before) { $pdo->rollBack(); return $result; }
            if (!is_array($data)) throw new RuntimeException('A private-state mutation must preserve an array.');
            $bytes = $this->encode($namespace, $data);
            $write = $pdo->prepare('UPDATE private_state SET payload_json=?,revision=? WHERE namespace=? AND revision=?');
            $write->execute([$bytes, $revision + 1, $namespace, $revision]);
            if ($write->rowCount() !== 1) throw new RuntimeException('Private storage lost its current revision.', 409);
            $read->execute([$namespace]);
            $verified = $read->fetch();
            if ($verified === false || $verified['payload_json'] !== $bytes || (int) $verified['revision'] !== $revision + 1) throw new RuntimeException('Private storage failed transactional read-back verification.', 503);
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** @return array{0:array,1:string} */
    private function fileSnapshot(string $namespace): array
    {
        $directory = $this->directory(false);
        if ($directory === null) return [[], 'missing'];
        $path = $directory . '/' . $namespace . '.json';
        if (!$this->regularFile($path, true)) return [[], 'missing'];
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('Private state could not be read.', 503);
        try {
            $this->sameFile($path, $handle);
            $stat = fstat($handle);
            if ($stat['size'] > self::MAX_BYTES) throw new RuntimeException('Private state is too large; review it before making changes.', 503);
            $bytes = stream_get_contents($handle, self::MAX_BYTES + 1);
            if (!is_string($bytes) || strlen($bytes) > self::MAX_BYTES) throw new RuntimeException('Private state could not be read completely.', 503);
        } finally { fclose($handle); }
        return [$this->decode($namespace, $bytes), hash('sha256', $bytes)];
    }

    private function directory(bool $create): ?string
    {
        $path = $this->root;
        foreach (['storage', 'private', 'operations'] as $part) {
            $path .= '/' . $part;
            clearstatcache(true, $path);
            if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new RuntimeException('Private storage requires confined real directories.', 503);
            if (!is_dir($path)) {
                if (!$create) return null;
                if (!@mkdir($path, 0700) && !is_dir($path)) throw new RuntimeException('Private storage cannot create its directory.', 503);
            }
            if (realpath($path) !== $path) throw new RuntimeException('Private storage resolved outside its installation.', 503);
            if ($create && $part !== 'storage') $this->privateMode($path, true);
            if (!is_readable($path) || !is_executable($path)) throw new RuntimeException('Private storage cannot inspect its directory safely.', 503);
        }
        return $path;
    }

    private function regularFile(string $path, bool $missingAllowed): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false && $missingAllowed) return false;
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) throw new RuntimeException('Private storage requires a confined regular file.', 503);
        return true;
    }

    /** @param resource $handle */
    private function sameFile(string $path, $handle): void
    {
        $this->regularFile($path, false);
        $actual = fstat($handle);
        $named = lstat($path);
        if ($actual === false || $named === false || $actual['dev'] !== $named['dev'] || $actual['ino'] !== $named['ino']) throw new RuntimeException('Private storage path changed during access.', 503);
    }

    private function privateMode(string $path, bool $directory): void
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        $expected = $directory ? 0700 : 0600;
        if (($stat['mode'] & 0777) === $expected) return;
        if (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) throw new RuntimeException('Private storage permissions require repair by the file owner.', 503);
        if (!@chmod($path, $expected)) throw new RuntimeException('Private storage could not protect its permissions.', 503);
        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== $expected) throw new RuntimeException('Private storage permissions could not be verified.', 503);
    }

    private function context(): array
    {
        $this->config->reload();
        $mode = $this->config->get('storage_mode', 'mysql');
        if (!in_array($mode, ['file', 'mysql'], true)) throw new RuntimeException('Private storage requires an explicitly supported backend.', 503);
        $db = $this->config->get('db', []);
        if (!is_array($db)) throw new RuntimeException('Private storage database configuration is invalid.', 503);
        $identity = hash('sha256', json_encode([$mode, $mode === 'file' ? $this->root : $db], JSON_THROW_ON_ERROR));
        $lockKey = $mode === 'file' ? $identity : hash('sha256', json_encode([$mode, $db['host'] ?? '', (int) ($db['port'] ?? 3306), $db['name'] ?? ''], JSON_THROW_ON_ERROR));
        return ['mode' => $mode, 'db' => $db, 'identity' => $identity, 'lock_key' => $lockKey];
    }

    private function unchangedContext(array $context): void
    {
        if (!hash_equals($context['identity'], $this->context()['identity'])) throw new RuntimeException('Private storage settings changed during an update. No journal changes were committed.', 409);
    }

    private function database(array $context): PDO
    {
        try {
            $pdo = Database::connect($context['db']);
            $table = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='private_state'")->fetchColumn();
            $columns = $pdo->query("SELECT COLUMN_NAME,DATA_TYPE,IS_NULLABLE,COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='private_state' ORDER BY ORDINAL_POSITION")->fetchAll();
            $bounds = $pdo->query("SELECT COLUMN_NAME,COLUMN_TYPE,CHARACTER_MAXIMUM_LENGTH,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='private_state' ORDER BY ORDINAL_POSITION")->fetchAll();
            $expected = [['COLUMN_NAME' => 'namespace', 'DATA_TYPE' => 'varchar', 'IS_NULLABLE' => 'NO', 'COLUMN_KEY' => 'PRI'],
                ['COLUMN_NAME' => 'payload_json', 'DATA_TYPE' => 'longtext', 'IS_NULLABLE' => 'NO', 'COLUMN_KEY' => ''],
                ['COLUMN_NAME' => 'revision', 'DATA_TYPE' => 'bigint', 'IS_NULLABLE' => 'NO', 'COLUMN_KEY' => '']];
            if ($table !== 'InnoDB' || $columns !== $expected || (int) ($bounds[0]['CHARACTER_MAXIMUM_LENGTH'] ?? 0) !== 64
                || ($bounds[0]['COLLATION_NAME'] ?? '') !== 'utf8mb4_bin'
                // MariaDB reports the integer's historical display width. It
                // has the same unsigned 64-bit domain as MySQL's bare bigint.
                || !in_array($bounds[2]['COLUMN_TYPE'] ?? '', ['bigint unsigned', 'bigint(20) unsigned'], true)) throw new RuntimeException('Private operational storage requires migration 202610210017_private_state. Apply pending Database Updates before using it.', 503);
            return $pdo;
        } catch (Throwable $error) {
            if ($error instanceof RuntimeException && $error->getCode() === 503) throw $error;
            throw new RuntimeException('Private operational storage is unavailable. Verify the database and pending Database Updates; no file fallback was used.', 503, $error);
        }
    }

    private function validateNamespace(string $namespace): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $namespace) !== 1) throw new InvalidArgumentException('A simple private-state namespace is required.');
    }

    private function revision(mixed $revision, bool $new = false): int
    {
        if ((!is_int($revision) && !is_string($revision)) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string) $revision) !== 1
            || (float) $revision >= PHP_INT_MAX || (int) $revision < ($new ? 0 : 1)) throw new RuntimeException('Private state has an invalid revision. Review it before making changes.', 503);
        return (int) $revision;
    }

    private function encode(string $namespace, array $data): string
    {
        $this->jsonValue($data);
        try { $bytes = json_encode(['schema' => 1, 'namespace' => $namespace, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n"; }
        catch (JsonException $error) { throw new RuntimeException('Private state contains values that cannot be saved safely.', 0, $error); }
        if (strlen($bytes) > self::MAX_BYTES || $this->decode($namespace, $bytes) !== $data) throw new RuntimeException('Private state exceeds its limit or cannot round-trip without changes.', 503);
        return $bytes;
    }

    private function jsonValue(mixed $value, int $depth = 0): void
    {
        if ($depth > 64) throw new RuntimeException('Private state nesting is too deep.');
        if (is_array($value)) { foreach ($value as $child) $this->jsonValue($child, $depth + 1); return; }
        if ($value === null || is_string($value) || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value))) return;
        throw new RuntimeException('Private state accepts only JSON data values.');
    }

    private function decode(string $namespace, string $bytes): array
    {
        if (strlen($bytes) > self::MAX_BYTES) throw new RuntimeException('Private state exceeds its supported size.', 503);
        try { $envelope = json_decode($bytes, true, 70, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { throw new RuntimeException('Private state contains invalid JSON. Restore a verified copy before making changes.', 503, $error); }
        if (!is_array($envelope) || count($envelope) !== 3 || ($envelope['schema'] ?? null) !== 1 || ($envelope['namespace'] ?? null) !== $namespace
            || !is_array($envelope['data'] ?? null)) throw new RuntimeException('Private state has an unsupported or mismatched schema. Restore a verified copy before making changes.', 503);
        return $envelope['data'];
    }
}
