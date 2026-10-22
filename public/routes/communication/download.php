<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
require ROOT_PATH . '/app/bootstrap.php';
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
if (!is_installed()) { http_response_code(404); exit('Not found.'); }
$store = app_store();
$kind = $_GET['kind'] ?? null; $id = $_GET['id'] ?? '';
if (!is_string($id) || ($kind !== null && !is_string($kind))) { http_response_code(404); exit('Attachment not found.'); }
$attachment = $store->communicationAttachment($id, $kind);
if (!$attachment) { http_response_code(404); exit('Attachment not found.'); }
$authorize = static function () use ($store, $attachment): bool {
    if (app_auth()->check()) return true;
    $member = current_member();
    return $member !== null && $store->memberCanReadCommunicationAttachment($attachment, (string) $member['id']);
};
if (!$authorize()) { http_response_code(403); exit('Attachment access denied.'); }
try {
    $handle = \App\Core\PrivateCommunicationFile::open(ROOT_PATH, $attachment);
    if (!$authorize()) { fclose($handle); http_response_code(403); exit('Attachment access denied.'); }
    $name = basename(str_replace('\\', '/', (string) ($attachment['originalName'] ?? 'attachment')));
    $name = str_replace(["\r", "\n", '"'], '', $name) ?: 'attachment';
    $mime = (string) ($attachment['mimeType'] ?? 'application/octet-stream');
    if (!preg_match('~\A[a-z0-9.+-]+/[a-z0-9.+-]+\z~iD', $mime)) $mime = 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) $attachment['fileSize']);
    header("Content-Disposition: attachment; filename=\"attachment\"; filename*=UTF-8''" . rawurlencode($name));
    fpassthru($handle); fclose($handle);
} catch (Throwable $error) {
    http_response_code(in_array($error->getCode(), [404, 409], true) ? $error->getCode() : 503);
    echo 'Attachment is unavailable.';
}
