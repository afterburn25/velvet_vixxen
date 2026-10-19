<?php
declare(strict_types=1);

/** Recovery never replaces an active installed configuration or regenerates keys. */
function vvx_recovery_safe_path(string $root, string $path, bool $allowMissing = false): bool
{
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    if ($root === '' || str_contains($path, "\0") || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        return false;
    }
    $relative = substr($path, strlen($root) + 1);
    $current = $root;
    foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
        $current .= DIRECTORY_SEPARATOR . $segment;
        clearstatcache(true, $current);
        if (is_link($current)) {
            return false;
        }
        if (!file_exists($current)) {
            if (!$allowMissing) {
                return false;
            }
            continue;
        }
        if ($current !== $path && !is_dir($current)) {
            return false;
        }
    }
    return true;
}

/** Reject linked ancestors as well as linked files before loading executable configuration. */
function vvx_recovery_unlinked_file(string $path): bool
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    for ($current = $path; $current !== dirname($current); $current = dirname($current)) {
        if (is_link($current)) {
            return false;
        }
    }
    return true;
}

function vvx_recovery_load_config(string $path): array
{
    if (!vvx_recovery_unlinked_file($path)) {
        return [];
    }
    try {
        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        $loaded = (static function (string $__path): mixed {
            return require $__path;
        })($path);
        return is_array($loaded) ? $loaded : [];
    } catch (Throwable) {
        return [];
    }
}

function vvx_recovery_load_lock(string $path): array
{
    if (!vvx_recovery_unlinked_file($path)) {
        return [];
    }
    $decoded = json_decode((string) @file_get_contents($path), true);
    if (!is_array($decoded) || ($decoded['installed'] ?? false) !== true
        || !is_string($decoded['installation_id'] ?? null) || trim($decoded['installation_id']) === '') {
        return [];
    }
    return $decoded;
}

function vvx_recovery_lock_matches(array $config, array $lock): bool
{
    $expected = $config['installation_id'] ?? null;
    $actual = $lock['installation_id'] ?? null;
    return ($config['installed'] ?? false) === true && ($lock['installed'] ?? false) === true
        && is_string($expected) && trim($expected) !== '' && is_string($actual)
        && hash_equals($expected, $actual);
}

function vvx_recovery_valid_config(array $config): bool
{
    if (($config['installed'] ?? false) !== true || !is_string($config['installation_id'] ?? null)
        || strlen(trim($config['installation_id'])) < 8 || !is_array($config['security'] ?? null)) {
        return false;
    }
    foreach (['app_key', 'signing_key', 'media_key'] as $key) {
        if (!is_string($config['security'][$key] ?? null) || strlen($config['security'][$key]) < 32) {
            return false;
        }
    }
    $db = $config['db'] ?? [];
    if (!is_array($db)) {
        return false;
    }
    foreach (['host', 'name', 'user'] as $key) {
        if (!is_string($db[$key] ?? null) || trim($db[$key]) === '') {
            return false;
        }
    }
    return true;
}

/** @return list<string> */
function vvx_recovery_archive_files(string $root, array $names): array
{
    $archive = $root . '/storage/private/repair-backups';
    if (!vvx_recovery_safe_path($root, $archive) || !is_dir($archive)) {
        return [];
    }
    $result = [];
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archive, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isFile() && !$item->isLink() && in_array($item->getFilename(), $names, true)
                && vvx_recovery_safe_path($root, $path)) {
                $result[] = $path;
            }
        }
    } catch (Throwable) {
        // An unreadable backup remains untouched and is not recovery evidence.
    }
    return $result;
}

