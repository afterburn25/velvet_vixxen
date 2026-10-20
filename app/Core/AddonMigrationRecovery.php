<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Private, durable progress survives DDL commits and does not depend on the migration ledger being writable. */
final class AddonMigrationRecovery
{
    private readonly string $path;
    private mixed $lock = null;

    public function __construct(private readonly string $addonKey, private readonly string $scope = 'migrations')
    {
        if (!in_array($scope, ['migrations', 'lifecycle'], true)) throw new RuntimeException('Invalid add-on recovery scope.');
        $directory = root_path('storage/backups/addons/' . $scope);
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory))) {
            throw new RuntimeException('The private add-on migration recovery directory is unavailable.');
        }
        @chmod($directory, 0750);
        $this->path = $directory . '/' . hash('sha256', $addonKey) . '.json';
    }

    public function lock(): void
    {
        if (is_link($this->path) || is_link($this->path . '.lock')) throw new RuntimeException('Unsafe add-on migration recovery path.');
        $this->lock = fopen($this->path . '.lock', 'c');
        if (!is_resource($this->lock) || !flock($this->lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another migration operation is already running for this add-on.');
        }
        @chmod($this->path . '.lock', 0640);
    }

    public function read(): ?array
    {
        if (!is_file($this->path)) return null;
        if (is_link($this->path)) throw new RuntimeException('Unsafe add-on migration recovery record.');
        $record = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['addonKey'] ?? '') !== $this->addonKey || ($record['schema'] ?? null) !== 1 || ($record['scope'] ?? 'migrations') !== $this->scope) {
            throw new RuntimeException('Invalid add-on migration recovery record; preserve it for review.');
        }
        return $record;
    }

    public function write(array $record): void
    {
        $record['schema'] = 1; $record['addonKey'] = $this->addonKey; $record['scope'] = $this->scope; $record['updatedAt'] = now_iso();
        $temporary = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            $bytes = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $file = fopen($temporary, 'x');
            if (!is_resource($file)) throw new RuntimeException('Could not create the add-on migration recovery record.');
            @chmod($temporary, 0640);
            try {
                if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file) || (function_exists('fsync') && !fsync($file))) {
                    throw new RuntimeException('Could not persist add-on migration progress.');
                }
            } finally { fclose($file); }
            if (!rename($temporary, $this->path)) throw new RuntimeException('Could not replace the add-on migration recovery record.');
        } finally { if (is_file($temporary)) @unlink($temporary); }
    }

    public function clear(): void
    {
        if (is_file($this->path) && !unlink($this->path)) throw new RuntimeException('Could not clear completed add-on migration recovery.');
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
    }
}
