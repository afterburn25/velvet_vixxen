<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/includes/bootstrap.php';
if (!is_installed()) { fwrite(STDERR, "Application is not installed.\n"); exit(1); }
try {
    $options = getopt('', ['limit:', 'discover:']);
    foreach (array_slice($argv, 1) as $argument) {
        if (!is_string($argument) || preg_match('/\A--(?:limit|discover)=[0-9]+\z/D', $argument) !== 1) throw new InvalidArgumentException('Use --limit=1 through --limit=25 and optional --discover=1 through --discover=250.');
    }
    $limit = filter_var($options['limit'] ?? '10', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 25]]);
    $discover = filter_var($options['discover'] ?? '100', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 250]]);
    if ($limit === false || $discover === false) throw new InvalidArgumentException('Use --limit=1 through --limit=25 and --discover=1 through --discover=250.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $service = new \App\Core\NotificationService(app_config(), app_store());
    $planning = $service->discover($discover);
    $delivery = $service->run($limit);
    echo json_encode(['discovery' => $planning, 'delivery' => $delivery], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($delivery['failed'] > 0 || $delivery['unknown'] > 0 ? 2 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Notification run failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
