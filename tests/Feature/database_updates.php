<?php
declare(strict_types=1);

$sourceRoot = dirname(__DIR__, 2);
$updateFixture = sys_get_temp_dir() . '/vvx-database-update-test-' . bin2hex(random_bytes(6));
foreach (['config', 'storage', 'uploads', 'install', 'includes', 'app/Core', 'app/Console'] as $directory) mkdir($updateFixture . '/' . $directory, 0700, true);
require_once $sourceRoot . '/includes/autoload.php';
require_once $sourceRoot . '/includes/installation_recovery.php';
$updateChecks = 0;
$assertUpdate = static function (bool $condition, string $message) use (&$updateChecks): void {
    if (!$condition) throw new RuntimeException($message);
    $updateChecks++;
};
$rejectUpdate = static function (callable $action, string $message) use ($assertUpdate): void {
    $failed = false;
    try { $action(); } catch (RuntimeException) { $failed = true; }
    $assertUpdate($failed, $message);
};
$removeUpdateFixture = static function (string $path) use (&$removeUpdateFixture): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    @chmod($path, 0700);
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $removeUpdateFixture($path . '/' . $entry);
    @rmdir($path);
};
try {
    $fixtureConfig = ['installed' => true, 'installation_id' => 'fixture-database-updates-installation', 'storage_mode' => 'file',
        'security' => ['app_key' => str_repeat('a', 48), 'signing_key' => str_repeat('s', 48), 'media_key' => str_repeat('m', 48)]];
    $configBytes = "<?php\nreturn " . var_export($fixtureConfig, true) . ";\n";
    $lockBytes = json_encode(['installed' => true, 'installation_id' => $fixtureConfig['installation_id']], JSON_THROW_ON_ERROR);
    file_put_contents($updateFixture . '/config/app.php', $configBytes);
    file_put_contents($updateFixture . '/storage/install.lock', $lockBytes);
    file_put_contents($updateFixture . '/uploads/customer-fixture.txt', 'Existing upload must remain intact.');
    file_put_contents($updateFixture . '/install/fixture.txt', 'No CLI bootstrap installer retirement.');
    $service = new \App\Core\DatabaseUpdateService($updateFixture);
    $preview = $service->preview();
    $assertUpdate($preview['mode'] === 'file' && $preview['pending'] === [], 'File storage must not pretend it needs SQL migrations.');
    $assertUpdate($service->apply('', false, '')['status'] === 'not_needed', 'File-storage apply must be a harmless no-op.');
    $assertUpdate(!file_exists($updateFixture . '/storage/private'), 'Read-only/no-op checks must not create update runtime state.');
    $assertUpdate(file_get_contents($updateFixture . '/config/app.php') === $configBytes && file_get_contents($updateFixture . '/storage/install.lock') === $lockBytes,
        'Inspecting updates changed installation state.');

    file_put_contents($updateFixture . '/storage/install.lock', json_encode(['installed' => true, 'installation_id' => 'different-fixture-id']));
    $rejectUpdate(static fn() => $service->preview(), 'A contradictory installation lock must block updates.');
    file_put_contents($updateFixture . '/storage/install.lock', $lockBytes);
    rename($updateFixture . '/config/app.php', $updateFixture . '/config/original.php');
    symlink($updateFixture . '/config/original.php', $updateFixture . '/config/app.php');
    $rejectUpdate(static fn() => $service->preview(), 'Linked active configuration must not be executed.');
    unlink($updateFixture . '/config/app.php');
    rename($updateFixture . '/config/original.php', $updateFixture . '/config/app.php');
    rename($updateFixture . '/storage/install.lock', $updateFixture . '/storage/original.lock');
    symlink($updateFixture . '/storage/original.lock', $updateFixture . '/storage/install.lock');
    $rejectUpdate(static fn() => $service->preview(), 'Linked installation lock must block updates.');
    unlink($updateFixture . '/storage/install.lock');
    rename($updateFixture . '/storage/original.lock', $updateFixture . '/storage/install.lock');
    $badKeys = $fixtureConfig;
    $badKeys['security']['app_key'] = '';
    file_put_contents($updateFixture . '/config/app.php', "<?php\nreturn " . var_export($badKeys, true) . ";\n");
    $rejectUpdate(static fn() => $service->preview(), 'Missing active security keys must block updates.');
    file_put_contents($updateFixture . '/config/app.php', $configBytes);

    // Run the real CLI from a minimal disposable installation with no app/bootstrap.php.
    foreach (['includes/autoload.php', 'includes/installation_recovery.php', 'app/Core/DatabaseUpdateService.php', 'app/Console/migrate.php'] as $relative) {
        copy($sourceRoot . '/' . $relative, $updateFixture . '/' . $relative);
    }
    $runCli = static function (array $arguments) use ($updateFixture): array {
        $process = proc_open(array_merge([PHP_BINARY, $updateFixture . '/app/Console/migrate.php'], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Unable to run the disposable update CLI.');
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return ['exit' => proc_close($process), 'output' => $output, 'error' => $error];
    };
    $cliStatus = $runCli(['--status']);
    $assertUpdate($cliStatus['exit'] === 0 && (json_decode($cliStatus['output'], true)['mode'] ?? '') === 'file', 'CLI status failed without the public bootstrap.');
    $cliApply = $runCli(['--apply']);
    $assertUpdate($cliApply['exit'] === 0 && (json_decode($cliApply['output'], true)['status'] ?? '') === 'not_needed', 'File-storage CLI apply did not safely explain its no-op.');
    $assertUpdate(is_file($updateFixture . '/install/fixture.txt'), 'Update CLI retired the installer or invoked public bootstrap.');
    $assertUpdate(file_get_contents($updateFixture . '/uploads/customer-fixture.txt') === 'Existing upload must remain intact.', 'Database update handling modified a customer upload.');
    $assertUpdate(file_get_contents($updateFixture . '/config/app.php') === $configBytes && file_get_contents($updateFixture . '/storage/install.lock') === $lockBytes,
        'CLI status/apply changed the existing configuration or lock.');
    echo "PASS database updates: {$updateChecks} assertions (disposable file-storage/CLI fixtures only)\n";
} finally {
    $removeUpdateFixture($updateFixture);
}
