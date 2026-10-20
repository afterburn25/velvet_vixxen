<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class UploadService
{
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function upload(array $file, string $type = 'model', ?array $thumbnailFile = null): array
    {
        $this->validate($file);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext = self::ALLOWED[$mime] ?? null;
        if ($ext === null) {
            throw new RuntimeException('Unsupported image type. Upload JPG, PNG, or WebP.');
        }
        $id = bin2hex(random_bytes(12));
        $directory = root_path('uploads/media/' . gmdate('Y/m'));
        $thumbDirectory = root_path('uploads/thumbnails/' . gmdate('Y/m'));
        foreach ([$directory, $thumbDirectory] as $path) {
            if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
                throw new RuntimeException('Unable to create the upload directory.');
            }
        }
        $filename = $id . '.' . $ext;
        $destination = $directory . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to store the uploaded image.');
        }
        @chmod($destination, 0644);
        [$width, $height] = getimagesize($destination) ?: [0, 0];
        $relative = 'media/' . gmdate('Y/m') . '/' . $filename;

        $thumbnailRelative = $relative;
        if ($thumbnailFile && ($thumbnailFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $this->validate($thumbnailFile, 5 * 1024 * 1024);
            $thumbMime = (new \finfo(FILEINFO_MIME_TYPE))->file($thumbnailFile['tmp_name']);
            $thumbExt = self::ALLOWED[$thumbMime] ?? null;
            if ($thumbExt === null) {
                throw new RuntimeException('Custom thumbnail must be JPG, PNG, or WebP.');
            }
            $thumbFilename = $id . '-thumb.' . $thumbExt;
            $thumbDestination = $thumbDirectory . '/' . $thumbFilename;
            if (!move_uploaded_file($thumbnailFile['tmp_name'], $thumbDestination)) {
                throw new RuntimeException('Unable to store the custom thumbnail.');
            }
            @chmod($thumbDestination, 0644);
            $thumbnailRelative = 'thumbnails/' . gmdate('Y/m') . '/' . $thumbFilename;
        } else {
            $generated = $this->makeThumbnail($destination, $thumbDirectory . '/' . $id . '-thumb.jpg');
            if ($generated) {
                $thumbnailRelative = 'thumbnails/' . gmdate('Y/m') . '/' . $id . '-thumb.jpg';
            }
        }

        return [
            'id' => $id,
            'title' => pathinfo((string) ($file['name'] ?? 'Image'), PATHINFO_FILENAME),
            'type' => in_array($type, ['model', 'background', 'thumbnail', 'export'], true) ? $type : 'model',
            'file' => $relative,
            'thumbnail' => $thumbnailRelative,
            'width' => $width,
            'height' => $height,
            'tags' => [],
            'createdAt' => now_iso(),
        ];
    }

    private function validate(array $file, int $maxBytes = 25 * 1024 * 1024): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'The image exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'The image exceeds the form upload limit.',
                UPLOAD_ERR_PARTIAL => 'The image upload was interrupted.',
                UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
            ];
            throw new RuntimeException($messages[$error] ?? 'The image could not be uploaded.');
        }
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            throw new RuntimeException('The image is too large. Maximum size is ' . (int) ($maxBytes / 1024 / 1024) . ' MB.');
        }
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('The uploaded file could not be verified.');
        }
    }

    private function makeThumbnail(string $source, string $destination): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }
        $info = getimagesize($source);
        if (!$info) {
            return false;
        }
        [$width, $height, $type] = $info;
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if (!$image) {
            return false;
        }
        $targetW = 480;
        $targetH = 360;
        $scale = max($targetW / $width, $targetH / $height);
        $scaledW = (int) ceil($width * $scale);
        $scaledH = (int) ceil($height * $scale);
        $srcX = max(0, (int) (($scaledW - $targetW) / 2 / $scale));
        $srcY = max(0, (int) (($scaledH - $targetH) / 2 / $scale));
        $srcW = min($width, (int) ($targetW / $scale));
        $srcH = min($height, (int) ($targetH / $scale));
        $thumb = imagecreatetruecolor($targetW, $targetH);
        imagecopyresampled($thumb, $image, 0, 0, $srcX, $srcY, $targetW, $targetH, $srcW, $srcH);
        $ok = imagejpeg($thumb, $destination, 86);
        imagedestroy($image);
        imagedestroy($thumb);
        return $ok;
    }
}
