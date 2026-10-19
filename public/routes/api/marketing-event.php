<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require_once ROOT_PATH . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/marketing.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); http_response_code(405); echo json_encode(['ok' => false]); exit;
}
$originOf = static function (string $url): string {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) return '';
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
};
$expectedOrigin = $originOf(base_url());
$requestOrigin = $originOf((string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''));
if ($expectedOrigin === '' || $requestOrigin !== $expectedOrigin) {
    http_response_code(403); echo json_encode(['ok' => false]); exit;
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 2048) {
    http_response_code(413); echo json_encode(['ok' => false]); exit;
}
$raw = file_get_contents('php://input', false, null, 0, 2049);
$data = json_decode((string) $raw, true);
if (strlen((string) $raw) > 2048 || !is_array($data)
    || array_diff(array_keys($data), ['event', 'path']) !== []
    || !is_string($data['event'] ?? null) || !is_string($data['path'] ?? null)) {
    http_response_code(400); echo json_encode(['ok' => false]); exit;
}
try {
    velvet_marketing_store()->recordEvent($data['event'], ['path' => $data['path']]);
    echo json_encode(['ok' => true]);
} catch (Throwable $error) {
    http_response_code(400); echo json_encode(['ok' => false]);
}