/** @return list<string> */
function vvx_recovery_config_candidates(string $root): array
{
    $candidates = [
        $root . '/config/app.php.previous',
        $root . '/config/app.php.backup',
        $root . '/config/app.php.bak',
    ];
    foreach (['app.php', 'app.php.previous', 'app.php.backup', 'app.php.bak'] as $name) {
        foreach (glob($root . '/.vvx-private-repair-config-*/' . $name) ?: [] as $path) {
            $candidates[] = $path;
        }
    }
    $candidates = array_merge($candidates, vvx_recovery_archive_files($root, ['app.php', 'app.php.previous', 'app.php.backup', 'app.php.bak']));
    $candidates = array_values(array_unique(array_filter($candidates,
        static fn(string $path): bool => vvx_recovery_safe_path($root, $path) && vvx_recovery_unlinked_file($path))));
    usort($candidates, static fn(string $a, string $b): int => ((@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0)) ?: strcmp($a, $b));
    return $candidates;
}

/** @return list<array{path:string,data:array}> */
function vvx_recovery_lock_candidates(string $root): array
{
    $paths = array_merge([$root . '/storage/install.lock'], glob($root . '/.vvx-private-repair-storage-*/install.lock') ?: [],
        vvx_recovery_archive_files($root, ['install.lock']));
    $result = [];
    foreach (array_unique($paths) as $path) {
        if (vvx_recovery_safe_path($root, $path) && ($data = vvx_recovery_load_lock($path)) !== []) {
            $result[] = ['path' => $path, 'data' => $data];
        }
    }
    return $result;
}

/** @return array{ok:bool,message:string,state?:array} */
function vvx_recovery_verify_database(array $config): array
{
    if (!class_exists(PDO::class) || !extension_loaded('pdo_mysql')) {
        return ['ok' => false, 'message' => 'PDO MySQL is not available for recovery verification.'];
    }
    $db = (array) ($config['db'] ?? []);
    $host = (string) ($db['host'] ?? 'localhost');
    $port = (int) ($db['port'] ?? 3306);
    $name = (string) ($db['name'] ?? '');
    $charset = (string) ($db['charset'] ?? 'utf8mb4');
    if (!preg_match('/^[A-Za-z0-9_.:\-\[\]]+$/', $host) || !preg_match('/^[A-Za-z0-9_$.-]+$/', $name)
        || !preg_match('/^[A-Za-z0-9_]+$/', $charset) || $port < 1 || $port > 65535) {
        return ['ok' => false, 'message' => 'The saved database settings are not valid for recovery verification.'];
    }
    try {
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset={$charset}",
            (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 3]);
        foreach (['SELECT state_value FROM installation_state WHERE state_key = ? LIMIT 1',
            'SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1'] as $query) {
            try {
                $stmt = $pdo->prepare($query);
                $stmt->execute(['installation']);
                $raw = $stmt->fetchColumn();
                $state = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($state) || !is_string($state['installation_id'] ?? null) || $state['installation_id'] === '') {
                    continue;
                }
                // A contradictory primary database identity is not overridden by a fallback setting.
                if (!hash_equals((string) ($config['installation_id'] ?? ''), $state['installation_id'])) {
                    return ['ok' => false, 'message' => 'The database contains a different installation ID.'];
                }
                return ['ok' => true, 'message' => 'Database installation ID verified.', 'state' => $state];
            } catch (Throwable) {
                // Older installations may only have the settings record.
            }
        }
        return ['ok' => false, 'message' => 'The database did not contain a matching installation ID.'];
    } catch (Throwable) {
        return ['ok' => false, 'message' => 'The saved database could not be reached for recovery verification.'];
    }
}

function vvx_recovery_atomic_write(string $path, string $contents, int $mode = 0640): bool
{
    $directory = dirname($path);
    for ($current = $path; $current !== dirname($current); $current = dirname($current)) {
        if (is_link($current)) {
            return false;
        }
    }
    if ((!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory))
        || (file_exists($path) && !is_file($path))) {
        return false;
    }
    $temp = $path . '.recovery-' . bin2hex(random_bytes(8));
    $handle = @fopen($temp, 'xb');
    if ($handle === false) {
        return false;
    }
    @chmod($temp, $mode);
    $written = fwrite($handle, $contents);
    $flushed = fflush($handle);
    fclose($handle);
    if ($written !== strlen($contents) || !$flushed || is_link($path) || !@rename($temp, $path)) {
        @unlink($temp);
        return false;
    }
    clearstatcache(true, $path);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }
    return hash_equals(hash('sha256', $contents), (string) @hash_file('sha256', $path));
}

