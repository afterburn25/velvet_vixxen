<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/app/bootstrap.php';

if (!is_installed()) {
    fwrite(STDERR, "Velvet Vixxen is not installed.\n");
    exit(1);
}
$runtime = addon_runtime();
$runtime->boot();
$selected = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--task=')) $selected = trim(substr($argument, 7));
}
try {
    $result = $selected !== null && $selected !== ''
        ? ['task' => $selected, 'result' => $runtime->runTask($selected)]
        : $runtime->runDueTasks();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
