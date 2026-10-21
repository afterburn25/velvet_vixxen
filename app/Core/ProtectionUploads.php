<?php
declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

/** A durable web-server barrier precedes every protection activation. */
final class ProtectionUploads
{
    private const RULES = "# Velvet Vixxen protected uploads v1\nOptions -Indexes\nRequire all denied\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^ - [F,L]\n</IfModule>\n";
    private readonly string $root;
    private readonly ?Closure $probe;

    public function __construct(private readonly Config $config, ?callable $probe = null)
    {
        $this->root = dirname($config->path(), 2);
        $this->probe = $probe === null ? null : Closure::fromCallable($probe);
    }

    /** Reads do not initialize directories, journals, probes or locks. */
    public function privateRequired(): bool
    {
        $this->config->reload();
        $rules = $this->root . '/uploads/.htaccess';
        return $this->config->get('protection.enabled', false) === true
            || (!is_link($rules) && is_file($rules) && @file_get_contents($rules) === self::RULES);
    }

    /** Called by upload writers before writing even the first uploaded byte. */
    public function destination(string $relative): string
    {
        $path = ProtectionResources::publicPath($relative, $this->root);
        if ($path === null) throw new RuntimeException('Choose a managed upload destination.');
        if (!$this->privateRequired()) return $this->root . '/' . $path;
        $directory = $this->privateDirectory();
        return $directory . '/protected-assets/' . hash('sha256', $path) . '.bin';
    }

    /** The uploaded-file namespace is denied before any flag or media move. */
    public function installAndVerify(callable $authorize): void
    {
        $authorize();
        $uploads = $this->root . '/uploads';
        if (is_link($uploads) || !is_dir($uploads)) throw new RuntimeException('The uploads directory must be a real directory before protection can be enabled.', 503);
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploads, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if (++$count > 50000 || $file->isLink()) throw new RuntimeException('Review the uploads directory before protection setup: linked paths or an oversized directory cannot be sealed automatically.', 503);
            if ($file->getFilename() === '.htaccess' && $file->getPathname() !== $uploads . '/.htaccess') throw new RuntimeException('Remove or reconcile nested upload access rules before protection setup. They can override the private delivery barrier.', 503);
        }
        $rules = $uploads . '/.htaccess';
        if (is_link($rules) || (file_exists($rules) && !is_file($rules))) throw new RuntimeException('The upload access rules need a storage review.', 503);
        $previous = is_file($rules) ? file_get_contents($rules) : '';
        if (!is_string($previous) || strlen($previous) > 65536) throw new RuntimeException('The upload access rules could not be preserved safely.', 503);
        if ($previous !== self::RULES) {
            $directory = $this->privateDirectory();
            $backup = $directory . '/protection-upload-rules-' . hash('sha256', $previous) . '.txt';
            if (!file_exists($backup)) $this->writeExclusive($backup, $previous, 0600);
            elseif (is_link($backup) || file_get_contents($backup) !== $previous) throw new RuntimeException('The preserved upload access rules need a review.', 503);
            $authorize();
            $temporary = $uploads . '/.vvx-protection-' . bin2hex(random_bytes(12));
            try {
                $this->writeExclusive($temporary, self::RULES, 0644);
                $authorize();
                if (!rename($temporary, $rules)) throw new RuntimeException('The server upload barrier could not be installed.', 503);
            } finally { if (is_file($temporary)) @unlink($temporary); }
        }
        // A real file must exist throughout the HTTP check. A missing-file 404
        // is never evidence that a hosting rule blocks uploaded bytes.
        $name = 'protection-check-' . bin2hex(random_bytes(20)) . '.txt';
        $marker = $uploads . '/' . $name;
        $body = 'Harmless Velvet Vixxen upload-denial check ' . bin2hex(random_bytes(24));
        $this->writeExclusive($marker, $body, 0644);
        try {
            $authorize();
            $url = $this->probeUrl($name);
            $status = $this->probe === null ? $this->requestStatus($url) : ($this->probe)($url, $marker);
            if (!is_int($status) || !in_array($status, [403, 404], true) || !is_file($marker)
                || is_link($marker) || file_get_contents($marker) !== $body || file_get_contents($rules) !== self::RULES) {
                throw new RuntimeException('This host still permits raw upload requests, or its denial could not be verified. Protection was not activated. Ask your host to deny direct access to the uploads directory, then retry. Apache access rules have been saved; other servers need an equivalent rule.', 503);
            }
            $authorize();
        } finally { @unlink($marker); }
    }

    private function probeUrl(string $name): string
    {
        $base = rtrim((string) $this->config->get('base_url'), '/');
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $base)) throw new RuntimeException('Save a valid website address before checking protected delivery.', 503);
        return $base . '/uploads/' . $name . '?vvx_check=' . bin2hex(random_bytes(12));
    }

    private function requestStatus(string $url): int
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url); $bytes = 0;
            if ($handle === false) throw new RuntimeException('The protected-delivery check could not start.', 503);
            curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Cache-Control: no-cache, no-store', 'Accept: text/plain'],
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$bytes): int { $bytes += strlen($chunk); return $bytes > 65536 ? 0 : strlen($chunk); }]);
            $ok = curl_exec($handle); $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_close($handle);
            return $ok === false ? 0 : $status;
        }
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 8, 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 0, 'header' => "Cache-Control: no-cache, no-store\r\nAccept: text/plain\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @fopen($url, 'rb', false, $context);
        if (!is_resource($stream)) return 0;
        $metadata = stream_get_meta_data($stream); fclose($stream);
        foreach (($metadata['wrapper_data'] ?? []) as $header) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match)) return (int) $match[1];
        return 0;
    }

    private function privateDirectory(): string
    {
        $path = $this->root;
        foreach (['storage', 'private', 'protected-assets'] as $part) {
            $path .= '/' . $part;
            if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new RuntimeException('Private upload storage requires real directories.', 503);
            if (!is_dir($path) && !mkdir($path, 0700)) throw new RuntimeException('Private upload storage could not be created.', 503);
            if ($part !== 'storage' && !chmod($path, 0700)) throw new RuntimeException('Private upload permissions could not be secured.', 503);
        }
        return dirname($path);
    }

    private function writeExclusive(string $path, string $body, int $mode): void
    {
        $stream = @fopen($path, 'xb');
        if (!is_resource($stream)) throw new RuntimeException('A protection setup file could not be created safely.', 503);
        try {
            if (!chmod($path, $mode) || fwrite($stream, $body) !== strlen($body) || !fflush($stream)
                || (function_exists('fsync') && !fsync($stream))) throw new RuntimeException('A protection setup file could not be saved.', 503);
        } finally { fclose($stream); }
    }
}
