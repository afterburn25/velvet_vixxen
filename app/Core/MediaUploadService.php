<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class MediaUploadService
{
    /** @var array<string,array{extension:string,kind:string}> */
    private const TYPES = [
        'image/jpeg' => ['extension' => 'jpg', 'kind' => 'image'],
        'image/png' => ['extension' => 'png', 'kind' => 'image'],
        'image/webp' => ['extension' => 'webp', 'kind' => 'image'],
        'audio/mpeg' => ['extension' => 'mp3', 'kind' => 'audio'],
        'audio/mp4' => ['extension' => 'm4a', 'kind' => 'audio'],
        'audio/x-m4a' => ['extension' => 'm4a', 'kind' => 'audio'],
        'audio/aac' => ['extension' => 'aac', 'kind' => 'audio'],
        'audio/wav' => ['extension' => 'wav', 'kind' => 'audio'],
        'audio/x-wav' => ['extension' => 'wav', 'kind' => 'audio'],
        'audio/flac' => ['extension' => 'flac', 'kind' => 'audio'],
        'video/mp4' => ['extension' => 'mp4', 'kind' => 'video'],
        'video/webm' => ['extension' => 'webm', 'kind' => 'video'],
        'video/quicktime' => ['extension' => 'mov', 'kind' => 'video'],
        'text/vtt' => ['extension' => 'vtt', 'kind' => 'caption'],
        'application/x-subrip' => ['extension' => 'srt', 'kind' => 'caption'],
        'text/plain' => ['extension' => 'vtt', 'kind' => 'caption'],
    ];

    /**
     * @return array<string,mixed>
     */
    public function upload(array $file, string $requestedKind, bool $private = false, ?array $thumbnailFile = null): array
    {
        $requestedKind = strtolower(trim($requestedKind));
        $limits = [
            'image' => 30 * 1024 * 1024,
            'poster' => 30 * 1024 * 1024,
            'gallery' => 30 * 1024 * 1024,
            'audio' => 250 * 1024 * 1024,
            'video' => 2 * 1024 * 1024 * 1024,
            'trailer' => 750 * 1024 * 1024,
            'caption' => 5 * 1024 * 1024,
        ];
        $this->validate($file, $limits[$requestedKind] ?? 30 * 1024 * 1024);

        $tmp = (string) ($file['tmp_name'] ?? '');
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $definition = self::TYPES[$mime] ?? null;
        if ($definition === null) {
            throw new RuntimeException('Unsupported media type. Use JPG, PNG, WebP, MP3, M4A, AAC, WAV, FLAC, MP4, WebM, MOV, VTT, or SRT.');
        }

        $actualKind = $definition['kind'];
        $allowedKind = match ($requestedKind) {
            'poster', 'gallery', 'image' => $actualKind === 'image',
            'trailer', 'video' => $actualKind === 'video',
            'audio' => $actualKind === 'audio',
            'caption' => $actualKind === 'caption',
            default => false,
        };
        if (!$allowedKind) {
            throw new RuntimeException('The selected file does not match the requested media type.');
        }

        $id = bin2hex(random_bytes(12));
        $yearMonth = gmdate('Y/m');
        $bucket = match ($requestedKind) {
            'gallery', 'image' => 'images',
            'poster' => 'images',
            'trailer', 'video' => 'videos',
            'audio' => 'audio',
            'caption' => 'captions',
            default => 'media',
        };
        $filename = $id . '.' . $definition['extension'];
        $relative = ($private ? 'private:' : '') . $bucket . '/' . $yearMonth . '/' . $filename;
        $destination = $private ? root_path('storage/private/media/' . $bucket . '/' . $yearMonth . '/' . $filename)
            : (new ProtectionUploads(app_config()))->destination($relative);
        $base = dirname($destination);
        if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
            throw new RuntimeException('Unable to create the media upload directory.');
        }

        if (!move_uploaded_file($tmp, $destination)) {
            throw new RuntimeException('Unable to store the uploaded media file.');
        }
        @chmod($destination, str_contains($destination, '/storage/private/') ? 0600 : 0644);

        $width = 0;
        $height = 0;
        if ($actualKind === 'image') {
            [$width, $height] = getimagesize($destination) ?: [0, 0];
        }
        $duration = $this->probeDuration($destination);
        $thumbnail = '';

        if ($thumbnailFile && (int) ($thumbnailFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $thumbnailAsset = $this->upload($thumbnailFile, 'poster', false, null);
            $thumbnail = (string) $thumbnailAsset['file'];
        } elseif ($actualKind === 'image') {
            // A public, reduced-size thumbnail is generated even when the original is private.
            // This lets locked galleries show safe previews without exposing original files.
            $thumbnail = $this->createThumbnail($destination, $id, $yearMonth) ?: ($private ? '' : $relative);
        }

        return [
            'id' => $id,
            'title' => pathinfo((string) ($file['name'] ?? ucfirst($requestedKind)), PATHINFO_FILENAME),
            'type' => $requestedKind,
            'file' => $relative,
            'thumbnail' => $thumbnail,
            'mime' => $mime,
            'size' => (int) ($file['size'] ?? filesize($destination) ?: 0),
            'width' => (int) $width,
            'height' => (int) $height,
            'duration' => $duration,
            'private' => $private,
            'tags' => [],
            'metadata' => ['originalName' => (string) ($file['name'] ?? '')],
            'createdAt' => now_iso(),
        ];
    }

    private function validate(array $file, int $maxBytes): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'The file exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'The file exceeds the form upload limit.',
                UPLOAD_ERR_PARTIAL => 'The upload was interrupted.',
                UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
            ];
            throw new RuntimeException($messages[$error] ?? 'The file could not be uploaded.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('The file size is invalid or exceeds the allowed limit.');
        }
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('The uploaded file could not be verified.');
        }
    }

    private function createThumbnail(string $source, string $id, string $yearMonth): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }
        $info = @getimagesize($source);
        if (!$info) {
            return null;
        }
        [$width, $height, $type] = $info;
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if (!$image) {
            return null;
        }
        $targetWidth = 640;
        $targetHeight = 480;
        $scale = max($targetWidth / max(1, $width), $targetHeight / max(1, $height));
        $sourceWidth = min($width, (int) round($targetWidth / $scale));
        $sourceHeight = min($height, (int) round($targetHeight / $scale));
        $sourceX = max(0, (int) round(($width - $sourceWidth) / 2));
        $sourceY = max(0, (int) round(($height - $sourceHeight) / 2));
        $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled($thumb, $image, 0, 0, $sourceX, $sourceY, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        $relative = 'thumbnails/' . $yearMonth . '/' . $id . '-thumb.jpg';
        $destination = (new ProtectionUploads(app_config()))->destination($relative);
        $directory = dirname($destination);
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
        $ok = imagejpeg($thumb, $destination, 86);
        imagedestroy($image);
        imagedestroy($thumb);
        if (!$ok) {
            return null;
        }
        @chmod($destination, str_contains($destination, '/storage/private/') ? 0600 : 0644);
        return $relative;
    }

    private function probeDuration(string $path): ?float
    {
        $ffprobe = trim((string) @shell_exec('command -v ffprobe 2>/dev/null'));
        if ($ffprobe === '') {
            return null;
        }
        $command = escapeshellcmd($ffprobe) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($path) . ' 2>/dev/null';
        $value = trim((string) @shell_exec($command));
        return is_numeric($value) ? round((float) $value, 3) : null;
    }
}
