<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class CommunicationUploadService
{
    /** @var array<string,string> */
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
    ];

    /** @return list<array<string,mixed>> */
    public function uploadMany(array $files, int $maxFiles = 5): array
    {
        $normalized = $this->normalizeFiles($files);
        if (count($normalized) > $maxFiles) {
            throw new RuntimeException('Upload no more than ' . $maxFiles . ' files at a time.');
        }
        $result = [];
        foreach ($normalized as $file) {
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $result[] = $this->upload($file);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function upload(array $file): array
    {
        $settings = app_store()->communicationSettings();
        $maxBytes = max(1, (int) ($settings['maxAttachmentMb'] ?? 10)) * 1024 * 1024;
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'An attachment exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'An attachment exceeds the form upload limit.',
                UPLOAD_ERR_PARTIAL => 'An attachment upload was interrupted.',
                UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
            ];
            throw new RuntimeException($messages[$error] ?? 'An attachment could not be uploaded.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > $maxBytes) {
            throw new RuntimeException('Each attachment must be smaller than ' . (int) ($maxBytes / 1024 / 1024) . ' MB.');
        }
        if (!is_uploaded_file($tmp)) {
            throw new RuntimeException('The uploaded attachment could not be verified.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extension = self::TYPES[$mime] ?? null;
        if ($extension === null) {
            throw new RuntimeException('Unsupported attachment. Use JPG, PNG, WebP, PDF, or plain text.');
        }
        $allowedExtensions = array_map('strtolower', (array) ($settings['allowedExtensions'] ?? []));
        if ($allowedExtensions !== [] && !in_array($extension, $allowedExtensions, true) && !($extension === 'jpg' && in_array('jpeg', $allowedExtensions, true))) {
            throw new RuntimeException('This attachment type is disabled in Communication Settings.');
        }
        $directoryRelative = 'communications/' . gmdate('Y/m');
        $directory = root_path('storage/private/' . $directoryRelative);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the protected attachment directory.');
        }
        $id = bin2hex(random_bytes(12));
        $destination = $directory . '/' . $id . '.' . $extension;
        if (!move_uploaded_file($tmp, $destination)) {
            throw new RuntimeException('Unable to store the protected attachment.');
        }
        @chmod($destination, 0640);
        $original = trim((string) ($file['name'] ?? 'attachment.' . $extension));
        $original = preg_replace('/[^\pL\pN._() -]+/u', '_', basename($original)) ?: 'attachment.' . $extension;
        return [
            'id' => $id,
            'filePath' => 'private:' . $directoryRelative . '/' . basename($destination),
            'originalName' => substr($original, 0, 190),
            'mimeType' => $mime,
            'fileSize' => $size,
            'createdAt' => now_iso(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function normalizeFiles(array $files): array
    {
        if (!isset($files['name'])) return [];
        if (!is_array($files['name'])) return [$files];
        $rows = [];
        foreach ($files['name'] as $index => $name) {
            $rows[] = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }
        return $rows;
    }
}
