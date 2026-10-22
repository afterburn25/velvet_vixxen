<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class Config
{
    private array $values = [];
    private string $sourceDigest = 'missing';
    private static array $writing = [];
    private ?array $writeLock = null;

    public function __construct(private readonly string $path)
    {
        $this->reload();
    }

    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->values;
        }

        $segments = explode('.', $key);
        $value = $this->values;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function reload(): void
    {
        // Reading configuration never creates a lock, directory or temporary
        // file. Bind the values to the exact bytes that PHP evaluated.
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $before = $this->digest();
            if ($before === 'missing') {
                $this->values = []; $this->sourceDigest = 'missing'; return;
            }
            self::invalidateRuntimeCaches($this->path);
            $loaded = require $this->path;
            if ($before !== $this->digest()) continue;
            if (!is_array($loaded)) throw new RuntimeException('The configuration file did not return a settings array.');
            $this->values = $loaded; $this->sourceDigest = $before; return;
        }
        throw new RuntimeException('Configuration changed while it was being read. Reload before continuing.', 409);
    }

    /** Exact saved bytes, suitable for a form's optimistic revision guard. */
    public function revision(): string { return $this->sourceDigest; }

    /**
     * Load the exact bytes currently stored on disk through a one-time unique
     * path. This avoids stale OPcache/realpath entries on shared hosting after
     * the installer atomically replaces config/app.php.
     */
    public static function loadFresh(string $path): array
    {
        self::invalidateRuntimeCaches($path);
        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            throw new RuntimeException('The configuration file could not be read from disk.');
        }

        $directory = dirname($path);
        $verificationPath = $directory . '/.vvx-config-verify-' . bin2hex(random_bytes(8)) . '.php';
        $written = @file_put_contents($verificationPath, $contents, LOCK_EX);
        if ($written === false || $written !== strlen($contents)) {
            @unlink($verificationPath);
            throw new RuntimeException('The configuration file could not be independently verified.');
        }

        @chmod($verificationPath, 0600);
        self::invalidateRuntimeCaches($verificationPath);

        try {
            $loaded = require $verificationPath;
        } catch (Throwable $e) {
            throw new RuntimeException('The configuration file contains invalid PHP: ' . $e->getMessage(), 0, $e);
        } finally {
            self::invalidateRuntimeCaches($verificationPath);
            @unlink($verificationPath);
        }

        if (!is_array($loaded)) {
            throw new RuntimeException('The configuration file did not return a settings array.');
        }

        return $loaded;
    }

    public function write(array $values): void
    {
        $expected = $this->sourceDigest;
        $this->withWriteLock(function () use ($values, $expected): void {
            if (!hash_equals($expected, $this->digest())) throw new RuntimeException('Configuration changed. Reload this page before saving so newer settings are preserved.', 409);
            $this->activate($values, $expected);
        });
    }

    /** Merge only the intended fields into fresh settings while writers serialize. */
    public function update(callable $mutation, ?string $expectedRevision = null): mixed
    {
        return $this->withWriteLock(function () use ($mutation, $expectedRevision): mixed {
            $this->reload();
            if ($expectedRevision !== null && !hash_equals($expectedRevision, $this->sourceDigest)) {
                throw new RuntimeException('Configuration changed. Reload this page before saving so newer settings are preserved.', 409);
            }
            $before = $this->values; $next = $before; $expected = $this->sourceDigest;
            $result = $mutation($next);
            if (!is_array($next)) throw new RuntimeException('Configuration updates must preserve a settings array.');
            if ($next !== $before) $this->activate($next, $expected);
            return $result;
        });
    }

    private function withWriteLock(callable $operation): mixed
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the configuration directory.');
        }

        if (!is_writable($directory)) {
            @chmod($directory, 0775);
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('The configuration directory is not writable by PHP.');
        }
        $key = realpath($directory) . '/' . basename($this->path);
        if (isset(self::$writing[$key])) throw new RuntimeException('Finish the current configuration update before starting another.', 409);
        $lockPath = $directory . '/.vvx-config.lock';
        $this->regular($lockPath, true);
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) throw new RuntimeException('The configuration write lock is unavailable.', 503);
        self::$writing[$key] = true;
        try {
            $this->sameFile($lockPath, $lock);
            if (!@chmod($lockPath, 0600)) throw new RuntimeException('The configuration write lock could not be protected.', 503);
            $deadline = microtime(true) + 5;
            do {
                if (flock($lock, LOCK_EX | LOCK_NB)) { $locked = true; break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if (empty($locked)) throw new RuntimeException('Another settings update is finishing. Try again shortly.', 503);
            $this->sameFile($lockPath, $lock);
            $this->writeLock = ['path' => $lockPath, 'handle' => $lock];
            return $operation();
        } finally {
            if (!empty($locked)) flock($lock, LOCK_UN);
            $this->writeLock = null;
            unset(self::$writing[$key]); fclose($lock);
        }
    }

    private function activate(array $values, string $expected): void
    {
        // This second check also catches a writer that ignored the shared lock
        // during a caller's validation or other dependent reads.
        if ($this->writeLock === null) throw new RuntimeException('Configuration writes require their shared lock.');
        $this->sameFile($this->writeLock['path'], $this->writeLock['handle']);
        if (!hash_equals($expected, $this->digest())) throw new RuntimeException('Configuration changed during this update. Newer settings were preserved.', 409);

        $payload = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($values, true) . ";\n";
        $temp = $this->path . '.tmp-' . bin2hex(random_bytes(5));
        try {
        $written = file_put_contents($temp, $payload, LOCK_EX);
        if ($written === false || $written !== strlen($payload)) {
            @unlink($temp);
            throw new RuntimeException('Unable to write the temporary configuration file.');
        }

        @chmod($temp, 0640);
        clearstatcache(true, $temp);
        if (!is_readable($temp) || filesize($temp) !== strlen($payload)) {
            @unlink($temp);
            throw new RuntimeException('The temporary configuration file failed verification.');
        }

        if (is_file($this->path)) {
            $backup = $this->path . '.previous';
            $this->regular($backup, true);
            @copy($this->path, $backup);
            @chmod($backup, 0640);
        }

        if (!hash_equals($expected, $this->digest())) {
            @unlink($temp);
            throw new RuntimeException('Configuration changed during this update. Newer settings were preserved.', 409);
        }
        $this->sameFile($this->writeLock['path'], $this->writeLock['handle']);
        if (!@rename($temp, $this->path)) {
            @unlink($temp);
            throw new RuntimeException('Unable to activate the new configuration file.');
        }

        @chmod($this->path, 0640);
        self::invalidateRuntimeCaches($this->path);

        $activated = @file_get_contents($this->path);
        if (!is_string($activated) || !hash_equals(hash('sha256', $payload), hash('sha256', $activated))) {
            throw new RuntimeException('The configuration file was written but its contents did not match the requested settings.');
        }

        $fresh = self::loadFresh($this->path);
        if ($fresh !== $values) {
            throw new RuntimeException('The configuration file was written but PHP returned stale or different settings.');
        }

        $this->values = $values;
        $this->sourceDigest = hash('sha256', $payload);
        } finally {
            if (is_file($temp) && !is_link($temp)) @unlink($temp);
        }
    }

    private function digest(): string
    {
        if (!$this->regular($this->path, true)) return 'missing';
        $digest = @hash_file('sha256', $this->path);
        if (!is_string($digest)) throw new RuntimeException('The configuration file could not be read from disk.');
        return $digest;
    }

    private function regular(string $path, bool $missing): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false && $missing) return false;
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) throw new RuntimeException('Configuration requires a regular file without linked aliases.', 503);
        return true;
    }

    /** @param resource $handle */
    private function sameFile(string $path, $handle): void
    {
        $this->regular($path, false);
        $named = lstat($path); $actual = fstat($handle);
        if ($actual === false || $named['dev'] !== $actual['dev'] || $named['ino'] !== $actual['ino']) throw new RuntimeException('The configuration lock changed during access.', 503);
    }

    private static function invalidateRuntimeCaches(string $path): void
    {
        clearstatcache(true, $path);
        clearstatcache(true, dirname($path));

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
