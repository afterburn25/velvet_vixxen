<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
require ROOT_PATH . '/includes/bootstrap.php';
require_once ROOT_PATH . '/app/Support/member_privacy.php';
vvx_privacy_private();
if (!is_installed()) { http_response_code(404); exit('Not found.'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); http_response_code(405); exit('Method not allowed.'); }
try {
    $id = $_GET['id'] ?? null;
    if (!is_string($id)) throw new RuntimeException('This export is unavailable.', 404);
    $privacy = new App\Core\MemberPrivacyService(app_config(), app_store());
    [$member, $token] = vvx_privacy_member($privacy);
    $bytes = $privacy->download($id, (string) $member['id'], $token);
    // Send the bytes whose complete length, SHA-256 and ZIP CRC were just verified.
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="velvet-vixxen-account-data.zip"');
    header('Content-Length: ' . (string) strlen($bytes));
    header('X-Archive-SHA256: ' . hash('sha256', $bytes));
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    echo $bytes;
} catch (Throwable $caught) {
    $code = in_array($caught->getCode(), [403, 404], true) ? (int) $caught->getCode() : 503;
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $code === 403 ? 'Sign in to the verified account that requested this export.' : 'This private export is unavailable or expired.';
}
