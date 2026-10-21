<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/includes/bootstrap.php';
if (!is_installed()) { fwrite(STDERR, "Application is not installed.\n"); exit(1); }
try {
    $options = getopt('', ['limit:']);
    foreach (array_slice($argv, 1) as $argument) if (!is_string($argument) || preg_match('/\A--limit=[0-9]+\z/D', $argument) !== 1) throw new InvalidArgumentException('Use --limit=1 through --limit=250.');
    $limit = filter_var($options['limit'] ?? '100', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 250]]);
    if ($limit === false) throw new InvalidArgumentException('Use --limit=1 through --limit=250.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $stats = (new \App\Core\MailProviderService(app_config(), app_store()))->reconcile($limit);
    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Provider reconciliation failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
