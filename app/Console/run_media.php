<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/app/bootstrap.php';
if (!is_installed()) { fwrite(STDERR, "Application is not installed.\n"); exit(1); }

try {
    $options = getopt('', ['limit:']);
    $limit = $options['limit'] ?? '100';
    if (!is_string($limit) || !ctype_digit($limit) || strlen($limit) > 3 || (int) $limit < 1 || (int) $limit > 500) {
        throw new InvalidArgumentException('Use --limit=1 through --limit=500.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $stats = app_store()->publishDueMedia((int) $limit);
    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Media publication could not finish. Check the application storage and try again.\n");
    exit(1);
}
