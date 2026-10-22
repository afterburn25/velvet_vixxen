<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/includes/bootstrap.php';
if (!is_installed()) { fwrite(STDERR, "Application is not installed.\n"); exit(1); }
try {
    $options = getopt('', ['limit:']);
    foreach (array_slice($argv, 1) as $argument) if (!is_string($argument) || preg_match('/\A--limit=[0-9]+\z/D', $argument) !== 1) throw new InvalidArgumentException('Use --limit=1 through --limit=25.');
    if (count($argv) > 2) throw new InvalidArgumentException('Supply --limit only once.');
    $limit = filter_var($options['limit'] ?? '10', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 25]]);
    if ($limit === false) throw new InvalidArgumentException('Use --limit=1 through --limit=25.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $result = (new App\Core\MemberPrivacyService(app_config(), app_store()))->run($limit);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['held'] > 0 ? 2 : 0);
} catch (Throwable $caught) { fwrite(STDERR, 'Privacy processing failed: ' . $caught->getMessage() . PHP_EOL); exit(1); }