/** Preserve old bytes before any replacement; a failed archive stops recovery. */
function vvx_recovery_preserve_state(string $root, string $activePath, string $lockPath): string|false
{
    $archive = $root . '/storage/private/repair-backups/automatic-recovery-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
    if (!vvx_recovery_safe_path($root, $archive, true) || !@mkdir($archive, 0750, true)) {
        return false;
    }
    if (!vvx_recovery_atomic_write($archive . '/.htaccess', "Require all denied\n")) {
        return false;
    }
    foreach ([$activePath => 'overwritten-app.php', $lockPath => 'overwritten-install.lock'] as $path => $name) {
        if (!file_exists($path)) {
            continue;
        }
        if (!vvx_recovery_safe_path($root, $path) || !vvx_recovery_unlinked_file($path)) {
            return false;
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents) || !vvx_recovery_atomic_write($archive . '/' . $name, $contents)) {
            return false;
        }
    }
    return $archive;
}

/** @return array{status:string,message:string,config_path?:string,lock_path?:string,checked?:int} */
function vvx_attempt_automatic_installation_recovery(string $root, bool $force = false): array
{
    static $attempted = [];
    $real = realpath($root);
    if ($real === false || !is_dir($real)) {
        return ['status' => 'blocked', 'message' => 'The installation root is not available for safe recovery.'];
    }
    $root = $real;
    if (isset($attempted[$root]) && !$force) {
        return ['status' => 'skipped', 'message' => 'Recovery was already checked during this request.'];
    }
    $attempted[$root] = true;
    $activePath = $root . '/config/app.php';
    $lockPath = $root . '/storage/install.lock';
    if (!vvx_recovery_safe_path($root, $activePath, true) || !vvx_recovery_safe_path($root, $lockPath, true)) {
        return ['status' => 'blocked', 'message' => 'Recovery will not follow linked configuration or storage paths.'];
    }
    $active = vvx_recovery_load_config($activePath);
    if (vvx_recovery_valid_config($active) && vvx_recovery_lock_matches($active, vvx_recovery_load_lock($lockPath))) {
        return ['status' => 'installed', 'message' => 'The active configuration and installation lock match.'];
    }
    if (($active['installed'] ?? false) !== true && vvx_recovery_config_candidates($root) === []) {
        return ['status' => 'not_found', 'message' => 'No prior configuration is available for automatic recovery.', 'checked' => 0];
    }
    $guardPath = $root . '/storage/private/.installation-recovery.guard';
    if (!vvx_recovery_safe_path($root, $guardPath, true)
        || (file_exists($guardPath) && !is_file($guardPath))
        || (!is_dir(dirname($guardPath)) && !@mkdir(dirname($guardPath), 0750, true) && !is_dir(dirname($guardPath)))) {
        return ['status' => 'failed', 'message' => 'The private recovery guard could not be prepared. Existing configuration was preserved.'];
    }
    $guard = @fopen($guardPath, 'c+b');
    if ($guard === false) {
        return ['status' => 'failed', 'message' => 'The private recovery guard could not be opened. Existing configuration was preserved.'];
    }
    @chmod($guardPath, 0600);
    if (!flock($guard, LOCK_EX | LOCK_NB)) {
        fclose($guard);
        return ['status' => 'pending', 'message' => 'Another request is recovering this installation. Refresh to recheck its completed state.'];
    }
    try {
        // Re-read active files after acquiring the guard; concurrent requests may have completed recovery.
        return vvx_recovery_restore_verified_state($root);
    } finally {
        flock($guard, LOCK_UN);
        fclose($guard);
    }
}

