<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/app/bootstrap.php';
if (!is_installed()) {
    fwrite(STDERR, "Application is not installed.\n");
    exit(1);
}
try {
    $stats = commerce_service()->processDueSubscriptions();
    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($stats['failed'] ?? 0) > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Billing run failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
