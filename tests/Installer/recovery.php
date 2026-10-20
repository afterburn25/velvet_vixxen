<?php
declare(strict_types=1);

// Load helpers directly. Booting the source checkout can retire its /install directory.
$sourceRoot = dirname(__DIR__, 2);
require_once $sourceRoot . '/includes/installation_recovery.php';
require_once $sourceRoot . '/app/Core/Config.php';
require_once $sourceRoot . '/install/services/InstallerLog.php';
require_once $sourceRoot . '/install/services/FileSystemService.php';
require_once $sourceRoot . '/install/services/InstallerService.php';

$fixtures = sys_get_temp_dir() . '/vvx-recovery-test-' . bin2hex(random_bytes(8));
mkdir($fixtures, 0700, true);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$write = static function (string $path, string $contents): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    if (file_put_contents($path, $contents) !== strlen($contents)) throw new RuntimeException('Fixture write failed.');
};
$configText = static fn(array $config): string => "<?php\nreturn " . var_export($config, true) . ";\n";
$config = static fn(string $id, string $key = 'a'): array => [
    'installed' => true, 'installation_id' => $id, 'version' => 'test-installed-version',
    'base_url' => 'https://example.invalid', 'storage_mode' => 'mysql',
    // Invalid DSN host forces a read-only verification failure without network access.
    'db' => ['host' => 'invalid;host', 'port' => 3306, 'name' => 'fixture', 'user' => 'fixture', 'password' => ''],
    'security' => ['app_key' => str_repeat($key, 48), 'signing_key' => str_repeat($key, 49), 'media_key' => str_repeat($key, 50)],
];
$lock = static fn(string $id): string => json_encode(['installed' => true, 'installation_id' => $id], JSON_THROW_ON_ERROR);
$newRoot = static function (string $name) use ($fixtures, $write): string {
    $root = $fixtures . '/' . $name;
    $write($root . '/config/app.php', "<?php\nreturn ['installed' => false];\n");
    $write($root . '/storage/private/customer.txt', 'private fixture sentinel');
    $write($root . '/uploads/existing.bin', 'uploaded fixture sentinel');
    return $root;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
    }
    @rmdir($path);
};

