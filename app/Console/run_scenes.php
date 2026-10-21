<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
// A scene worker has no reason to start add-ons or execute unrelated jobs.
require ROOT_PATH . '/includes/bootstrap.php';
if (!is_installed()) { fwrite(STDERR, "Application is not installed.\n"); exit(1); }

try {
    $options = getopt('', ['limit:']);
    $limit = $options['limit'] ?? '10';
    if (!is_string($limit) || !preg_match('/\A(?:[1-9]|1[0-9]|2[0-5])\z/D', $limit)) {
        throw new InvalidArgumentException('Use --limit=1 through --limit=25.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $stats = app_store()->runSceneSchedules((int) $limit);
    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($stats['held'] > 0 ? 2 : 0);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Scene scheduling could not finish. Check the saved schedule and application storage, then try again.\n");
    exit(1);
}
