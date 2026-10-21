<?php
declare(strict_types=1);

// This worker deliberately takes the exclusive gate itself, without web
// bootstrap, add-ons, installer recovery, sessions or privileged HTTP routes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/includes/autoload.php';
require ROOT_PATH . '/includes/version.php';
require ROOT_PATH . '/app/Support/functions.php';

try { $options = App\Core\OperationsFiles::options( ['key-file:', 'verify:', 'help']); } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
if (isset($options['help'])) {
    echo "Run one queued or due daily encrypted snapshot, then apply reviewed retention:\n  php app/Console/run_backups.php\n\nVerify one complete archive:\n  php app/Console/run_backups.php --verify=BACKUP_ID\n\nThe external key is configured through operations.php. --key-file=/private/path may select an original key for verification. Keys themselves never belong on a command line. Exit 0 means finished, 2 reports a held/failed attempt, and 1 means the worker could not finish.\n";
    exit(0);
}
try {
    $config = new App\Core\Config(ROOT_PATH . '/config/app.php');
    $service = new App\Core\BackupService($config);
    $key = isset($options['key-file']) && is_string($options['key-file']) ? $options['key-file'] : null;
    $result = isset($options['verify']) ? $service->verify((string)$options['verify'], $key) : $service->run($key);
    echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(($result['held'] ?? 0) + ($result['failed'] ?? 0) > 0 ? 2 : 0);
} catch (Throwable $error) {
    $message = $error instanceof PDOException || $error->getPrevious() instanceof PDOException
        ? 'Storage could not be read. Check the private hosting logs and database availability; existing archives were preserved.'
        : ($error instanceof RuntimeException || $error instanceof InvalidArgumentException ? $error->getMessage() : 'The backup worker could not finish. Existing archives were preserved.');
    fwrite(STDERR, 'Backup: ' . $message . PHP_EOL); exit(1);
}
