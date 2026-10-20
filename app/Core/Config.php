<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class Config
{
    private array $values = [];

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
        $this->values = [];
        if (!is_file($this->path)) {
            return;
        }

        self::invalidateRuntimeCaches($this->path);
        $loaded = require $this->path;
        if (is_array($loaded)) {
            $this->values = $loaded;
        }
    }

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

        $payload = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($values, true) . ";\n";
        $temp = $this->path . '.tmp-' . bin2hex(random_bytes(5));
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
            @copy($this->path, $backup);
            @chmod($backup, 0640);
        }

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
