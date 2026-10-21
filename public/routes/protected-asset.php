<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/includes/bootstrap.php';
header('Cache-Control: no-store, private, max-age=0'); header('X-Robots-Tag: noindex, nofollow');
try {
    if (!is_installed()) throw new RuntimeException('Media is unavailable.', 404);
    $key = $_GET['file'] ?? null;
    if (!is_string($key)) throw new RuntimeException('Media is unavailable.', 404);
    $delivery = new App\Core\ProtectionDelivery(app_config());
    $entry = $delivery->resolve($key);
    App\Core\PrivateMediaResponse::serve($entry['absolute'], $entry['mime'], static fn() => $delivery->resolve($key));
} catch (Throwable $error) {
    http_response_code(in_array((int) $error->getCode(), [403, 404, 405, 416, 503], true) ? (int) $error->getCode() : 503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'This media is unavailable.';
}
