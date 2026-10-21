<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use ZipArchive;

/** Bounded complete archives. Oversized records fail explicitly; they are never silently truncated. */
final class MemberPrivacyArchive
{
    public const MAX_BYTES = 33554432;
    public const MAX_JSON_BYTES = 8388608;
    public const MAX_FILES = 1000;
    public const LIFETIME = 604800;

    public function __construct(private readonly string $root) {}

    public function directory(bool $create = false): string
    {
        $path = $this->root;
        foreach (['storage','private','member-exports'] as $part) {
            $path .= '/' . $part;
            if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new RuntimeException('Private export storage needs review.');
            if (!is_dir($path) && $create && !@mkdir($path, 0700) && !is_dir($path)) throw new RuntimeException('Private export storage could not be created.');
        }
        return $path;
    }

    public static function id(string $id): string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) throw new RuntimeException('Privacy request not found.', 404);
        return $id;
    }

    public function create(string $id, array $export, array $attachments, callable $authorize): array
    {
        self::id($id); $authorize();
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('The ZIP extension is required for account exports.');
        if (count($attachments) > self::MAX_FILES) throw new RuntimeException('The complete archive has more than 1,000 attachments. Arrange an operator-assisted export; no partial archive was created.');
        $directory = $this->directory(true); $target = $directory . '/' . $id . '.zip';
        if (is_link($target) || (file_exists($target) && !is_file($target))) throw new RuntimeException('Private export target needs review.');
        $this->removeBuilds($id);
        $temporary = $directory . '/' . $id . '.build-' . bin2hex(random_bytes(16)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('The private export could not be created.');
        @chmod($temporary, 0600); $manifest = []; $total = 0;
        try {
            foreach ($attachments as $attachment) {
                $authorize();
                $handle = PrivateCommunicationFile::open($this->root, $attachment);
                try { $bytes = stream_get_contents($handle, self::MAX_BYTES + 1); }
                finally { fclose($handle); }
                if (!is_string($bytes) || strlen($bytes) !== (int) $attachment['fileSize']) throw new RuntimeException('An attachment changed before export. Review the current inventory.');
                $total += strlen($bytes);
                if ($total > self::MAX_BYTES) throw new RuntimeException('The complete archive exceeds 32 MiB. Arrange an operator-assisted export; no partial archive was created.');
                $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename(str_replace('\\','/', (string) ($attachment['originalName'] ?? 'attachment'))));
                $name = substr($name ?: 'attachment', 0, 120);
                $identity = (string) ($attachment['id'] ?? '');
                if (preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $identity) !== 1 || !in_array($attachment['kind'] ?? '', ['message','request'], true)) throw new RuntimeException('An attachment identity needs review.');
                $entry = 'attachments/' . $attachment['kind'] . '-' . $identity . '-' . $name;
                if (isset($manifest[$entry])) throw new RuntimeException('Duplicate archive attachment identities need review.');
                $manifest[$entry] = ['size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'crc32' => hash('crc32b', $bytes)];
                if (!$zip->addFromString($entry, $bytes) || !$zip->setCompressionName($entry, ZipArchive::CM_STORE)) throw new RuntimeException('The complete attachment could not be archived.');
                unset($bytes);
            }
            $export['attachmentFiles'] = $manifest;
            $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            if (strlen($json) > self::MAX_JSON_BYTES || $total + strlen($json) > self::MAX_BYTES) throw new RuntimeException('The complete account record exceeds the archive limit. Arrange an operator-assisted export; no partial archive was created.');
            if (!$zip->addFromString('member-data.json', $json) || !$zip->setCompressionName('member-data.json', ZipArchive::CM_STORE)) throw new RuntimeException('Account data could not be archived.');
            if (!$zip->close()) throw new RuntimeException('The complete archive could not be finalized.');
            @chmod($temporary, 0600);
            $this->verifyZip($temporary);
            $size = filesize($temporary); $hash = hash_file('sha256', $temporary);
            if (!is_int($size) || $size > self::MAX_BYTES + 1048576 || !is_string($hash)) throw new RuntimeException('The complete archive could not be verified.');
            $authorize();
            if (!@rename($temporary, $target)) throw new RuntimeException('The verified archive could not be activated.');
            return ['file' => $id . '.zip', 'size' => $size, 'sha256' => $hash, 'created_at' => time(), 'expires_at' => time() + self::LIFETIME, 'entries' => count($manifest) + 1];
        } finally {
            if (isset($zip) && $zip->status === ZipArchive::ER_OK) { try { $zip->close(); } catch (\Throwable) {} }
            if (is_file($temporary) && !is_link($temporary)) @unlink($temporary);
        }
    }

    /** Only the checked bytes are returned to the HTTP route, even if a pathname changes later. */
    public function read(string $id, array $metadata, callable $authorize): string
    {
        self::id($id); $authorize();
        if (($metadata['file'] ?? '') !== $id . '.zip' || !is_int($metadata['expires_at'] ?? null) || $metadata['expires_at'] <= time()
            || !is_int($metadata['size'] ?? null) || $metadata['size'] < 1 || $metadata['size'] > self::MAX_BYTES + 1048576
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($metadata['sha256'] ?? '')) !== 1) throw new RuntimeException('This export is unavailable or expired.', 404);
        $path = $this->directory() . '/' . $id . '.zip';
        if (is_link($path) || !is_file($path)) throw new RuntimeException('This export is unavailable.', 404);
        $before = lstat($path); $handle = @fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('This export is unavailable.', 404);
        try {
            $opened = fstat($handle);
            if (!is_array($before) || !is_array($opened) || $before['dev'] !== $opened['dev'] || $before['ino'] !== $opened['ino'] || $opened['size'] !== $metadata['size']) throw new RuntimeException('This export has changed.', 404);
            $bytes = stream_get_contents($handle, self::MAX_BYTES + 1048577);
            if (!is_string($bytes) || strlen($bytes) !== $metadata['size'] || !hash_equals($metadata['sha256'], hash('sha256', $bytes))) throw new RuntimeException('This export has changed.', 404);
            $this->verifyZip($path);
            $authorize(); return $bytes;
        } finally { fclose($handle); }
    }

    private function verifyZip(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) throw new RuntimeException('The export ZIP failed its integrity check.', 404);
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_FILES + 1 || $zip->locateName('member-data.json') === false) throw new RuntimeException('The complete archive is invalid.', 404);
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entry = $zip->statIndex($i);
                if (!is_array($entry) || !is_int($entry['size'] ?? null) || $entry['size'] < 0 || $entry['size'] > self::MAX_BYTES
                    || ($entry['name'] !== 'member-data.json' && !preg_match('~\Aattachments/(?:message|request)-[A-Za-z0-9._-]+\z~D', $entry['name']))) throw new RuntimeException('The export contains an invalid entry.', 404);
                $total += $entry['size']; if ($total > self::MAX_BYTES) throw new RuntimeException('The export exceeds its verified bound.', 404);
                $bytes = $zip->getFromIndex($i);
                if (!is_string($bytes) || strlen($bytes) !== $entry['size'] || sprintf('%08x', crc32($bytes)) !== sprintf('%08x', $entry['crc'])) throw new RuntimeException('The export failed its CRC check.', 404);
            }
        } finally { $zip->close(); }
    }

    public function remove(string $id, array $metadata): void
    {
        self::id($id); $path = $this->directory() . '/' . $id . '.zip';
        if (!file_exists($path) && !is_link($path)) return;
        if (is_link($path) || !is_file($path) || ($metadata['file'] ?? '') !== $id . '.zip'
            || !hash_equals((string) ($metadata['sha256'] ?? ''), (string) hash_file('sha256', $path))) throw new RuntimeException('A private archive changed. Review it before cleanup.');
        if (!@unlink($path)) throw new RuntimeException('A private archive could not be removed.');
    }

    /** Recover a verified ZIP activated just before a worker lost its journal commit. */
    public function checkpoint(string $id): ?array
    {
        self::id($id); $path = $this->directory() . '/' . $id . '.zip';
        if (!file_exists($path) && !is_link($path)) return null;
        if (is_link($path) || !is_file($path)) throw new RuntimeException('A private export target needs review.');
        $this->verifyZip($path);
        return ['file' => $id . '.zip', 'size' => (int) filesize($path), 'sha256' => (string) hash_file('sha256', $path)];
    }

    public function removeBuilds(string $id): void
    {
        self::id($id);
        foreach (glob($this->directory() . '/' . $id . '.build-*.zip') ?: [] as $path) {
            if (!preg_match('/\A' . preg_quote($id, '/') . '\.build-[a-f0-9]{32}\.zip\z/D', basename($path))
                || is_link($path) || !is_file($path) || !@unlink($path)) throw new RuntimeException('An interrupted private export needs storage review.');
        }
    }
}
