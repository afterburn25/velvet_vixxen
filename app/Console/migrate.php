<?php
declare(strict_types=1);

// A database update must never invoke installer recovery or any public web bootstrap.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/includes/autoload.php';
App\Core\OperationsGate::bootstrap(ROOT_PATH);
require ROOT_PATH . '/includes/installation_recovery.php';

$options = getopt('', ['status', 'apply', 'backup-confirmed', 'backup-reference:', 'reviewed-failure', 'plan:', 'help']);
if (isset($options['help'])) {
    echo "Inspect pending updates without changing the database:\n  php app/Console/migrate.php --status\n\nApply after verifying a fresh database backup and copies of config/app.php, storage/install.lock and customer uploads:\n  php app/Console/migrate.php --apply --backup-confirmed --backup-reference='Hosting backup ID or verified backup location'\n\nUse --plan=HASH to require a previously reviewed status plan. After a failed or interrupted run, inspect partial database changes before adding --reviewed-failure. No automatic rollback runs on an installed site.\n";
    exit(0);
}
try {
    $updater = new App\Core\DatabaseUpdateService(ROOT_PATH);
    $status = $updater->preview();
    if (!isset($options['apply'])) {
        echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    $result = $updater->apply(is_string($options['plan'] ?? null) ? $options['plan'] : (string) $status['plan'],
        isset($options['backup-confirmed']), is_string($options['backup-reference'] ?? null) ? $options['backup-reference'] : '',
        isset($options['reviewed-failure']));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Database update: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
