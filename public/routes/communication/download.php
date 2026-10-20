<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require ROOT_PATH . '/app/bootstrap.php';

if (!is_installed()) { http_response_code(404); exit; }
$store = app_store();
$attachment = $store->communicationAttachment((string) ($_GET['id'] ?? ''));
if (!$attachment) { http_response_code(404); exit('Attachment not found.'); }
$adminAllowed = app_auth()->check();
$member = current_member();
$memberAllowed = false;
if (!$adminAllowed && $member) {
    if ((string) ($attachment['kind'] ?? '') === 'message') {
        $message = $store->communicationMessage((string) ($attachment['messageId'] ?? ''));
        $thread = $message ? $store->communicationThread((string) ($message['threadId'] ?? '')) : null;
        $memberAllowed = $thread && (string) ($thread['memberId'] ?? '') === (string) $member['id'];
    } else {
        $type = (string) ($attachment['requestType'] ?? '');
        $request = $type === 'content_request'
            ? $store->contentRequest((string) ($attachment['requestId'] ?? ''))
            : ($type === 'booking_request' ? $store->bookingRequest((string) ($attachment['requestId'] ?? '')) : null);
        $memberAllowed = $request && (string) ($request['memberId'] ?? '') === (string) $member['id'];
    }
}
if (!$adminAllowed && !$memberAllowed) { http_response_code(403); exit('Access denied.'); }
$stored = (string) ($attachment['filePath'] ?? '');
if (!str_starts_with($stored, 'private:')) { http_response_code(404); exit('Attachment not found.'); }
$relative = ltrim(substr($stored, 8), '/\\');
$privateRoot = realpath(root_path('storage/private'));
$file = realpath(root_path('storage/private/' . $relative));
if ($privateRoot === false || $file === false || !str_starts_with($file, $privateRoot . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404); exit('Attachment not found.');
}
$name = preg_replace('/[^\pL\pN._() -]+/u', '_', basename((string) ($attachment['originalName'] ?? 'attachment'))) ?: 'attachment';
$mime = (string) ($attachment['mimeType'] ?? 'application/octet-stream');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($file));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($file);
