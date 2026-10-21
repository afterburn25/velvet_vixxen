<?php
declare(strict_types=1);

$source = dirname(__DIR__, 2);
require_once $source . '/includes/version.php';
require_once $source . '/app/Core/RuntimeBuildService.php';
$fixture = sys_get_temp_dir() . '/vvx-runtime-build-' . bin2hex(random_bytes(8));
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$reject = static function (callable $action, string $message) use ($assert): void {
    $rejected = false;
    try { $action(); } catch (RuntimeException) { $rejected = true; }
    $assert($rejected, $message);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    @rmdir($path);
};
try {
    $assert(trim((string) file_get_contents($source . '/VERSION')) === VELVET_VIXXEN_VERSION, 'Visible runtime version differs from canonical VERSION.');
    $assert(VELVET_VIXXEN_ASSET_VERSION === VELVET_VIXXEN_VERSION . '-' . VELVET_VIXXEN_BUILD_ID, 'Browser assets do not identify the same canonical release/build.');
    $files = ['admin/update.php', 'admin/index.php', 'app/Core/RuntimeBuildService.php', 'includes/version.php',
        'app/Views/admin/composer.php', 'public/assets/js/composer.js', 'public/assets/js/api-client.js', 'public/assets/js/scene-geometry.js',
        'admin/repair.php', 'app/Core/RuntimeInventoryService.php', 'app/Core/RootLayoutService.php',
        'app/Views/admin/repair-status.php', 'route.php', 'public/routes/api/upload.php',
        'public/routes/api/scene.php', 'app/Support/public_routes.php', 'public/router.php',
        'app/Core/MenuService.php',
        'app/Core/MenuStoreTrait.php',
        'app/Support/menu_admin.php',
        'app/Views/admin/menus-actions.php',
        'app/Views/admin/menus.php',
        'app/Views/public/partials/menu-items.php',
        'public/assets/css/menu-admin.css',
        'public/assets/css/navigation.css',
        'public/assets/css/site-ux.css',
        'public/assets/js/menu-admin.js',
        'public/assets/js/navigation.js',
    ];
    $hashes = [];
    foreach ($files as $relative) {
        $directory = dirname($fixture . '/' . $relative);
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        copy($source . '/' . $relative, $fixture . '/' . $relative);
        $hashes[$relative] = hash_file('sha256', $fixture . '/' . $relative);
    }
    foreach (['config/app.php', 'storage/data.json', 'storage/install.lock', 'uploads/owner-photo.txt'] as $private) {
        if (!is_dir(dirname($fixture . '/' . $private))) mkdir(dirname($fixture . '/' . $private), 0700, true);
        file_put_contents($fixture . '/' . $private, 'Synthetic existing site state: ' . $private);
        $preserved[$private] = hash_file('sha256', $fixture . '/' . $private);
    }
    $manifest = ['schema' => 1, 'product' => 'Velvet Vixxen', 'version' => VELVET_VIXXEN_VERSION,
        'build_id' => VELVET_VIXXEN_BUILD_ID, 'files' => $hashes];
    $write = static function (array $data) use ($fixture): void {
        file_put_contents($fixture . '/includes/runtime-build.json', json_encode($data, JSON_THROW_ON_ERROR));
    };
    $write($manifest);
    $service = new App\Core\RuntimeBuildService($fixture);
    $status = $service->inspect();
    $assert($status['files_match'] && $status['files_checked'] === count($files), 'A complete file map must verify.');
    $assert($status['running_build_matches'], 'The running build identity must be reported.');

    // Old files can have the same size and mtime as the replacement. Hash contents, not metadata.
    $target = $fixture . '/app/Views/admin/composer.php';
    $original = file_get_contents($target);
    $mtime = filemtime($target);
    file_put_contents($target, str_replace('Add Button', 'Old Button', $original));
    touch($target, $mtime);
    $status = $service->inspect();
    $assert(!$status['files_match'] && $status['problems'] === [
        ['path' => 'app/Views/admin/composer.php', 'status' => 'Different file contents'],
    ], 'Same-size/same-time old Composer must be identified by contents.');
    $reject(static fn() => $service->refreshPhpCache(), 'Incomplete deployment must not activate via cache refresh.');
    file_put_contents($target, $original);
    unlink($fixture . '/public/routes/api/upload.php');
    $status = $service->inspect();
    $assert(!$status['files_match'] && $status['problems'][0]['path'] === 'public/routes/api/upload.php', 'Missing organized upload handler must be identified.');
    copy($source . '/public/routes/api/upload.php', $fixture . '/public/routes/api/upload.php');
    unlink($fixture . '/public/assets/js/scene-geometry.js');
    $status = $service->inspect();
    $assert(!$status['files_match'] && $status['problems'][0]['path'] === 'public/assets/js/scene-geometry.js', 'Missing shared scene geometry must be identified.');
    $reject(static fn() => $service->refreshPhpCache(), 'A missing scene dependency must block cache activation.');
    copy($source . '/public/assets/js/scene-geometry.js', $fixture . '/public/assets/js/scene-geometry.js');

    unlink($fixture . '/public/assets/js/menu-admin.js');
    $status = $service->inspect();
    $assert(!$status['files_match'] && $status['problems'][0]['path'] === 'public/assets/js/menu-admin.js', 'A missing menu editor script must be identified.');
    $reject(static fn() => $service->refreshPhpCache(), 'Missing menu dependencies must block cache activation.');
    copy($source . '/public/assets/js/menu-admin.js', $fixture . '/public/assets/js/menu-admin.js');

    rename($target, $fixture . '/composer-outside.php');
    symlink($fixture . '/composer-outside.php', $target);
    $assert(!$service->inspect()['files_match'], 'A linked runtime file must not be read as a verified replacement.');
    $reject(static fn() => $service->refreshPhpCache(), 'A linked runtime file must block cache activation.');
    unlink($target);
    rename($fixture . '/composer-outside.php', $target);

    foreach (['config/app.php', 'storage/data.json', 'uploads/owner-photo.txt', '../foreign.php', '/tmp/foreign.php',
        'app/../config/app.php', 'app\\Core\\Other.php', 'addons/installed/private.php', 'includes/runtime-build.json', 'api/upload.php', 'media.php', 'account/index.php'] as $unsafe) {
        $invalid = $manifest;
        $invalid['files'][$unsafe] = str_repeat('0', 64);
        $write($invalid);
        $reject(static fn() => $service->inspect(), 'Private or escaping manifest path must fail: ' . $unsafe);
    }
    $invalid = $manifest;
    unset($invalid['files']['public/routes/api/scene.php']);
    $write($invalid);
    $reject(static fn() => $service->inspect(), 'Manifest cannot omit the scene endpoint and claim a complete update.');
    $invalid = $manifest;
    unset($invalid['files']['public/assets/js/scene-geometry.js']);
    $write($invalid);
    $reject(static fn() => $service->inspect(), 'Current manifest cannot omit the shared scene geometry dependency.');
    $invalid['version'] = '0.8.9';
    $invalid['build_id'] = '20261020.10';
    $write($invalid);
    $legacy = $service->inspect();
    $assert($legacy['files_match'] && !$legacy['running_build_matches'] && $legacy['version'] === '0.8.9', 'Older inventories must remain inspectable without a dependency introduced later.');
    $menuDependencies = [
        'app/Core/MenuService.php',
        'app/Core/MenuStoreTrait.php',
        'app/Support/menu_admin.php',
        'app/Views/admin/menus-actions.php',
        'app/Views/admin/menus.php',
        'app/Views/public/partials/menu-items.php',
        'public/assets/css/menu-admin.css',
        'public/assets/css/navigation.css',
        'public/assets/css/site-ux.css',
        'public/assets/js/menu-admin.js',
        'public/assets/js/navigation.js',
    ];
    foreach ($menuDependencies as $dependency) {
        $incomplete = $manifest;
        unset($incomplete['files'][$dependency]);
        $write($incomplete);
        $reject(static fn() => $service->inspect(), 'Current inventory cannot omit menu dependency: ' . $dependency);
    }
    $beforeMenus = $manifest;
    foreach ($menuDependencies as $dependency) unset($beforeMenus['files'][$dependency]);
    $beforeMenus['version'] = '0.8.10';
    $beforeMenus['build_id'] = '20261020.11';
    $write($beforeMenus);
    $legacyMenus = $service->inspect();
    $assert($legacyMenus['files_match'] && !$legacyMenus['running_build_matches'] && $legacyMenus['version'] === '0.8.10', 'A pre-menu inventory remains inspectable without later menu dependencies.');
    file_put_contents($fixture . '/includes/runtime-build.json', '{invalid json');
    $reject(static fn() => $service->inspect(), 'Malformed manifest must fail readably.');
    unlink($fixture . '/includes/runtime-build.json');
    $reject(static fn() => $service->inspect(), 'Missing manifest must not imply successful deployment.');
    $write($manifest);
    rename($fixture . '/includes', $fixture . '/outside-includes');
    symlink($fixture . '/outside-includes', $fixture . '/includes');
    $reject(static fn() => $service->inspect(), 'Linked parent must not expose or verify a manifest.');
    unlink($fixture . '/includes');
    rename($fixture . '/outside-includes', $fixture . '/includes');
    $assert($service->inspect()['files_match'], 'Restoring all intended runtime bytes must restore verification.');
    foreach ($preserved as $path => $hash) {
        $assert(hash_file('sha256', $fixture . '/' . $path) === $hash, 'Build checks changed private installed state: ' . $path);
    }
    echo "PASS runtime build verification: {$checks} assertions\n";
} finally {
    $remove($fixture);
}