/** Internal recovery pass; callers hold the per-installation recovery guard. */
function vvx_recovery_restore_verified_state(string $root): array
{
    $activePath = $root . '/config/app.php';
    $lockPath = $root . '/storage/install.lock';
    if (!vvx_recovery_safe_path($root, $activePath, true) || !vvx_recovery_safe_path($root, $lockPath, true)) {
        return ['status' => 'blocked', 'message' => 'Recovery will not follow linked configuration or storage paths.'];
    }
    $activeFingerprint = is_file($activePath) ? @hash_file('sha256', $activePath) : null;
    $lockFingerprint = is_file($lockPath) ? @hash_file('sha256', $lockPath) : null;
    $active = vvx_recovery_load_config($activePath);
    $activeLock = vvx_recovery_load_lock($lockPath);
    if (vvx_recovery_valid_config($active) && vvx_recovery_lock_matches($active, $activeLock)) {
        return ['status' => 'installed', 'message' => 'The active configuration and installation lock match.'];
    }
    $locks = vvx_recovery_lock_candidates($root);
    $candidatePaths = vvx_recovery_config_candidates($root);
    // An installed config is authoritative even if its keys or database settings need owner repair.
    if (($active['installed'] ?? false) === true) {
        if (!vvx_recovery_valid_config($active)) {
            return ['status' => 'blocked', 'message' => 'The active installed configuration needs repair and was preserved unchanged.'];
        }
        $candidatePaths = [$activePath];
    }
    $matches = [];
    foreach ($candidatePaths as $candidatePath) {
        $candidateFingerprint = @hash_file('sha256', $candidatePath);
        $candidate = $candidatePath === $activePath ? $active : vvx_recovery_load_config($candidatePath);
        if (!vvx_recovery_valid_config($candidate)) {
            continue;
        }
        if ($candidatePath !== $activePath && $activeLock !== [] && !vvx_recovery_lock_matches($candidate, $activeLock)) {
            continue;
        }
        $matchingLock = null;
        foreach ($locks as $lock) {
            if (vvx_recovery_lock_matches($candidate, $lock['data'])) {
                $matchingLock = $lock;
                break;
            }
        }
        $dbCheck = $matchingLock === null ? vvx_recovery_verify_database($candidate) : ['ok' => true];
        if (!$dbCheck['ok']) {
            continue;
        }
        $matches[] = ['path' => $candidatePath, 'config' => $candidate, 'lock' => $matchingLock, 'database' => $dbCheck, 'fingerprint' => $candidateFingerprint];
    }
    if ($matches === []) {
        return ['status' => ($active['installed'] ?? false) === true ? 'blocked' : 'not_found',
            'message' => 'No saved configuration and matching installation identity could be verified. Existing configuration was preserved.',
            'checked' => count($candidatePaths)];
    }
    if (count(array_unique(array_map(static fn(array $match): string => $match['config']['installation_id'], $matches))) > 1) {
        return ['status' => 'blocked', 'message' => 'Backups belong to more than one installation. No configuration was changed.', 'checked' => count($candidatePaths)];
    }
    $selected = $matches[0];
    $candidatePath = $selected['path'];
    $candidate = $selected['config'];
    $matchingLock = $selected['lock'];
    $contents = @file_get_contents($candidatePath);
    if (!is_string($contents) || $contents === '' || !vvx_recovery_safe_path($root, $candidatePath)
        || hash('sha256', $contents) !== $selected['fingerprint']) {
        return ['status' => 'failed', 'message' => 'The verified configuration could not be read. No configuration was changed.'];
    }
    $lockData = $matchingLock['data'] ?? array_merge((array) ($selected['database']['state'] ?? []), [
        'installed' => true,
        'installation_id' => $candidate['installation_id'],
        'version' => (string) ($candidate['version'] ?? ''),
        'base_url' => (string) ($candidate['base_url'] ?? ''),
        'installed_at' => (string) ($candidate['installed_at'] ?? gmdate('c')),
        'recovered_at' => gmdate('c'),
    ]);
    $lockContents = json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($lockContents)) {
        return ['status' => 'failed', 'message' => 'The verified installation lock could not be encoded.'];
    }
    if (!vvx_recovery_safe_path($root, $activePath, true) || !vvx_recovery_safe_path($root, $lockPath, true)
        || (is_file($activePath) ? @hash_file('sha256', $activePath) : null) !== $activeFingerprint
        || (is_file($lockPath) ? @hash_file('sha256', $lockPath) : null) !== $lockFingerprint) {
        return ['status' => 'pending', 'message' => 'Installation files changed during verification. Existing state was preserved; refresh to recheck.'];
    }
    $archive = vvx_recovery_preserve_state($root, $activePath, $lockPath);
    if ($archive === false) {
        return ['status' => 'failed', 'message' => 'Existing configuration and lock could not be safely archived. Recovery made no replacements.'];
    }
    // The lock is written first so a failed configuration write cannot reopen fresh installation.
    if (!vvx_recovery_atomic_write($lockPath, $lockContents . PHP_EOL)) {
        return ['status' => 'failed', 'message' => 'The installation lock could not be restored. The active configuration was preserved.'];
    }
    if ($candidatePath !== $activePath && !vvx_recovery_atomic_write($activePath, $contents)) {
        return ['status' => 'failed', 'message' => 'The verified installation lock was restored, but configuration recovery needs a retry. Original files are archived.'];
    }
    vvx_recovery_atomic_write($archive . '/RECOVERY.txt', "Recovered from: {$candidatePath}\nRecovered at: " . gmdate('c') . "\n");
    return ['status' => 'recovered', 'message' => $candidatePath === $activePath
        ? 'The installation lock was restored. The active configuration and all security keys were preserved.'
        : 'The previous installed configuration and matching installation lock were restored.',
        'config_path' => $candidatePath, 'lock_path' => $matchingLock['path'] ?? 'recreated-from-database', 'checked' => count($candidatePaths)];
}

