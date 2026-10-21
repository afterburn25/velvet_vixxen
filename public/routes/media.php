<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 2)); }
require ROOT_PATH . '/app/bootstrap.php';

if (!is_installed()) {
    http_response_code(404);
    exit;
}

$id = (string) ($_GET['id'] ?? '');
$expires = (int) ($_GET['expires'] ?? 0);
$signature = (string) ($_GET['signature'] ?? '');
$key = (string) app_config('security.media_key', '');
$payload = $id . '|' . $expires;
$valid = $id !== '' && $expires >= time() && $expires <= time() + 86400 && $key !== ''
    && preg_match('/^[a-f0-9]{64}$/', $signature) === 1
    && hash_equals(hash_hmac('sha256', $payload, $key), $signature);

if (!$valid) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'This protected media link is invalid or expired.';
    exit;
}

$asset = app_store()->mediaAsset($id);
if (!(new App\Core\ProtectionRecords(app_config()))->publicationAllowed('asset', $id, $asset)) {
    http_response_code(403); exit('This media is unavailable.');
}
$stored = (string) ($asset['file'] ?? '');
if (!$asset || !str_starts_with($stored, 'private:')) {
    http_response_code(404);
    exit;
}

$relative = ltrim(substr($stored, strlen('private:')), '/');
$base = realpath(root_path('storage/private/media'));
$path = realpath(root_path('storage/private/media/' . $relative));
if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit;
}

$size = filesize($path);
if ($size === false) {
    http_response_code(500);
    exit;
}
$mime = (string) ($asset['mime'] ?? '');
if ($mime === '') {
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
}
$mime = $mime !== '' ? $mime : 'application/octet-stream';
if (!(new App\Core\ProtectionRecords(app_config()))->publicationAllowed('asset', $id, $asset)) {
    http_response_code(403); exit('This media is unavailable.');
}

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Content-Disposition: inline; filename="' . rawurlencode(basename($path)) . '"');

$start = 0;
$end = $size - 1;
$status = 200;
$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $matches) === 1) {
    if ($matches[1] !== '') {
        $start = max(0, (int) $matches[1]);
    }
    if ($matches[2] !== '') {
        $end = min($end, (int) $matches[2]);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $status = 206;
}

$length = $end - $start + 1;
http_response_code($status);
header('Content-Length: ' . $length);
if ($status === 206) {
    header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
}

$handle = fopen($path, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}
fseek($handle, $start);
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(1024 * 1024, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) {
        break;
    }
    flush();
}
fclose($handle);
