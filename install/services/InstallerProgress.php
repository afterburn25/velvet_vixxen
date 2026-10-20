<?php
declare(strict_types=1);

namespace Velvet\Installer;

use RuntimeException;

final class InstallerProgress
{
    private string $id;
    private string $path;

    public function __construct(private readonly string $root, string $id)
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $id);
        if (!is_string($clean) || $clean === '') {
            throw new RuntimeException('The installation progress identifier is invalid.');
        }
        $this->id = $clean;
        $directory = $this->root . '/storage/temp';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $this->path = $directory . '/installer-progress-' . $this->id . '.json';
    }

    public function reset(): void
    {
        $this->update('ready', 'Ready to install', 0, 'waiting', 'Complete the form and start installation.');
    }

    public function update(
        string $stage,
        string $label,
        int $percent,
        string $status = 'running',
        string $detail = '',
    ): void {
        $record = [
            'id' => $this->id,
            'stage' => $stage,
            'label' => $label,
            'percent' => max(0, min(100, $percent)),
            'status' => in_array($status, ['waiting', 'running', 'success', 'warning', 'failed'], true) ? $status : 'running',
            'detail' => $detail,
            'updated_at' => gmdate('c'),
        ];
        $this->write($record);
    }

    public function read(): array
    {
        if (!is_file($this->path)) {
            return [
                'id' => $this->id,
                'stage' => 'ready',
                'label' => 'Waiting to begin',
                'percent' => 0,
                'status' => 'waiting',
                'detail' => '',
                'updated_at' => null,
            ];
        }
        $decoded = json_decode((string) @file_get_contents($this->path), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function path(): string
    {
        return $this->path;
    }

    public function remove(): void
    {
        @unlink($this->path);
    }

    private function write(array $record): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) || !is_writable($directory)) {
            return;
        }
        $payload = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        $temp = $this->path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $payload, LOCK_EX) === false) {
            @unlink($temp);
            return;
        }
        @chmod($temp, 0640);
        if (!@rename($temp, $this->path)) {
            @unlink($temp);
            return;
        }
        @chmod($this->path, 0640);
    }
}
