<?php
declare(strict_types=1);

namespace Velvet\Installer;

final class InstallerLog
{
    private string $path;

    public function __construct(private readonly string $root, ?string $installId = null)
    {
        $installId = $installId ?: bin2hex(random_bytes(8));
        $directory = $root . '/storage/logs';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        if (!is_dir($directory) || !is_writable($directory)) {
            $directory = rtrim(sys_get_temp_dir(), '/\\');
        }
        $this->path = $directory . '/velvet-installer-' . preg_replace('/[^A-Za-z0-9_-]/', '', $installId) . '.log';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function write(string $event, array $context = []): void
    {
        $record = [
            'time' => gmdate('c'),
            'event' => $event,
            'context' => $this->sanitize($context),
        ];
        @file_put_contents(
            $this->path,
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
        @chmod($this->path, 0640);
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/password|secret|token|private[_-]?key|license[_-]?key/i', $key)) {
            return '[redacted]';
        }
        if (!is_array($value)) {
            return is_scalar($value) || $value === null ? $value : get_debug_type($value);
        }
        $clean = [];
        foreach ($value as $childKey => $childValue) {
            $clean[$childKey] = $this->sanitize($childValue, (string) $childKey);
        }
        return $clean;
    }
}