/** Retire a reintroduced installer intact; never erase unknown files from the overlay. */
function vvx_retire_reintroduced_installer(string $root): void
{
    static $done = [];
    $real = realpath($root);
    if ($real === false || isset($done[$real])) {
        return;
    }
    $root = $real;
    $source = $root . '/install';
    if (!vvx_recovery_safe_path($root, $source) || !is_dir($source)) {
        return;
    }
    $config = vvx_recovery_load_config($root . '/config/app.php');
    if (!vvx_recovery_valid_config($config) || !vvx_recovery_lock_matches($config, vvx_recovery_load_lock($root . '/storage/install.lock'))) {
        return;
    }
    // install/bootstrap.php still needs to load its services; retire on the next ordinary app request.
    foreach (get_included_files() as $included) {
        if (str_starts_with($included, $source . DIRECTORY_SEPARATOR)) {
            return;
        }
    }
    $done[$root] = true;
    $archiveRoot = $root . '/storage/private/repair-backups';
    if (!vvx_recovery_safe_path($root, $archiveRoot, true)
        || (!is_dir($archiveRoot) && !@mkdir($archiveRoot, 0750, true) && !is_dir($archiveRoot))) {
        return;
    }
    if (!vvx_recovery_atomic_write($archiveRoot . '/.htaccess', "Require all denied\n")) {
        return;
    }
    $target = $archiveRoot . '/retired-installer-overlay-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
    if (@rename($source, $target)) {
        return;
    }
    // If ownership blocks moving it, deny public access while the verified installed guard remains active.
    vvx_recovery_atomic_write($source . '/.disabled', gmdate('c') . PHP_EOL);
    vvx_recovery_atomic_write($source . '/.htaccess', "Require all denied\n");
}
