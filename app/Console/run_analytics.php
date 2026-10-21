<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/app/bootstrap.php';
require ROOT_PATH . '/includes/marketing.php';
if (!is_installed()) { fwrite(STDERR, "Application is not installed.\n"); exit(1); }
try {
    if (count($argv) > 2 || (isset($argv[1]) && !preg_match('/\A--limit=([0-9]{1,3})\z/D', $argv[1], $match))) throw new InvalidArgumentException('Use --limit=1 through --limit=250.');
    $limit = isset($match[1]) ? (int) $match[1] : 100;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $stats = velvet_marketing_store()->runAnalyticsMaintenance(app_store(), $limit);
    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Measurement maintenance could not finish. Saved source records were preserved; review the installation and measurement settings.\n"); exit(1);
}
