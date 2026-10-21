<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class PrivateMediaResponse
{
    /** The final authorization check follows file opening and range validation. */
    public static function serve(string $path, string $mime, callable $authorize): never
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD'); http_response_code(405); exit;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('Media is unavailable.', 404);
        try {
            $stat = fstat($handle); $size = $stat['size'] ?? 0;
            if (!is_int($size) || $size < 1) throw new RuntimeException('Media is unavailable.', 404);
            $start = 0; $end = $size - 1; $range = $_SERVER['HTTP_RANGE'] ?? '';
            if (!is_string($range)) throw new RuntimeException('Invalid byte range.', 416);
            if ($range !== '') {
                if (!preg_match('/\Abytes=(\d{0,19})-(\d{0,19})\z/D', $range, $match) || ($match[1] === '' && $match[2] === '')) throw new RuntimeException('Invalid byte range.', 416);
                if ($match[1] === '') $start = max(0, $size - (int) $match[2]);
                else { $start = (int) $match[1]; if ($match[2] !== '') $end = min($end, (int) $match[2]); }
                if ($start > $end || $start >= $size || ($match[1] === '' && (int) $match[2] === 0)) throw new RuntimeException('Invalid byte range.', 416);
            }
            $authorize();
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/wav', 'audio/x-wav', 'audio/flac', 'video/mp4', 'video/webm', 'video/quicktime', 'text/vtt', 'text/plain', 'application/x-subrip'];
            header('Content-Type: ' . (in_array($mime, $allowed, true) ? $mime : 'application/octet-stream'));
            header("Content-Security-Policy: default-src 'none'; sandbox");
            header('Cache-Control: private, no-store, max-age=0'); header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow, noarchive');
            header('Content-Disposition: inline'); header('Accept-Ranges: bytes');
            http_response_code($range === '' ? 200 : 206);
            header('Content-Length: ' . ($end - $start + 1));
            if ($range !== '') header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
            fseek($handle, $start); $remaining = $end - $start + 1;
            while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
                $bytes = fread($handle, min(1048576, $remaining));
                if ($bytes === false || $bytes === '') break;
                echo $bytes; $remaining -= strlen($bytes);
            }
        } finally { fclose($handle); }
        exit;
    }
}
