<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class CommunicationUploadService
{
    private const TYPES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'application/pdf' => 'pdf', 'text/plain' => 'txt',
    ];
    /** Files created by this instance, with fingerprints so cleanup never removes replacements. */
    private array $pending = [];

    /** @return list<array<string,mixed>> */
    public function uploadMany(array $files, int $maxFiles = 5): array
    {
        $normalized = array_values(array_filter($this->normalizeFiles($files),
            static fn(array $file): bool => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if (count($normalized) > $maxFiles) throw new RuntimeException('Upload no more than ' . $maxFiles . ' files at a time.');
        // Validate the whole batch before the first file leaves PHP's temporary upload area.
        $prepared = array_map(fn(array $file): array => $this->prepare($file), $normalized);
        $result = [];
        try {
            foreach ($prepared as $file) $result[] = $this->storePrepared($file);
        } catch (\Throwable $error) {
            $this->discardPendingUploads();
            throw $error;
        }
        return $result;
    }

    public function upload(array $file): array
    {
        return $this->storePrepared($this->prepare($file));
    }

    /** Call only after records referencing these newly uploaded files have been persisted. */
    public function completeUploads(): void
    {
        $this->pending = [];
    }

    /** Revert only this instance's uncommitted uploads; caller-supplied paths are never accepted. */
    public function discardPendingUploads(): void
    {
        foreach ($this->pending as $path => $fingerprint) {
            if (vvx_recovery_safe_path(ROOT_PATH, $path) && is_file($path) && !is_link($path)
                && hash_equals($fingerprint, (string) hash_file('sha256', $path))) @unlink($path);
        }
        $this->pending = [];
    }

    private function prepare(array $file): array
    {
        $settings = app_store()->communicationSettings();
        $maxBytes = max(1, (int) ($settings['maxAttachmentMb'] ?? 10)) * 1024 * 1024;
        if (!is_string($file['tmp_name'] ?? null) || !is_string($file['name'] ?? null)) {
            throw new RuntimeException('The attachment upload is invalid.');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $messages = [UPLOAD_ERR_INI_SIZE => 'An attachment exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'An attachment exceeds the form upload limit.',
                UPLOAD_ERR_PARTIAL => 'An attachment upload was interrupted.', UPLOAD_ERR_NO_FILE => 'Choose a file to upload.'];
            throw new RuntimeException($messages[$error] ?? 'An attachment could not be uploaded.');
        }
        $tmp = $file['tmp_name'];
        if (!is_uploaded_file($tmp)) throw new RuntimeException('The uploaded attachment could not be verified.');
        $size = filesize($tmp);
        if ($size === false || $size < 1 || $size > $maxBytes) {
            throw new RuntimeException('Each attachment must be smaller than ' . (int) ($maxBytes / 1024 / 1024) . ' MB.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extension = self::TYPES[$mime] ?? null;
        $originalExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension === null || !in_array($originalExtension, $extension === 'jpg' ? ['jpg', 'jpeg'] : [$extension], true)) {
            throw new RuntimeException('Unsupported attachment. Use a matching JPG, PNG, WebP, PDF, or plain text file.');
        }
        $allowed = array_map('strtolower', (array) ($settings['allowedExtensions'] ?? []));
        if ($allowed !== [] && !in_array($extension, $allowed, true) && !($extension === 'jpg' && in_array('jpeg', $allowed, true))) {
            throw new RuntimeException('This attachment type is disabled in Communication Settings.');
        }
        $original = preg_replace('/[^\pL\pN._() -]+/u', '_', basename($file['name'])) ?: 'attachment.' . $extension;
        return ['tmp' => $tmp, 'extension' => $extension, 'mime' => $mime, 'size' => $size,
            'original' => function_exists('mb_strcut') ? mb_strcut($original, 0, 190, 'UTF-8') : substr($original, 0, 190)];
    }

    private function storePrepared(array $file): array
    {
        $relative = 'communications/' . gmdate('Y/m');
        $directory = root_path('storage/private/' . $relative);
        if (!vvx_recovery_safe_path(ROOT_PATH, $directory, true)) throw new RuntimeException('The protected attachment directory could not be verified.');
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the protected attachment directory.');
        }
        if (!vvx_recovery_safe_path(ROOT_PATH, $directory)) throw new RuntimeException('The protected attachment directory could not be verified.');
        $id = bin2hex(random_bytes(12));
        $destination = $directory . '/' . $id . '.' . $file['extension'];
        if (file_exists($destination) || is_link($destination) || !move_uploaded_file($file['tmp'], $destination)) {
            throw new RuntimeException('Unable to store the protected attachment.');
        }
        @chmod($destination, 0640);
        $this->pending[$destination] = (string) hash_file('sha256', $destination);
        return ['id' => $id, 'filePath' => 'private:' . $relative . '/' . basename($destination),
            'originalName' => $file['original'], 'mimeType' => $file['mime'], 'fileSize' => $file['size'], 'createdAt' => now_iso()];
    }

    private function normalizeFiles(array $files): array
    {
        if (!isset($files['name'])) return [];
        if (!is_array($files['name'])) return [$files];
        $rows = [];
        foreach ($files['name'] as $index => $name) {
            $rows[] = ['name' => $name, 'type' => $files['type'][$index] ?? '', 'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$index] ?? 0];
        }
        return $rows;
    }
}
