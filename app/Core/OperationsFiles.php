<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;

/** Filesystem rules shared by the operational archive and request gate. */
final class OperationsFiles
{
    public const MAX_ENTRIES = 100000;
    public const MAX_BYTES = 274877906944; // 256 GiB, streamed rather than buffered.
    public const EXCLUDED = ['.git', '.github', 'storage/backups', 'database/backups',
        'storage/cache', 'storage/sessions', 'storage/temp', 'storage/private/repair-backups',
        'storage/private/operations/backups', 'storage/private/operations/backup-work',
        'storage/private/operations/request.lock', 'storage/private/operations/gate.json'];

    /** Strict CLI parsing: a misspelled --dry-run must never start a real job. */
    public static function options(array $specifications): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Operations commands are CLI-only.', 403);
        $spec = [];
        foreach ($specifications as $item) $spec[rtrim($item, ':')] = str_ends_with($item, ':');
        $result = []; $arguments = $GLOBALS['argv'];
        for ($index=1;$index<count($arguments);$index++) {
            $argument = $arguments[$index];
            if (!str_starts_with($argument,'--')) throw new InvalidArgumentException('Use named --options for Operations commands.');
            $parts = explode('=',substr($argument,2),2); $name = $parts[0];
            if (!array_key_exists($name,$spec) || array_key_exists($name,$result)) throw new InvalidArgumentException('An unknown or duplicate Operations option was refused. Use --help.');
            if (!$spec[$name]) {
                if (count($parts)!==1) throw new InvalidArgumentException('This Operations flag does not accept a value.');
                $result[$name] = false; continue;
            }
            $value = $parts[1] ?? ($arguments[++$index] ?? null);
            if (!is_string($value) || $value === '' || str_starts_with($value,'--') || strlen($value)>4096) throw new InvalidArgumentException('An Operations option is missing its value.');
            $result[$name] = $value;
        }
        return $result;
    }

    public static function root(string $root): string
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real) || is_link($root)) throw new RuntimeException('The installation directory is unavailable.');
        self::noLinks($real);
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    public static function relative(mixed $path): string
    {
        if (!is_string($path) || $path === '' || strlen($path) > 1024 || $path[0] === '/'
            || !preg_match('//u', $path) || preg_match('/[\x00-\x1f\x7f\\\\:]/', $path)
            || count(explode('/', $path)) > 40) throw new InvalidArgumentException('An archive entry has an unsupported path.');
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new InvalidArgumentException('An archive entry has an unsafe path.');
        }
        return $path;
    }

    /** Verify every existing ancestor; never follow a symbolic link. */
    public static function noLinks(string $path): void
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '\\')) throw new RuntimeException('An absolute local path is required.');
        $current = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new RuntimeException('A local path is not canonical.');
            $current .= '/' . $part;
            clearstatcache(true, $current);
            if (is_link($current)) throw new RuntimeException('Symbolic links are not supported by backup operations.');
        }
    }

    public static function directory(string $path, bool $create = false): string
    {
        self::noLinks($path);
        if (!is_dir($path) && (!$create || !@mkdir($path, 0700, true))) throw new RuntimeException('The private operations directory is unavailable.');
        self::noLinks($path);
        $real = realpath($path);
        if ($real !== $path || !is_dir($path)) throw new RuntimeException('The private operations directory changed.');
        return $path;
    }

    public static function writeNew(string $path, string $contents): void
    {
        self::noLinks($path);
        $handle = @fopen($path, 'xb');
        if ($handle === false) throw new RuntimeException('The new private file could not be created.');
        try {
            if (!@chmod($path, 0600)) throw new RuntimeException('The new private file permissions could not be restricted.');
            self::writeAll($handle, $contents);
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('The private file could not be flushed safely.');
        } finally { fclose($handle); }
    }

    public static function replace(string $path, string $contents): void
    {
        self::noLinks($path);
        if (file_exists($path) && !is_file($path)) throw new RuntimeException('The private marker is not a regular file.');
        $temporary = $path . '.new-' . bin2hex(random_bytes(12));
        try {
            self::writeNew($temporary, $contents);
            self::noLinks($path);
            if (!@rename($temporary, $path)) throw new RuntimeException('The private marker could not be saved.');
        } finally { if (is_file($temporary) && !is_link($temporary)) @unlink($temporary); }
    }

    public static function writeAll($handle, string $contents): void
    {
        $offset = 0; $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('An archive write did not finish.');
            $offset += $written;
        }
    }

    public static function readExact($handle, int $length): string
    {
        $result = '';
        while (strlen($result) < $length) {
            $part = fread($handle, $length - strlen($result));
            if ($part === false || $part === '') throw new RuntimeException('The encrypted archive is truncated or unreadable.');
            $result .= $part;
        }
        return $result;
    }

    /** The key is an independent binary file, never an application secret or a CLI argument value. */
    public static function key(string $path, string $root): string
    {
        self::noLinks($path);
        if (str_starts_with($path . '/', self::root($root) . '/')) throw new RuntimeException('Keep the backup key outside the installation and every web document root.');
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || $stat['size'] !== 32 || ($stat['mode'] & 0077) !== 0
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) throw new RuntimeException('The backup key must be an owner-only, regular 32-byte file outside the installation.');
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('The backup key is unreadable.');
        try {
            $opened = fstat($handle);
            if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']) throw new RuntimeException('The backup key changed while opening.');
            $key = self::readExact($handle, 32);
            if (fread($handle, 1) !== '') throw new RuntimeException('The backup key has an unexpected length.');
            return $key;
        } finally { fclose($handle); }
    }

    public static function excluded(string $relative): bool
    {
        foreach (self::EXCLUDED as $prefix) if ($relative === $prefix || str_starts_with($relative, $prefix . '/')) return true;
        return false;
    }

    /** A sorted, bounded inventory. Exclusions are declared in every encrypted manifest. */
    public static function inventory(string $root): array
    {
        $root = self::root($root); $result = []; $bytes = 0; $folded = [];
        $walk = static function (string $relative) use (&$walk, &$result, &$bytes, &$folded, $root): void {
            $directory = $relative === '' ? $root : $root . '/' . $relative;
            $names = @scandir($directory);
            if ($names === false) throw new RuntimeException('A snapshot directory could not be read.');
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') continue;
                $path = $relative === '' ? $name : $relative . '/' . $name;
                self::relative($path); $absolute = $root . '/' . $path; self::noLinks($absolute);
                if (self::excluded($path)) continue;
                $stat = @lstat($absolute);
                if ($stat === false || !in_array($stat['mode'] & 0170000, [0040000, 0100000], true)
                    || (($stat['mode'] & 0170000) === 0100000 && $stat['nlink'] !== 1)) throw new RuntimeException('Only regular files and directories may enter a backup.');
                $fold = strtolower($path);
                if (isset($folded[$fold])) throw new RuntimeException('Case-conflicting archive paths need operator review.');
                $folded[$fold] = true;
                $kind = ($stat['mode'] & 0170000) === 0040000 ? 'directory' : 'file';
                $size = $kind === 'file' ? (int) $stat['size'] : 0;
                $bytes += $size;
                if ($size < 0 || $bytes > self::MAX_BYTES || count($result) >= self::MAX_ENTRIES) throw new RuntimeException('The snapshot exceeds the supported file count or 256 GiB boundary. Use the hosting backup service for this installation.');
                $result[$path] = ['kind' => $kind, 'mode' => $stat['mode'] & 0777, 'size' => $size,
                    'dev' => $stat['dev'], 'ino' => $stat['ino'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime']];
                if ($kind === 'directory') $walk($path);
            }
        };
        $walk(''); ksort($result); return $result;
    }
}
