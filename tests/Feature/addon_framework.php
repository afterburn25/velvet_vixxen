<?php
declare(strict_types=1);

use App\Core\AddonManager;
use App\Core\AddonRuntime;

require dirname(__DIR__) . '/TestEnvironment.php';

$environment = velvet_test_environment('addon-framework');
$store = $environment['store'];
$root = $environment['root'];
$failures = [];
$addonId = 'velvet.welcome-banner';
$installedPath = $root . '/addons/installed/' . $addonId;

$delete = static function (string $path) use (&$delete): void {
    if (!file_exists($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $child) $delete($child->getPathname());
        @rmdir($path);
    } else {
        @unlink($path);
    }
};
$delete($installedPath);
foreach (glob($root . '/storage/backups/addons/velvet-welcome-banner-*.zip') ?: [] as $backup) @unlink($backup);

try {
    $manager = new AddonManager($store);
    $review = $manager->stageBundledStarter();
    if (($review['operation'] ?? '') !== 'install') $failures[] = 'Bundled starter was not staged as an install.';

    $untrustedRejected = false;
    try {
        $manager->install((string) $review['token'], false, true);
    } catch (RuntimeException $e) {
        $untrustedRejected = str_contains(strtolower($e->getMessage()), 'trust');
    }
    if (!$untrustedRejected) $failures[] = 'Unsigned package was installable without explicit trust.';

    $installed = $manager->install((string) $review['token'], true, true);
    if (($installed['status'] ?? '') !== 'active' || ($installed['version'] ?? '') !== '1.0.0') $failures[] = 'Starter add-on was not installed and activated.';
    if (!is_file($installedPath . '/addon.json')) $failures[] = 'Installed add-on files are missing.';

    $store->setAddonSetting($addonId, 'headline', 'Automated Add-on Test');
    $runtime = new AddonRuntime($store);
    $runtime->boot();
    if (!isset($runtime->adminPages()['welcome-banner'])) $failures[] = 'Add-on admin page was not registered.';
    if (!in_array('welcome', $runtime->publicRouteKeys(), true)) $failures[] = 'Add-on public route was not registered.';
    $admin = (string) $runtime->renderAdminPage('welcome-banner');
    $route = (string) $runtime->renderPublicRoute('welcome');
    $widget = $runtime->renderHook('homepage.after_sets');
    if (!str_contains($admin, 'Welcome Banner')) $failures[] = 'Add-on admin page did not render.';
    if (!str_contains($route, 'Automated Add-on Test')) $failures[] = 'Add-on public route did not read namespaced settings.';
    if (!str_contains($widget, 'Automated Add-on Test')) $failures[] = 'Add-on homepage widget did not render.';
    $service = $runtime->service('velvet.welcome-banner.welcome-copy');
    if (!is_callable($service) || $service() !== 'Automated Add-on Test') $failures[] = 'Add-on service registration failed.';
    if (!isset($runtime->tasks()['velvet.welcome-banner.refresh-banner'])) $failures[] = 'Add-on scheduled task was not registered.';
    $runtime->runTask('velvet.welcome-banner.refresh-banner');
    $task = $store->addonTask($addonId, 'refresh-banner');
    if (($task['lastStatus'] ?? '') !== 'completed' || empty($task['lastRunAt']) || empty($store->addonSetting($addonId, 'last_task_run'))) {
        $failures[] = 'Registered add-on task did not execute and persist its result.';
    }
    $rebootedRuntime = new AddonRuntime($store);
    $rebootedRuntime->boot();
    $taskAfterBoot = $store->addonTask($addonId, 'refresh-banner');
    if (empty($taskAfterBoot['lastRunAt'])) $failures[] = 'Add-on task history was lost during runtime registration.';

    $runtime->dispatch('member.registered', ['memberId' => 'member-addon-test']);
    $event = $store->addonSetting($addonId, 'last_member_event', []);
    if (($event['memberId'] ?? '') !== 'member-addon-test') $failures[] = 'Add-on event listener did not run.';
    $runtime->dispatch('order.completed', ['order' => ['orderNumber' => 'VVX-ADDON-TEST']]);
    $orderEvent = $store->addonSetting($addonId, 'last_order_event', []);
    if (($orderEvent['orderNumber'] ?? '') !== 'VVX-ADDON-TEST') $failures[] = 'Core commerce event did not reach the add-on listener.';

    $manager->deactivate($addonId);
    $inactiveRuntime = new AddonRuntime($store);
    $inactiveRuntime->boot();
    if ($inactiveRuntime->adminPages() !== [] || $inactiveRuntime->publicRouteKeys() !== []) $failures[] = 'Deactivated add-on still registered runtime components.';
    $manager->activate($addonId);

    // Exercise the repair/update path and verify a restorable package backup was created.
    $repairReview = $manager->stageBundledStarter();
    if (($repairReview['operation'] ?? '') !== 'repair') $failures[] = 'Same-version package was not classified as a repair.';
    $manager->install((string) $repairReview['token'], true, true);
    $backups = $store->addonBackups($addonId);
    if ($backups === [] || !is_file($root . '/' . (string) $backups[0]['path'])) $failures[] = 'Pre-repair add-on backup was not created.';
    $rolledBack = $manager->rollbackLatest($addonId);
    if (($rolledBack['version'] ?? '') !== '1.0.0' || ($rolledBack['status'] ?? '') !== 'active') {
        $failures[] = 'One-click add-on rollback did not restore an active package.';
    }

    // File-mode migration registry and targeted rollback.
    $migrations = [[
        'id' => 'test_create_table', 'checksum' => hash('sha256', 'test_create_table'),
        'up' => ['CREATE TABLE ignored_in_file_mode'], 'down' => ['DROP TABLE ignored_in_file_mode'],
    ]];
    $keys = $store->applyAddonMigrations($addonId, $migrations);
    if ($keys !== ['test_create_table'] || !isset($store->addonMigrations($addonId)['test_create_table'])) $failures[] = 'Add-on migration registry did not record a migration.';
    $store->rollbackAddonMigrationKeys($addonId, $migrations, $keys);
    if (isset($store->addonMigrations($addonId)['test_create_table'])) $failures[] = 'Targeted add-on migration rollback failed.';

    $manager->setSafeMode(true);
    $safeRuntime = new AddonRuntime($store);
    $safeRuntime->boot();
    if ($safeRuntime->adminPages() !== []) $failures[] = 'Safe mode did not suppress add-on boot.';
    $manager->setSafeMode(false);

    $manager->uninstall($addonId, true);
    if ($store->addon($addonId) !== null || is_dir($installedPath)) $failures[] = 'Add-on uninstall did not remove registry and files.';
    if ($store->addonSettings($addonId) !== []) $failures[] = 'Purge uninstall retained namespaced settings.';
    foreach ($store->rawFileData()['addonTasks'] ?? [] as $task) {
        if (($task['addonKey'] ?? '') === $addonId) $failures[] = 'Uninstall retained scheduled add-on tasks.';
    }
} catch (Throwable $e) {
    $failures[] = 'Unexpected add-on lifecycle exception: ' . $e->getMessage();
} finally {
    $delete($installedPath);
    foreach (glob($root . '/storage/backups/addons/velvet-welcome-banner-*.zip') ?: [] as $backup) @unlink($backup);
    foreach (glob($root . '/addons/staging/*') ?: [] as $path) {
        if (basename($path) !== '.gitkeep') $delete($path);
    }
    velvet_test_cleanup($environment);
}

velvet_finish_test('Add-on framework feature test', $failures);