try {
    $a = $config('installation-current', 'a');
    $b = $config('installation-prior', 'b');
    $assert(vvx_recovery_lock_matches($a, json_decode($lock($a['installation_id']), true)), 'A matching lock must verify.');
    foreach ([[], ['installed' => true, 'installation_id' => 'other'], ['installed' => false, 'installation_id' => $a['installation_id']],
        ['installed' => 'true', 'installation_id' => $a['installation_id']], ['installed' => true, 'installation_id' => []]] as $badLock) {
        $assert(!vvx_recovery_lock_matches($a, $badLock), 'Invalid lock identity must not verify.');
    }

    $root = $newRoot('active-matched');
    $activeText = $configText($a);
    $write($root . '/config/app.php', $activeText);
    $write($root . '/storage/install.lock', $lock($a['installation_id']));
    $write($root . '/.vvx-private-repair-config-old/app.php', $configText($b));
    $write($root . '/.vvx-private-repair-storage-old/install.lock', $lock($b['installation_id']));
    $assert(vvx_attempt_automatic_installation_recovery($root)['status'] === 'installed', 'Valid installed state must not be repaired.');
    $assert(file_get_contents($root . '/config/app.php') === $activeText, 'Valid active config must stay byte-identical.');
    $assert(!is_dir($root . '/storage/private/repair-backups'), 'Healthy boot must not create an archive.');
    $assert(vvx_attempt_automatic_installation_recovery($root)['status'] === 'skipped', 'Recovery should be checked once per root per request.');

    $root = $newRoot('missing-lock');
    $write($root . '/config/app.php', $activeText);
    $older = $config($a['installation_id'], 'z');
    $write($root . '/config/app.php.previous', $configText($older));
    $write($root . '/.vvx-private-repair-storage-old/install.lock', $lock($a['installation_id']));
    $result = vvx_attempt_automatic_installation_recovery($root);
    $assert($result['status'] === 'recovered', 'Matching archived lock must repair missing active lock.');
    $assert(file_get_contents($root . '/config/app.php') === $activeText, 'Missing-lock recovery must not replace current keys with older keys.');
    $assert(vvx_recovery_lock_matches($a, vvx_recovery_load_lock($root . '/storage/install.lock')), 'Restored lock must match active config.');
    $assert(file_get_contents($root . '/config/app.php.previous') === $configText($older), 'Recovery source backups must be preserved.');
    $assert(vvx_attempt_automatic_installation_recovery($root, true)['status'] === 'installed', 'Repeated recovery must be idempotent.');

    $root = $newRoot('concurrent-recovery');
    $write($root . '/config/app.php', $activeText);
    $write($root . '/.vvx-private-repair-storage-current/install.lock', $lock($a['installation_id']));
    $guard = fopen($root . '/storage/private/.installation-recovery.guard', 'c+b');
    flock($guard, LOCK_EX);
    $assert(vvx_attempt_automatic_installation_recovery($root)['status'] === 'pending', 'A concurrent recovery pass must not replace files while another request holds the guard.');
    $assert(!file_exists($root . '/storage/install.lock') && file_get_contents($root . '/config/app.php') === $activeText, 'Concurrent recovery must preserve current state while waiting.');
    flock($guard, LOCK_UN);
    fclose($guard);
    $assert(vvx_attempt_automatic_installation_recovery($root, true)['status'] === 'recovered', 'Recovery should resume safely after the other request releases its guard.');

    $root = $newRoot('concurrent-installer-recovery');
    $write($root . '/config/app.php', $activeText);
    $write($root . '/.vvx-private-repair-storage-current/install.lock', $lock($a['installation_id']));
    $applyGuard = fopen($root . '/storage/private/.installer-apply.lock', 'c+b');
    $assert(is_resource($applyGuard) && flock($applyGuard, LOCK_EX | LOCK_NB), 'The installer/recovery fixture must hold a real exclusive apply lock.');
    $assert(vvx_attempt_automatic_installation_recovery($root)['status'] === 'pending', 'Automatic recovery must wait while an installer holds the shared apply lock.');
    $assert(!file_exists($root . '/storage/install.lock') && file_get_contents($root . '/config/app.php') === $activeText, 'An active installation must prevent recovery config/lock writes.');
    $assert(!is_dir($root . '/storage/private/repair-backups'), 'Blocked concurrent recovery must not archive or rotate installation files.');
    flock($applyGuard, LOCK_UN);
    fclose($applyGuard);
    $assert(vvx_attempt_automatic_installation_recovery($root, true)['status'] === 'recovered', 'Ordinary recovery must work after installation releases the shared apply lock.');

    // A late failure leaves a complete installed-looking .previous file. Neither
    // automatic boot recovery nor installer retirement may activate that backup
    // while the durable pending checkpoint still requires final verification.
    foreach ([false, true] as $installedFlag) {
        foreach ([false, true] as $withLock) {
            $label = ($installedFlag ? 'finalizing' : 'staged') . ($withLock ? '-locked' : '-unlocked');
            $root = $newRoot('pending-' . $label);
            $pending = $a;
            $pending['installed'] = $installedFlag;
            $pending['installer_pending'] = true;
            $pending['db']['host'] = 'localhost';
            $pendingText = $configText($pending);
            $oldCompleted = $config($a['installation_id'], 'z');
            $oldText = $configText($oldCompleted);
            $write($root . '/config/app.php', $pendingText);
            $write($root . '/config/app.php.previous', $oldText);
            $write($root . '/.vvx-private-repair-storage-completed/install.lock', $lock($a['installation_id']));
            if ($withLock) $write($root . '/storage/install.lock', $lock($a['installation_id']));
            $write($root . '/install/retained.txt', 'unfinished installer fixture');
            $assert(!vvx_recovery_valid_config($pending), 'A pending configuration is not a completed recovery candidate: ' . $label);
            $assert(!vvx_recovery_lock_matches($pending, json_decode($lock($a['installation_id']), true)), 'A matching lock must not publicly activate a pending configuration: ' . $label);
            $result = vvx_attempt_automatic_installation_recovery($root);
            $assert(in_array($result['status'], ['pending', 'resumable'], true), 'Boot recovery must recognize the durable pending checkpoint: ' . $label);
            $assert(file_get_contents($root . '/config/app.php') === $pendingText && file_get_contents($root . '/config/app.php.previous') === $oldText, 'Pending checkpoint and prior security-key bytes must remain unchanged: ' . $label);
            $assert($withLock ? file_get_contents($root . '/storage/install.lock') === $lock($a['installation_id']) : !file_exists($root . '/storage/install.lock'), 'Recovery must neither create nor remove the pending installation lock: ' . $label);
            $assert(!is_dir($root . '/storage/private/repair-backups'), 'A pending checkpoint must not be replaced or archived automatically: ' . $label);
            // The internal pass must recheck the marker after lock acquisition,
            // not rely only on the wrapper's earlier observation.
            $applyGuard = fopen($root . '/storage/private/.installer-apply.lock', 'c+b');
            $recoveryGuard = fopen($root . '/storage/private/.installation-recovery.guard', 'c+b');
            $assert(flock($applyGuard, LOCK_EX | LOCK_NB) && flock($recoveryGuard, LOCK_EX | LOCK_NB), 'The internal pending fixture must acquire both lifecycle locks.');
            try {
                $result = vvx_recovery_restore_verified_state($root);
                $assert(in_array($result['status'], ['pending', 'resumable'], true) && file_get_contents($root . '/config/app.php') === $pendingText, 'The guarded recovery pass must also preserve pending activation: ' . $label);
            } finally {
                flock($recoveryGuard, LOCK_UN); fclose($recoveryGuard);
                flock($applyGuard, LOCK_UN); fclose($applyGuard);
            }
            vvx_retire_reintroduced_installer($root);
            $assert(is_file($root . '/install/retained.txt') && !file_exists($root . '/install/.disabled'), 'Pending setup must keep its installer usable for retry: ' . $label);
            $service = new \Velvet\Installer\InstallerService($root, new \Velvet\Installer\InstallerLog($root, 'pending-fixture'));
            try {
                $service->install([]);
                throw new RuntimeException('Missing database payload unexpectedly accepted.');
            } catch (RuntimeException $error) {
                $assert(str_contains($error->getMessage(), 'Database host'), 'A valid pending checkpoint and its own matching lock must reach ordinary installer validation: ' . $label);
            }
            $assert(file_get_contents($root . '/config/app.php') === $pendingText, 'Validation failure must not alter pending keys: ' . $label);
        }
    }

    foreach ([false, true] as $installedFlag) {
        $root = $newRoot('invalid-pending-' . ($installedFlag ? 'installed' : 'staged'));
        $invalidPending = $a;
        $invalidPending['installed'] = $installedFlag;
        $invalidPending['installer_pending'] = true;
        unset($invalidPending['security']['signing_key']);
        $raw = $configText($invalidPending);
        $write($root . '/config/app.php', $raw);
        $log = new \Velvet\Installer\InstallerLog($root, 'invalid-pending-fixture');
        $service = new \Velvet\Installer\InstallerService($root, $log);
        try {
            $service->install([]);
            throw new RuntimeException('An incomplete pending configuration was incorrectly accepted.');
        } catch (RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'Existing installation state'), 'A pending marker must not bypass missing-key safeguards.');
        }
        $assert(!vvx_recovery_valid_config($invalidPending) && file_get_contents($root . '/config/app.php') === $raw && !file_exists($log->path()), 'Invalid pending identity must stay protected without installer mutations.');
        if ($installedFlag) {
            $write($root . '/config/app.php.previous', $activeText);
            $write($root . '/storage/install.lock', $lock($a['installation_id']));
            $result = vvx_attempt_automatic_installation_recovery($root);
            $assert($result['status'] === 'blocked' && file_get_contents($root . '/config/app.php') === $raw, 'Invalid installed pending state must retain the ordinary corrupt-configuration safeguard.');
        }
    }

    $root = $newRoot('pending-unrelated-lock');
    $pending = $a;
    $pending['installed'] = true;
    $pending['installer_pending'] = true;
    $pending['db']['host'] = 'localhost';
    $write($root . '/config/app.php', $configText($pending));
    $write($root . '/storage/install.lock', $lock($b['installation_id']));
    $service = new \Velvet\Installer\InstallerService($root, new \Velvet\Installer\InstallerLog($root, 'pending-conflict-fixture'));
    try {
        $service->install([]);
        throw new RuntimeException('An unrelated lock was incorrectly accepted for continuation.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'Existing installation state'), 'Pending continuation must reject a lock from another installation.');
    }
    $assert(file_get_contents($root . '/storage/install.lock') === $lock($b['installation_id']), 'Conflicting lock evidence must be preserved.');

    $root = $newRoot('wrong-active-lock');
    $write($root . '/config/app.php', $activeText);
    $write($root . '/storage/install.lock', $lock($b['installation_id']));
    $write($root . '/config/app.php.previous', $configText($b));
    $result = vvx_attempt_automatic_installation_recovery($root);
    $assert($result['status'] === 'blocked', 'A conflicting old installation must not displace active config.');
    $assert(file_get_contents($root . '/config/app.php') === $activeText, 'Conflicting lock must preserve current config.');
    $assert(file_get_contents($root . '/storage/install.lock') === $lock($b['installation_id']), 'Unverified active lock must be preserved as evidence.');
    $write($root . '/install/unique.txt', 'installer overlay data');
    vvx_retire_reintroduced_installer($root);
    $assert(is_file($root . '/install/unique.txt'), 'Invalid config/lock pair must never retire an installer.');

    $root = $newRoot('malformed-lock');
    $write($root . '/config/app.php', $activeText);
    $write($root . '/storage/install.lock', 'malformed old lock');
    $write($root . '/.vvx-private-repair-storage-current/install.lock', $lock($a['installation_id']));
    $assert(vvx_recovery_load_lock($root . '/storage/install.lock') === [], 'Arbitrary lock contents must not mean installed.');
    $assert(vvx_attempt_automatic_installation_recovery($root)['status'] === 'recovered', 'A matching backup may replace malformed lock.');
    $savedLocks = glob($root . '/storage/private/repair-backups/automatic-recovery-*/overwritten-install.lock') ?: [];
    $assert(count($savedLocks) === 1 && file_get_contents($savedLocks[0]) === 'malformed old lock', 'Replacing a bad lock must preserve its exact old bytes.');

    $root = $newRoot('fresh-overlay');
    $placeholder = file_get_contents($root . '/config/app.php');
    $write($root . '/storage/install.lock', $lock($a['installation_id']));
    $write($root . '/.vvx-private-repair-config-saved/app.php', $activeText);
    $write($root . '/.vvx-private-repair-config-wrong/app.php', $configText($b));
    $write($root . '/.vvx-private-repair-storage-wrong/install.lock', $lock($b['installation_id']));
    touch($root . '/.vvx-private-repair-config-wrong/app.php', time() + 60);
    $assert(vvx_attempt_automatic_installation_recovery($root)['status'] === 'recovered', 'Fresh overlay must recover the config matching active installation identity.');
    $assert(file_get_contents($root . '/config/app.php') === $activeText, 'Newest unrelated backup must not win over the active lock identity.');
    $savedConfigs = glob($root . '/storage/private/repair-backups/automatic-recovery-*/overwritten-app.php') ?: [];
    $assert(count($savedConfigs) === 1 && file_get_contents($savedConfigs[0]) === $placeholder, 'Overwritten placeholder must be archived before replacement.');
    $assert(file_get_contents($root . '/uploads/existing.bin') === 'uploaded fixture sentinel', 'Customer uploads must not change during recovery.');
    $assert(file_get_contents($root . '/storage/private/customer.txt') === 'private fixture sentinel', 'Private customer data must not change during recovery.');

    $root = $newRoot('multiple-identities');
    $placeholder = file_get_contents($root . '/config/app.php');
    foreach (['a' => $a, 'b' => $b] as $label => $saved) {
        $write($root . '/.vvx-private-repair-config-' . $label . '/app.php', $configText($saved));
        $write($root . '/.vvx-private-repair-storage-' . $label . '/install.lock', $lock($saved['installation_id']));
    }
    $assert(vvx_attempt_automatic_installation_recovery($root)['status'] === 'blocked', 'Unanchored backups from multiple installations require disambiguation.');
    $assert(file_get_contents($root . '/config/app.php') === $placeholder && !file_exists($root . '/storage/install.lock'), 'Ambiguous recovery must not write config or lock.');

    $root = $newRoot('invalid-installed-config');
    $invalid = $a;
    unset($invalid['security']['signing_key']);
    $write($root . '/config/app.php', $configText($invalid));
    $write($root . '/config/app.php.previous', $activeText);
    $write($root . '/storage/install.lock', $lock($a['installation_id']));
    $assert(vvx_attempt_automatic_installation_recovery($root)['status'] === 'blocked', 'Invalid but installed active config must be preserved for repair.');
    $assert(file_get_contents($root . '/config/app.php') === $configText($invalid), 'Recovery must not silently downgrade an installed config needing repair.');
    $assert(!vvx_recovery_valid_config($invalid), 'Missing security key must fail recovery validation.');

    $root = $newRoot('invalid-backup');
    $write($root . '/config/app.php.previous', $configText($invalid));
    $write($root . '/storage/install.lock', $lock($a['installation_id']));
    $assert(vvx_attempt_automatic_installation_recovery($root)['status'] === 'not_found', 'An incomplete backup is not eligible for automatic restoration.');

    $root = $newRoot('linked-paths');
    $outside = $fixtures . '/outside';
    mkdir($outside, 0700);
    $write($outside . '/app.php', "<?php\nfile_put_contents(" . var_export($outside . '/executed', true) . ", 'bad');\nreturn " . var_export($a, true) . ";\n");
    symlink($outside, $root . '/.vvx-private-repair-config-link');
    $write($root . '/storage/install.lock', $lock($a['installation_id']));
    $assert(vvx_recovery_config_candidates($root) === [], 'Recovery candidates must reject linked parent directories.');
    $assert(vvx_recovery_load_config($root . '/.vvx-private-repair-config-link/app.php') === [], 'Config loader must reject linked ancestors.');
    $assert(!file_exists($outside . '/executed'), 'Linked backup PHP must not execute.');
    symlink($outside . '/app.php', $root . '/config/app.php.previous');
    $assert(vvx_attempt_automatic_installation_recovery($root)['status'] === 'not_found', 'Linked config files must not be recovered.');
    $write($outside . '/lock', $lock($a['installation_id']));
    symlink($outside . '/lock', $root . '/linked.lock');
    $assert(vvx_recovery_load_lock($root . '/linked.lock') === [], 'Linked lock file must not be recovery evidence.');
    $assert(!vvx_recovery_atomic_write($root . '/linked.lock', 'replacement'), 'Atomic writes must reject symbolic-link targets.');
    $assert(file_get_contents($outside . '/lock') === $lock($a['installation_id']), 'Outside symbolic-link targets must remain unchanged.');
    $assert(!vvx_recovery_safe_path($root, $root . '/config/../storage/install.lock'), 'Parent traversal must be rejected.');

    $root = $newRoot('linked-archive');
    $placeholder = file_get_contents($root . '/config/app.php');
    $write($root . '/config/app.php.previous', $activeText);
    $write($root . '/storage/install.lock', $lock($a['installation_id']));
    symlink($outside, $root . '/storage/private/repair-backups');
    $assert(vvx_attempt_automatic_installation_recovery($root)['status'] === 'failed', 'Recovery must stop when the preservation archive is linked outside the root.');
    $assert(file_get_contents($root . '/config/app.php') === $placeholder, 'Failed preservation must not replace active config.');
    $assert(count(glob($outside . '/automatic-recovery-*') ?: []) === 0, 'Recovery must never write through a linked archive parent.');

    $root = $newRoot('retire-intact');
    $write($root . '/config/app.php', $activeText);
    $write($root . '/storage/install.lock', $lock($a['installation_id']));
    $write($root . '/install/nested/unique.txt', 'unique installer overlay content');
    vvx_retire_reintroduced_installer($root);
    $archives = glob($root . '/storage/private/repair-backups/retired-installer-overlay-*') ?: [];
    $assert(!is_dir($root . '/install') && count($archives) === 1, 'Verified overlay must retire to one private archive.');
    $assert(file_get_contents($archives[0] . '/nested/unique.txt') === 'unique installer overlay content', 'Installer retirement must preserve unknown unique files.');
    $assert(file_get_contents($root . '/config/app.php') === $activeText, 'Installer retirement must preserve installed config.');

    $root = $newRoot('retirement-during-installer');
    $write($root . '/config/app.php', $activeText);
    $write($root . '/storage/install.lock', $lock($a['installation_id']));
    $write($root . '/install/nested/unique.txt', 'in-progress installer file');
    $applyGuard = fopen($root . '/storage/private/.installer-apply.lock', 'c+b');
    $assert(is_resource($applyGuard) && flock($applyGuard, LOCK_EX | LOCK_NB), 'Retirement fixture must acquire the real shared installer guard.');
    vvx_retire_reintroduced_installer($root);
    $assert(is_file($root . '/install/nested/unique.txt') && !is_dir($root . '/storage/private/repair-backups'), 'A concurrent application request must not retire installer files while finalization holds the apply guard.');
    flock($applyGuard, LOCK_UN);
    fclose($applyGuard);
    vvx_retire_reintroduced_installer($root);
    $archives = glob($root . '/storage/private/repair-backups/retired-installer-overlay-*') ?: [];
    $assert(!is_dir($root . '/install') && count($archives) === 1 && file_get_contents($archives[0] . '/nested/unique.txt') === 'in-progress installer file', 'Retirement must resume normally once finalization releases the guard.');

    $root = $newRoot('executing-installer');
    $write($root . '/config/app.php', $activeText);
    $write($root . '/storage/install.lock', $lock($a['installation_id']));
    $write($root . '/install/probe.php', "<?php\nvvx_retire_reintroduced_installer(" . var_export($root, true) . ");\n");
    require $root . '/install/probe.php';
    $assert(is_file($root . '/install/probe.php'), 'Recovery must not remove installer services while installer bootstrap is executing.');

    $root = $newRoot('linked-installer');
    $write($root . '/config/app.php', $activeText);
    $write($root . '/storage/install.lock', $lock($a['installation_id']));
    symlink($outside, $root . '/install');
    vvx_retire_reintroduced_installer($root);
    $assert(is_link($root . '/install') && is_file($outside . '/app.php'), 'Installer retirement must not follow a linked installer directory.');

    foreach (['installed' => $a, 'identity' => ['installed' => false, 'installation_id' => 'partial-fixture'],
        'keys' => ['installed' => false, 'security' => ['app_key' => 'existing-fixture-key']],
        'corrupt' => null, 'lock' => ['installed' => false]] as $label => $saved) {
        $root = $newRoot('installer-guard-' . $label);
        $raw = $saved === null ? "<?php\nthrow new RuntimeException('fixture corrupt');\n" : $configText($saved);
        $write($root . '/config/app.php', $raw);
        if ($label === 'lock') $write($root . '/storage/install.lock', 'corrupt lock evidence');
        $log = new \Velvet\Installer\InstallerLog($root, 'guard-fixture');
        $service = new \Velvet\Installer\InstallerService($root, $log);
        try {
            $service->install([]);
            throw new RuntimeException('Installer unexpectedly accepted existing state.');
        } catch (RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'Existing installation state'), 'Existing state must stop before database validation: ' . $label);
        }
        $assert(file_get_contents($root . '/config/app.php') === $raw, 'Installer guard must preserve original config: ' . $label);
        $assert(!file_exists($log->path()), 'Installer guard must stop before logging installation or changing runtime state: ' . $label);
    }
    $root = $newRoot('installer-clean-guard');
    $service = new \Velvet\Installer\InstallerService($root, new \Velvet\Installer\InstallerLog($root, 'clean-fixture'));
    try {
        $service->install([]);
        throw new RuntimeException('Missing database payload unexpectedly accepted.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'Database host'), 'A clean placeholder must reach ordinary database validation.');
    }

    // Exercise the public installed predicate against real fixture bytes without app bootstrap.
    $root = $newRoot('installed-predicate');
    define('ROOT_PATH', $root);
    require_once $sourceRoot . '/app/Support/functions.php';
    $write($root . '/config/app.php', $activeText);
    app_config()->reload();
    $write($root . '/storage/install.lock', $lock($a['installation_id']));
    $assert(is_installed(), 'Public installed predicate must accept exact matching identity.');
    foreach ([false, true] as $installedFlag) {
        $pending = $a;
        $pending['installed'] = $installedFlag;
        $pending['installer_pending'] = true;
        $write($root . '/config/app.php', $configText($pending));
        app_config()->reload();
        $assert(!is_installed(), 'The public site must remain uninstalled until a pending checkpoint has completed final verification.');
    }
    $write($root . '/config/app.php', $activeText);
    app_config()->reload();
    $assert(is_installed(), 'Clearing the pending marker after successful finalization activates the existing matching configuration and lock.');
    $write($root . '/storage/install.lock', $lock($b['installation_id']));
    $assert(!is_installed(), 'Public installed predicate must reject another installation identity.');
    $write($root . '/storage/install.lock', 'not-json');
    $assert(!is_installed() && installed_lock_data() === [], 'Public installed predicate must reject arbitrary lock text.');
    unlink($root . '/storage/install.lock');
    $assert(!is_installed(), 'Public installed predicate must reject missing lock.');

    echo "PASS installer recovery: {$checks} assertions (disposable fixtures only)\n";
} finally {
    $remove($fixtures);
}
