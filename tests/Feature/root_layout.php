<?php
declare(strict_types=1);

namespace App\Core {
    // Real filesystem effects with deterministic race/write-denial controls.
    function rename(string $source, string $target): bool
    {
        if (($GLOBALS['layout_deny_rename'] ?? '') === $source) return false;
        if (($GLOBALS['layout_change_before_capture'] ?? '') === $source) {
            unset($GLOBALS['layout_change_before_capture']);
            \file_put_contents($source, 'Owner edited this file during the repair.');
        }
        $result = \rename($source, $target);
        if ($result && ($GLOBALS['layout_replace_after_capture'] ?? '') === $source) {
            unset($GLOBALS['layout_replace_after_capture']);
            \file_put_contents($source, 'Owner created a new file after capture.');
        }
        return $result;
    }
    function file_get_contents(string $path): string|false
    {
        if (!empty($GLOBALS['layout_corrupt_archive']) && str_ends_with($path, '/forwarders.json')) {
            unset($GLOBALS['layout_corrupt_archive']);
            \file_put_contents($path, '{incomplete archive');
        }
        return \file_get_contents($path);
    }
}

namespace {
    $source = dirname(__DIR__, 2);
    require_once $source . '/includes/version.php';
    require_once $source . '/app/Core/RootLayoutService.php';
    $historical = json_decode(file_get_contents($source . '/tests/Fixtures/root-layout-build5.json'), true, 32, JSON_THROW_ON_ERROR);
    $old = $historical['files'];
    $fixtures = sys_get_temp_dir() . '/vvx-root-layout-' . bin2hex(random_bytes(8));
    mkdir($fixtures, 0700, true);
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
    $write = static function (string $path, string $contents): void {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
        if (file_put_contents($path, $contents) !== strlen($contents)) throw new RuntimeException('Fixture write failed.');
    };
    $remove = static function (string $path) use (&$remove): void {
        if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
        @rmdir($path);
    };
    $required = [
        'admin/update.php', 'admin/index.php', 'app/Core/RuntimeBuildService.php', 'includes/version.php',
        'app/Views/admin/composer.php', 'public/assets/js/composer.js', 'public/assets/js/api-client.js', 'public/assets/js/scene-geometry.js',
        'app/Core/SceneDepth.php', 'public/assets/js/scene-depth.js',
        'app/Core/MarketingCalendar.php',
        'app/Views/admin/media-center.php',
        'app/Core/MarketingPublication.php',
        'app/Core/MarketingHistory.php',
        'app/Core/MarketingStorage.php',
        'app/Core/MarketingStore.php',
        'app/Core/Store.php',
        'app/Views/admin/marketing.php',
        'app/Views/admin/marketing-calendar.php',
        'app/Views/admin/marketing-publication-editor.php',
        'app/Views/admin/marketing-publication-list.php',
        'app/Views/admin/marketing-history.php',
        'includes/marketing.php',
        'includes/marketing_admin.php',
        'public/assets/css/marketing-calendar.css',
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
    $freshRoots = ['addons', 'admin', 'app', 'config', 'database', 'images', 'includes', 'install', 'public', 'storage', 'templates', 'uploads', 'vendor'];
    $newRoot = static function (string $name, bool $seedOld = true) use ($source, $fixtures, $old, $required, $write, $freshRoots): string {
        $root = $fixtures . '/' . $name;
        foreach ($freshRoots as $directory) mkdir($root . '/' . $directory, 0755, true);
        $hashes = [];
        foreach ($required as $relative) {
            $write($root . '/' . $relative, file_get_contents($source . '/' . $relative));
            $hashes[$relative] = hash_file('sha256', $root . '/' . $relative);
        }
        $write($root . '/includes/runtime-build.json', json_encode([
            'schema' => 1, 'product' => 'Velvet Vixxen', 'version' => VELVET_VIXXEN_VERSION,
            'build_id' => VELVET_VIXXEN_BUILD_ID, 'files' => $hashes,
        ], JSON_THROW_ON_ERROR));
        foreach (['config/app.php', 'storage/data.json', 'storage/install.lock', 'storage/private/private-fixture.txt',
            'uploads/owner-fixture.txt', 'database/custom-state.txt', 'addons/installed/owner-addon.txt',
            'custom-project/note.txt'] as $private) {
            $write($root . '/' . $private, 'Synthetic owner data must remain: ' . $private);
        }
        if ($seedOld) foreach ($old as $relative => $contents) $write($root . '/' . $relative, $contents);
        return $root;
    };
    $assertOld = static function (string $root, string $message) use ($assert, $old): void {
        foreach ($old as $relative => $contents) {
            $assert(is_file($root . '/' . $relative) && file_get_contents($root . '/' . $relative) === $contents, $message . ': ' . $relative);
        }
    };
    $assertArchive = static function (string $root, array $result, array $expected) use ($assert): void {
        $record = $root . '/' . $result['archive'];
        $data = json_decode(file_get_contents($record), true, 32, JSON_THROW_ON_ERROR);
        $assert(array_keys($data['entries']) === array_keys($expected), 'Archive must contain only the exact known files selected for this repair.');
        foreach ($expected as $relative => $bytes) {
            $entry = $data['entries'][$relative];
            $assert($entry['encoding'] === 'base64' && base64_decode($entry['contents'], true) === $bytes
                && $entry['sha256'] === hash('sha256', $bytes), 'Every archived file must recover its exact original bytes: ' . $relative);
        }
        $assert(!str_contains(file_get_contents($record), '<?php'), 'Archived forwarding code must be encoded data, never an executable PHP body.');
        $assert((fileperms($record) & 0777) === 0640 && (fileperms(dirname($record)) & 0777) === 0750, 'New private records must use restricted, non-world-writable permissions.');
        $assert(file_get_contents(dirname($record) . '/.htaccess') === "Require all denied\n", 'Private archive must include its own access denial.');
        $assert(glob(dirname($record) . '/*.php') === [] && glob(dirname($record) . '/*.data') === [], 'Successful repair must leave no executable PHP backups or temporary captures.');
    };

    try {
        $assert(count($old) === 46 && $historical['source_commit'] === '2bc178013c9c528f45a5d0447624f3cbaf4395f2', 'The negative fixture must preserve all 46 files from the actual rejected Build .5.');
        $root = $newRoot('complete');
        $private = [];
        foreach (['config/app.php', 'storage/data.json', 'storage/install.lock', 'storage/private/private-fixture.txt',
            'uploads/owner-fixture.txt', 'database/custom-state.txt', 'addons/installed/owner-addon.txt', 'custom-project/note.txt'] as $relative) {
            $private[$relative] = [hash_file('sha256', $root . '/' . $relative), fileperms($root . '/' . $relative)];
        }
        $service = new App\Core\RootLayoutService($root);
        $before = $service->inspect();
        $assert(count($before['known_files']) === 46 && count($before['directories']) === 25 && !$before['organized'], 'Negative control: replacing application files alone must expose all rejected .5 root folders still present.');
        $assert(!is_dir($root . '/storage/private/root-layout'), 'Read-only inspection must not create runtime state.');
        $result = $service->retire();
        $assert(count($result['retired']) === 46 && count($result['removed_directories']) === 25 && $result['failed'] === [], 'All exact historical shims and all 25 now-empty root route folders must retire.');
        $assertArchive($root, $result, $old);
        $assert($service->inspect()['organized'] && !file_exists($root . '/media.php'), 'The organized state must include retiring the additional root media.php.');
        foreach ($private as $relative => [$hash, $mode]) {
            $assert(hash_file('sha256', $root . '/' . $relative) === $hash && fileperms($root . '/' . $relative) === $mode, 'Owner data and permissions must remain unchanged: ' . $relative);
        }
        foreach ($freshRoots as $directory) $assert(is_dir($root . '/' . $directory), 'An approved root folder must remain: ' . $directory);
        $archives = glob($root . '/storage/private/root-layout/retired-*');
        $again = $service->retire();
        $assert($again['retired'] === [] && $again['archive'] === '' && glob($root . '/storage/private/root-layout/retired-*') === $archives, 'Repeated cleanup must be an idempotent no-op without another archive.');

        foreach (['missing-inventory', 'mixed-runtime', 'old-build', 'unsafe-inventory'] as $failure) {
            $root = $newRoot($failure);
            $manifestPath = $root . '/includes/runtime-build.json';
            $manifest = json_decode(file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
            if ($failure === 'missing-inventory') unlink($manifestPath);
            if ($failure === 'mixed-runtime') $write($root . '/route.php', 'Old or incomplete router bytes.');
            if ($failure === 'old-build') { $manifest['build_id'] = '20261019.5'; $write($manifestPath, json_encode($manifest)); }
            if ($failure === 'unsafe-inventory') { $manifest['files']['config/app.php'] = hash_file('sha256', $root . '/config/app.php'); $write($manifestPath, json_encode($manifest)); }
            $service = new App\Core\RootLayoutService($root);
            $reject(static fn() => $service->retire(), 'An unverified or inactive new runtime must block cleanup: ' . $failure);
            $assertOld($root, 'Refused cleanup must preserve every prior route');
            $assert(!is_dir($root . '/storage/private/root-layout'), 'Refused runtime verification must not create repair state.');
        }

        $root = $newRoot('custom-items');
        $custom = ['about/index.php' => $old['about/index.php'] . "\n// Owner comment.\n", 'api/custom.php' => 'Owner integration.',
            'account/old-uploads/unique.bin' => "Unique owner bytes\0\xff", 'account/.htaccess' => '# Owner rewrite rules.'];
        foreach ($custom as $relative => $bytes) $write($root . '/' . $relative, $bytes);
        mkdir($root . '/account/custom-empty', 0755);
        $service = new App\Core\RootLayoutService($root);
        $result = $service->retire();
        $assert(count($result['retired']) === 45 && count($result['removed_directories']) === 22 && $result['failed'] === [], 'Only exact unchanged files and actually empty directories may retire.');
        $expected = $old; unset($expected['about/index.php']);
        $assertArchive($root, $result, $expected);
        foreach ($custom as $relative => $bytes) $assert(file_get_contents($root . '/' . $relative) === $bytes, 'Custom sibling must stay at its existing location: ' . $relative);
        $assert(is_dir($root . '/account/custom-empty') && !$result['remaining']['organized'] && !$result['remaining']['has_work'], 'Unknown empty descendants must keep their parent, with readable custom-preserved state.');
        $preservedPaths = array_column($result['remaining']['preserved'], 'path');
        foreach (['about/index.php', 'api/custom.php', 'account/old-uploads', 'account/.htaccess', 'account/custom-empty'] as $path) {
            $assert(in_array($path, $preservedPaths, true), 'Owner feedback must identify preserved custom items: ' . $path);
        }

        $root = $newRoot('links');
        $outside = $fixtures . '/outside';
        $write($outside . '/upload.php', $old['api/upload.php']);
        $write($outside . '/owner.txt', 'Outside data must not be opened or moved.');
        $remove($root . '/account');
        symlink($outside, $root . '/account');
        unlink($root . '/api/upload.php');
        symlink($outside . '/upload.php', $root . '/api/upload.php');
        symlink($outside, $root . '/shop/custom-link');
        $service = new App\Core\RootLayoutService($root);
        $result = $service->retire();
        $assert(is_link($root . '/account') && is_link($root . '/api/upload.php') && is_link($root . '/shop/custom-link'), 'Linked files, roots and custom siblings must all remain in place.');
        $assert(file_get_contents($outside . '/upload.php') === $old['api/upload.php'] && file_get_contents($outside . '/owner.txt') === 'Outside data must not be opened or moved.', 'Cleanup must never alter a linked outside target even when its bytes match a known stub.');
        $assert($result['remaining']['preserved'] !== [] && !$result['remaining']['organized'], 'Symlinks must be reported as preserved, never counted as a clean root.');

        foreach (['linked-archive', 'blocked-archive', 'custom-lock', 'incomplete-archive'] as $failure) {
            $root = $newRoot($failure);
            if ($failure === 'linked-archive') symlink($outside, $root . '/storage/private/root-layout');
            if ($failure === 'blocked-archive') $write($root . '/storage/private/root-layout', 'Owner existing file.');
            if ($failure === 'custom-lock') $write($root . '/storage/private/root-layout/repair.lock', 'Owner existing private lock.');
            if ($failure === 'incomplete-archive') $GLOBALS['layout_corrupt_archive'] = true;
            $service = new App\Core\RootLayoutService($root);
            $reject(static fn() => $service->retire(), 'An unsafe or unverified private archive must prevent removal: ' . $failure);
            $assertOld($root, 'Archive refusal must preserve all original routes');
            if ($failure === 'custom-lock') $assert(file_get_contents($root . '/storage/private/root-layout/repair.lock') === 'Owner existing private lock.'
                && (fileperms($root . '/storage/private/root-layout/repair.lock') & 0777) === 0644, 'Unknown private lock contents and permissions must not be rewritten.');
            unset($GLOBALS['layout_corrupt_archive']);
        }

        $root = $newRoot('permission-failure');
        $GLOBALS['layout_deny_rename'] = $root . '/api/upload.php';
        $service = new App\Core\RootLayoutService($root);
        $result = $service->retire();
        unset($GLOBALS['layout_deny_rename']);
        $assert(count($result['retired']) === 45 && count($result['failed']) === 1 && $result['remaining']['known_files'] === ['api/upload.php'], 'Permission denial must produce accurate partial results and preserve the original entry.');
        $assert(file_get_contents($root . '/api/upload.php') === $old['api/upload.php'], 'Denied file retirement must not alter the original bytes.');
        $assertArchive($root, $result, $old);
        $savedArchive = file_get_contents($root . '/' . $result['archive']);
        $retry = $service->retire();
        $assert($retry['retired'] === ['api/upload.php'] && $retry['remaining']['organized'], 'A partial repair must finish on retry without restoring retired files.');
        $assert(file_get_contents($root . '/' . $result['archive']) === $savedArchive, 'Retry must preserve the previous archive unchanged.');

        foreach (['before-capture', 'after-capture'] as $race) {
            $root = $newRoot($race);
            $flag = $race === 'before-capture' ? 'layout_change_before_capture' : 'layout_replace_after_capture';
            $GLOBALS[$flag] = $root . '/api/upload.php';
            $service = new App\Core\RootLayoutService($root);
            $result = $service->retire();
            $expectedOwner = $race === 'before-capture' ? 'Owner edited this file during the repair.' : 'Owner created a new file after capture.';
            $assert(file_get_contents($root . '/api/upload.php') === $expectedOwner, 'A concurrent owner change must survive at its original path: ' . $race);
            $assert(!$result['remaining']['organized'] && !$result['remaining']['has_work'], 'Concurrent custom bytes must be reported as preserved and must not be retried as known runtime.');
            $assert($race === 'after-capture' || count($result['failed']) === 1, 'A changed atomic capture must report that the original was restored.');
            $assertArchive($root, $result, $old);
        }

        $root = $newRoot('empty-directories', false);
        mkdir($root . '/about', 0755);
        mkdir($root . '/account/custom-empty', 0755, true);
        $service = new App\Core\RootLayoutService($root);
        $result = $service->retire();
        $assert($result['retired'] === [] && $result['removed_directories'] === ['about'] && is_dir($root . '/account/custom-empty'), 'Only an empty allowlisted top-level directory may be removed; never descend into custom empty folders.');
        $assertArchive($root, $result, []);
        echo "PASS safe root-layout repair: {$checks} assertions (literal rejected Build .5 files and disposable fixtures)\n";
    } finally {
        unset($GLOBALS['layout_deny_rename'], $GLOBALS['layout_change_before_capture'], $GLOBALS['layout_replace_after_capture'], $GLOBALS['layout_corrupt_archive']);
        $remove($fixtures);
    }
}
