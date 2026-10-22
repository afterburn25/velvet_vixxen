<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Versioned framing around libsodium's authenticated stream; never a plaintext staging ZIP. */
final class BackupArchive
{
    public const MAGIC = "VVXBACKUP1\n";
    public const CHUNK = 1048576;
    public const MAX_METADATA = 2097152;
    private $handle;
    private string $state;
    private $inventoryHash;
    private int $entries = 0;
    private int $bytes = 0;
    private array $names = [];
    private bool $closed = false;

    public function __construct(string $path, #[\SensitiveParameter] string $key, array $header)
    {
        if (!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push') || PHP_INT_SIZE < 8) throw new RuntimeException('Backups require 64-bit PHP with the Sodium extension.');
        self::header($header); OperationsFiles::noLinks($path);
        $this->handle = @fopen($path, 'xb');
        if ($this->handle === false) throw new RuntimeException('A new encrypted archive could not be created.');
        if (!@chmod($path, 0600)) { fclose($this->handle); throw new RuntimeException('The archive permissions could not be restricted.'); }
        [$this->state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $this->inventoryHash = hash_init('sha256');
        OperationsFiles::writeAll($this->handle, self::MAGIC . $streamHeader);
        $this->push('H' . self::json($header));
    }

    public function file(string $relative, string $absolute, array $expected): void
    {
        OperationsFiles::noLinks($absolute);
        $handle = @fopen($absolute, 'rb');
        if ($handle === false) throw new RuntimeException('A snapshot file could not be opened.');
        $same = static function ($stat) use ($expected): bool {
            return is_array($stat) && ($stat['mode'] & 0170000) === 0100000
                && $stat['dev'] === $expected['dev'] && $stat['ino'] === $expected['ino']
                && $stat['size'] === $expected['size'] && $stat['mtime'] === $expected['mtime'] && $stat['ctime'] === $expected['ctime'];
        };
        try {
            if (!$same(fstat($handle))) throw new RuntimeException('A snapshot file changed before it could be copied.');
            $chunks = static function () use ($handle): \Generator {
                while (!feof($handle)) {
                    $part = fread($handle, self::CHUNK);
                    if ($part === false) throw new RuntimeException('A snapshot file could not be read.');
                    if ($part !== '') yield $part;
                }
            };
            $result = $this->entry($relative, 'file', $chunks(), $expected['mode']);
            clearstatcache(true, $absolute);
            if ($result['bytes'] !== $expected['size'] || !$same(fstat($handle)) || !$same(@lstat($absolute))) throw new RuntimeException('A snapshot file changed while it was copied. The archive was not completed.');
        } finally { fclose($handle); }
    }

    public function entry(string $path, string $kind, iterable $chunks, int $mode = 0600): array
    {
        $entry = self::entryHeader(['path' => $path, 'kind' => $kind, 'mode' => $mode]);
        $key = $kind === 'file' || $kind === 'directory' ? 'files:' . strtolower($path) : $kind . ':' . strtolower($path);
        if (isset($this->names[$key]) || ++$this->entries > OperationsFiles::MAX_ENTRIES) throw new RuntimeException('The archive has duplicate or too many entries.');
        $this->names[$key] = true; $this->push('N' . self::json($entry)); $hash = hash_init('sha256'); $size = 0;
        foreach ($chunks as $chunk) {
            if (!is_string($chunk)) throw new RuntimeException('An archive source yielded invalid data.');
            for ($at = 0, $length = strlen($chunk); $at < $length; $at += self::CHUNK) {
                $part = substr($chunk, $at, self::CHUNK); $size += strlen($part); $this->bytes += strlen($part);
                if ($this->bytes > OperationsFiles::MAX_BYTES || $kind === 'directory') throw new RuntimeException('The archive exceeds its supported data boundary.');
                hash_update($hash, $part); $this->push('D' . $part);
            }
        }
        $end = ['path' => $path, 'kind' => $kind, 'bytes' => $size, 'sha256' => hash_final($hash)];
        $encoded = self::json($end); hash_update($this->inventoryHash, $encoded . "\n"); $this->push('E' . $encoded);
        return $end;
    }

    public function finish(array $database): array
    {
        $summary = ['entries' => $this->entries, 'bytes' => $this->bytes, 'inventory_sha256' => hash_final($this->inventoryHash),
            'database' => $database, 'completed_at' => gmdate('c')];
        $this->push('M' . self::json($summary), true);
        if (!fflush($this->handle) || (function_exists('fsync') && !fsync($this->handle))) throw new RuntimeException('The encrypted archive could not be flushed.');
        fclose($this->handle); $this->closed = true; return $summary;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) fclose($this->handle);
        if (isset($this->state)) sodium_memzero($this->state);
    }

    private function push(string $plain, bool $final = false): void
    {
        if ($this->closed || strlen($plain) > self::MAX_METADATA) throw new RuntimeException('An archive frame exceeds its supported boundary.');
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, self::MAGIC,
            $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
        OperationsFiles::writeAll($this->handle, pack('N', strlen($cipher)) . $cipher);
    }

    /** Fully authenticates and hashes every entry, including required final tag and EOF. */
    public static function scan(string $path, #[\SensitiveParameter] string $key, ?callable $consumer = null): array
    {
        OperationsFiles::noLinks($path); $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) throw new RuntimeException('Select a regular encrypted archive.');
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('The encrypted archive is unreadable.');
        $state = null;
        try {
            $opened = fstat($handle);
            if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']) throw new RuntimeException('The archive changed while opening.');
            $magic = OperationsFiles::readExact($handle, strlen(self::MAGIC));
            if ($magic !== self::MAGIC) throw new RuntimeException('The archive format is not supported.');
            $streamHeader = OperationsFiles::readExact($handle, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $archiveHash = hash_init('sha256'); hash_update($archiveHash, $magic . $streamHeader);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $key);
            $header = null; $entry = null; $entryHash = null; $entryBytes = 0; $entries = 0; $bytes = 0; $names = []; $inventory = hash_init('sha256');
            $frames = 0;
            while (true) {
                if (++$frames > 1000000) throw new RuntimeException('The archive contains too many frames.');
                $lengthBytes = OperationsFiles::readExact($handle, 4); $length = unpack('Nlength', $lengthBytes)['length'];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES + 1 || $length > self::MAX_METADATA + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) throw new RuntimeException('The encrypted archive has an invalid frame length.');
                $cipher = OperationsFiles::readExact($handle, $length); hash_update($archiveHash, $lengthBytes . $cipher);
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher, self::MAGIC);
                if ($pulled === false) throw new RuntimeException('The archive failed authentication. Check its key and integrity.');
                [$plain, $tag] = $pulled; $type = $plain[0] ?? ''; $body = substr($plain, 1);
                if (!in_array($tag, [SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL], true)
                    || ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) !== ($type === 'M')) throw new RuntimeException('The archive stream ended out of sequence.');
                if ($type === 'H') {
                    if ($header !== null || $entry !== null) throw new RuntimeException('The archive has duplicate metadata.');
                    $header = self::header(self::decode($body)); if ($consumer !== null) $consumer('header', $header, '');
                    continue;
                }
                if ($header === null) throw new RuntimeException('The archive header is missing.');
                if ($type === 'N') {
                    if ($entry !== null || ++$entries > OperationsFiles::MAX_ENTRIES) throw new RuntimeException('The archive entry sequence is invalid.');
                    $entry = self::entryHeader(self::decode($body));
                    $name = in_array($entry['kind'], ['file', 'directory'], true) ? 'files:' . strtolower($entry['path']) : $entry['kind'] . ':' . strtolower($entry['path']);
                    if (isset($names[$name])) throw new RuntimeException('The archive contains duplicate paths.');
                    $names[$name] = true; $entryBytes = 0; $entryHash = hash_init('sha256');
                    if ($consumer !== null) $consumer('begin', $entry, '');
                } elseif ($type === 'D') {
                    if ($entry === null || $entry['kind'] === 'directory' || strlen($body) > self::CHUNK) throw new RuntimeException('The archive data sequence is invalid.');
                    $entryBytes += strlen($body); $bytes += strlen($body);
                    if ($bytes > OperationsFiles::MAX_BYTES) throw new RuntimeException('The archive exceeds its supported data boundary.');
                    hash_update($entryHash, $body); if ($consumer !== null) $consumer('data', $entry, $body);
                } elseif ($type === 'E') {
                    if ($entry === null) throw new RuntimeException('The archive entry terminator is unexpected.');
                    $actual = ['path' => $entry['path'], 'kind' => $entry['kind'], 'bytes' => $entryBytes, 'sha256' => hash_final($entryHash)];
                    if (self::decode($body) !== $actual) throw new RuntimeException('An archive entry failed its manifest checksum.');
                    hash_update($inventory, self::json($actual) . "\n");
                    if ($consumer !== null) $consumer('end', $entry + $actual, ''); $entry = null;
                } elseif ($type === 'M') {
                    $summary = self::decode($body);
                    if ($entry !== null || array_keys($summary) !== ['entries','bytes','inventory_sha256','database','completed_at']
                        || $summary['entries'] !== $entries || $summary['bytes'] !== $bytes
                        || $summary['inventory_sha256'] !== hash_final($inventory) || !is_array($summary['database'])
                        || !is_string($summary['completed_at']) || strtotime($summary['completed_at']) === false || fread($handle, 1) !== '') throw new RuntimeException('The final archive manifest or end marker is invalid.');
                    $opened = fstat($handle); clearstatcache(true, $path); $after = @lstat($path);
                    if ($opened === false || $after === false || $opened['ino'] !== $stat['ino'] || $after['ino'] !== $stat['ino']
                        || $after['size'] !== $stat['size'] || $after['mtime'] !== $stat['mtime'] || $after['ctime'] !== $stat['ctime']) throw new RuntimeException('The archive changed during verification.');
                    return ['header' => $header, 'manifest' => $summary, 'archive_sha256' => hash_final($archiveHash), 'archive_bytes' => $stat['size']];
                } else throw new RuntimeException('The archive contains an unsupported record.');
            }
        } finally { fclose($handle); if (is_string($state)) sodium_memzero($state); }
    }

    private static function header(array $header): array
    {
        if (array_keys($header) !== ['schema','id','created_at','version','build','installation_hash','storage_mode','excluded']
            || $header['schema'] !== 1 || !is_string($header['id']) || !preg_match('/\A[a-f0-9]{32}\z/D', $header['id'])
            || !is_string($header['installation_hash']) || !preg_match('/\A[a-f0-9]{64}\z/D', $header['installation_hash'])
            || !in_array($header['storage_mode'], ['file','mysql'], true) || !is_string($header['version']) || strlen($header['version']) > 40
            || !is_string($header['build']) || strlen($header['build']) > 40 || !is_string($header['created_at']) || strtotime($header['created_at']) === false
            || $header['excluded'] !== OperationsFiles::EXCLUDED) throw new RuntimeException('The encrypted archive header is invalid or unsupported.');
        return $header;
    }

    private static function entryHeader(array $entry): array
    {
        if (array_keys($entry) !== ['path','kind','mode'] || !in_array($entry['kind'], ['file','directory','schema','rows'], true)
            || !is_int($entry['mode']) || $entry['mode'] < 0 || $entry['mode'] > 0777) throw new RuntimeException('An archive entry header is invalid.');
        OperationsFiles::relative($entry['path']);
        if (in_array($entry['kind'], ['file','directory'], true) && OperationsFiles::excluded($entry['path'])) throw new RuntimeException('An archive contains an excluded recursive or transient path.');
        if (in_array($entry['kind'], ['schema','rows'], true) && !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $entry['path'])) throw new RuntimeException('An archive database identifier is invalid.');
        return $entry;
    }

    public static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
    private static function decode(string $value): array
    {
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('An archive metadata record is invalid.');
        return $decoded;
    }
}
