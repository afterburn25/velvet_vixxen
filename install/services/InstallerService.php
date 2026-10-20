<?php
declare(strict_types=1);

namespace Velvet\Installer;

use App\Core\Config;
use App\Core\Database;
use App\Core\MigrationRunner;
use App\Core\Store;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class InstallerService
{
    private FileSystemService $filesystem;

    /** @var callable(string,string,int,string,string):void|null */
    private $progress;

    public function __construct(
        private readonly string $root,
        private readonly InstallerLog $log,
        ?callable $progress = null,
    ) {
        $this->filesystem = new FileSystemService($root, $log);
        $this->progress = $progress;
    }

    /**
     * @param array{db:array,owner:array,site:array,mail?:array,install_id?:string} $payload
     * @return array{install_id:string,base_url:string,admin_email:string,log_path:string,migrations:list<string>,database_mode:string,owner_reused:bool}
     */
    public function install(array $payload): array
    {
        $activePath = $this->root . '/config/app.php';
        $lockPath = $this->root . '/storage/install.lock';
        $active = \vvx_recovery_load_config($activePath);
        $hasIdentity = ($active['installed'] ?? false) === true
            || trim((string) ($active['installation_id'] ?? '')) !== '';
        foreach (['app_key', 'signing_key', 'media_key'] as $key) {
            $hasIdentity = $hasIdentity || trim((string) ($active['security'][$key] ?? '')) !== '';
        }
        $staged = $this->completeKeyConfiguration($active)
            && (($active['installed'] ?? null) === false || \vvx_recovery_pending_installation($active));
        $savedLock = \vvx_recovery_load_lock($lockPath);
        $pendingLock = $staged && ($active['installer_pending'] ?? false) === true && $savedLock !== []
            && hash_equals((string) $active['installation_id'], (string) $savedLock['installation_id']);
        // A complete unfinished configuration is a resumable checkpoint. An
        // active site, corrupt identity or linked path is never a fresh install.
        if (($hasIdentity && !$staged) || (file_exists($lockPath) && !$pendingLock) || is_link($lockPath) || is_link($activePath)
            || !\vvx_recovery_safe_path($this->root, $activePath, true)
            || !\vvx_recovery_safe_path($this->root, $lockPath, true)
            || !\vvx_recovery_safe_path($this->root, $activePath . '.previous', true)
            || (is_file($activePath) && $active === [])) {
            throw new RuntimeException('Existing installation state was found. The installer will not overwrite it; restore its matching configuration and installation lock.');
        }
        $previousConfig = is_file($activePath) ? (string) file_get_contents($activePath) : null;
        $previousLock = is_file($lockPath) ? (string) file_get_contents($lockPath) : null;

        $installId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payload['install_id'] ?? ''));
        if ($installId === '') $installId = bin2hex(random_bytes(12));
        $this->report('validation', 'Validating installation details', 4, 'running', 'Checking database, owner, website, and email settings.');
        $db = $this->validateDatabase((array) ($payload['db'] ?? []));
        $owner = $this->validateOwner((array) ($payload['owner'] ?? []));
        $site = $this->validateSite((array) ($payload['site'] ?? []));
        $mail = $this->validateMail((array) ($payload['mail'] ?? []), $owner['email'], $site['site_name']);
        if ($staged && !$this->sameDatabase((array) $active['db'], $db)) {
            throw new RuntimeException('The unfinished configuration belongs to a different database. Enter its existing database details so setup can resume with the same security keys.');
        }

        $this->log->write('installation.started', [
            'install_id' => $installId, 'db' => $db,
            'owner' => ['name' => $owner['name'], 'email' => $owner['email']],
            'site' => $site, 'mail' => $mail,
        ]);
        $this->report('filesystem', 'Preparing folders and permissions', 12, 'running', 'Checking required packaged assets and writable runtime folders.');
        $filesystemResults = $this->filesystem->prepareAndProbe();
        if (!$this->filesystem->allRequiredWritable($filesystemResults)) {
            throw new RuntimeException('One or more required folders failed the write, rename, read, or delete test. Return to Server & Files to review the exact folder.');
        }

        $guard = $this->acquireInstallationGuard();
        $pdo = null;
        $databaseLock = '';
        $databaseLockHeld = false;
        $configurationStaged = false;
        $createdLock = null;
        $config = null;
        $baseConfig = [];
        try {
            $currentConfig = is_file($activePath) ? (string) file_get_contents($activePath) : null;
            $currentLock = is_file($lockPath) ? (string) file_get_contents($lockPath) : null;
            if ($currentConfig !== $previousConfig || $currentLock !== $previousLock || is_link($lockPath)
                || (file_exists($lockPath) && !is_file($lockPath))) {
                throw new RuntimeException('Another setup request changed the installation state. Refresh this page to continue from its saved result.');
            }
            $this->report('database', 'Checking the selected database', 24, 'running', 'Testing access and finding existing tables to preserve.');
            Database::test($db, true);
            $pdo = Database::connect($db);
            $databaseLock = 'vvx-install-' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 48);
            $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $statement->execute([$databaseLock]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException('Another installation is already using this database. Retry after that request finishes.');
            }
            $databaseLockHeld = true;
            $runner = new MigrationRunner($pdo, $this->root . '/database/migrations',
                fn(string $event, array $context): mixed => $this->log->write($event, $context));
            // This pass is read-only: incompatible objects, changed history or
            // incorrect owner credentials stop before staging config or schema.
            $runner->validateForResume();
            $tables = $this->databaseTables($pdo);
            $databaseState = $this->existingInstallationState($pdo, $tables);
            $existingOwner = $this->existingOwner($pdo, $tables, $owner);
            $savedConfig = $this->configurationForResume($active, $db, $databaseState);
            if ($savedConfig === [] && $this->requiresOriginalKeys($pdo, $tables)) {
                throw new RuntimeException('Existing encrypted license records need their original security keys. Restore the matching config/app.php or its backup; the existing database and records have been preserved.');
            }
            $installId = (string) ($savedConfig['installation_id'] ?? $databaseState['installation_id'] ?? $installId);
            $existingApplication = isset($tables['migrations']) || isset($tables['admins']) || isset($tables['settings']);
            $databaseMode = $existingApplication || $savedConfig !== [] ? 'resumed' : 'created';
            $this->report('database', $databaseMode === 'resumed' ? 'Continuing the existing database' : 'Database ready', 29, 'success',
                $databaseMode === 'resumed' ? 'Existing tables and records will be kept. Only missing setup work will run.' : 'Application tables will be added while any unrelated tables remain untouched.');

            $config = new Config($activePath);
            $baseConfig = $savedConfig !== [] ? $savedConfig : [
                'app_name' => $site['site_name'], 'base_url' => $site['base_url'],
                'timezone' => $site['timezone'], 'currency' => $site['currency'],
                'support_email' => $site['support_email'], 'mail' => $mail,
                'security' => ['app_key' => $this->secret(48), 'signing_key' => $this->secret(48), 'media_key' => $this->secret(48)],
                'paths' => ['storage' => 'storage', 'private' => 'storage/private', 'uploads' => 'uploads',
                    'images' => 'images', 'addons' => 'addons', 'templates' => 'templates'],
                'canva' => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => $site['base_url'] . '/admin/?page=canva-callback'],
            ];
            $baseConfig['installed'] = false;
            $baseConfig['installation_id'] = $installId;
            $baseConfig['version'] = '0.8.1';
            $baseConfig['storage_mode'] = 'mysql';
            $baseConfig['file_store_path'] = $baseConfig['file_store_path'] ?? 'storage/data.json';
            $baseConfig['db'] = $db;
            $baseConfig['installer_pending'] = true;
            // Preserve an adopted site's existing public settings when its
            // original local configuration is no longer present.
            if ($savedConfig === [] && isset($tables['settings'])) {
                $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
                $stmt->execute(['site']);
                $raw = $stmt->fetchColumn();
                $savedSite = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($savedSite)) {
                    foreach (['timezone', 'currency', 'support_email'] as $key) {
                        if (isset($savedSite[$key]) && is_string($savedSite[$key])) $baseConfig[$key] = $savedSite[$key];
                    }
                    if (isset($savedSite['site_name']) && is_string($savedSite['site_name'])) $baseConfig['app_name'] = $savedSite['site_name'];
                }
            }
            if ($savedConfig === [] && is_string($databaseState['base_url'] ?? null)
                && preg_match('#^https?://#i', $databaseState['base_url']) && filter_var($databaseState['base_url'], FILTER_VALIDATE_URL)) {
                $baseConfig['base_url'] = rtrim($databaseState['base_url'], '/');
            }
            $this->report('configuration', 'Saving resumable configuration', 34, 'running', 'Keeping the installation identity and security keys available if setup needs a retry.');
            $this->preserveConfigurationFiles();
            $config->write($baseConfig);
            $configurationStaged = true;
            $this->log->write('configuration.staged', ['path' => 'config/app.php', 'resumed' => $databaseMode === 'resumed']);

            $this->report('migrations', 'Preparing database tables', 48, 'running', 'Reusing compatible tables and applying only missing schema changes.');
            $applied = $runner->migrate(rollbackOnFailure: false, resumeExisting: true);
            $this->log->write('database.migrations_complete', ['migrations' => $applied]);
            $store = new Store($config);
            $this->report('owner', $existingOwner !== null ? 'Keeping the existing owner account' : 'Creating owner account', 67, 'running',
                $existingOwner !== null ? 'The existing owner login was verified; its account and password are preserved.' : 'Hashing the 12-character-or-longer password and assigning owner permissions.');
            $adminId = $existingOwner !== null ? (int) $existingOwner['id'] : (int) $store->createAdmin($owner['name'], $owner['email'], $owner['password']);
            $this->report('defaults', 'Completing missing site defaults', 74, 'running', 'Keeping existing settings, artwork, scenes, plans, and saved content.');
            $this->seedDefaults($store, $pdo, $site, $databaseState !== []);
            $this->seedRoles($pdo, $adminId);
            $installedAt = (string) ($savedConfig['installed_at'] ?? $databaseState['installed_at'] ?? gmdate('c'));
            $this->writeInstallationState($pdo, $installId, (string) $baseConfig['base_url'], $installedAt);
            $store->audit($adminId, $databaseMode === 'resumed' ? 'installation.resumed' : 'installation.completed', [
                'version' => '0.8.1', 'install_id' => $installId,
            ]);

            $finalConfig = $baseConfig;
            $finalConfig['installed'] = true;
            $finalConfig['installed_at'] = $installedAt;
            // Keep the pending marker through lock and startup verification. A
            // concurrent page load must not activate or retire an unfinished setup.
            $config->write($finalConfig);
            $createdLock = ['installed' => true, 'installation_id' => $installId, 'version' => '0.8.1',
                'base_url' => (string) $finalConfig['base_url'], 'installed_at' => (string) $finalConfig['installed_at']];
            $this->report('lock', 'Creating installation lock', 86, 'running', 'Protecting the completed website.');
            $this->writeLock($createdLock);
            $this->report('verification', 'Verifying application startup', 92, 'running', 'Checking the saved configuration, site settings, database, and lock.');
            $this->verifyBoot($finalConfig);
            // Do not discard a pre-existing backup during a continuation.
            $this->log->write('installation.verified', ['install_id' => $installId, 'admin_id' => $adminId, 'database_mode' => $databaseMode]);
            $this->report('installed', 'Core installation complete', 95, 'success', 'The website is ready. The public installer is ready for cleanup.');
            unset($finalConfig['installer_pending']);
            $config->write($finalConfig);
            return ['install_id' => $installId, 'base_url' => (string) $finalConfig['base_url'],
                'admin_email' => $existingOwner !== null ? (string) $existingOwner['email'] : $owner['email'],
                'log_path' => $this->log->path(), 'migrations' => $applied, 'database_mode' => $databaseMode,
                'owner_reused' => $existingOwner !== null];
        } catch (Throwable $error) {
            if ($configurationStaged && $config instanceof Config) {
                if ($createdLock !== null && \vvx_recovery_load_lock($lockPath) === $createdLock) @unlink($lockPath);
                try {
                    // DDL commits independently in MySQL. Keep completed work
                    // and the same keys; the next request reconciles what is missing.
                    $config->write($baseConfig);
                } catch (Throwable $saveError) {
                    $this->log->write('installation.checkpoint_warning', ['error' => $saveError->getMessage()]);
                }
            }
            $detail = 'Installation paused. Existing tables and records were kept; completed setup work can be resumed. ' . $error->getMessage();
            $this->report('failed', 'Installation needs attention', 100, 'failed', $detail);
            $this->log->write('installation.failed', ['error' => $error->getMessage(), 'automatic_rollback' => false]);
            throw new RuntimeException($detail, 0, $error);
        } finally {
            if ($databaseLockHeld && $pdo instanceof PDO) {
                try { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$databaseLock]); } catch (Throwable) {}
            }
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    /** @return array{public_removed:bool,residual_path:?string,message:string} */
    public function cleanup(string $installId): array
    {
        return $this->filesystem->retireInstaller($installId);
    }

    private function validateDatabase(array $db): array
    {
        $clean = [
            'host' => trim((string) ($db['host'] ?? 'localhost')),
            'port' => (int) ($db['port'] ?? 3306),
            'name' => trim((string) ($db['name'] ?? '')),
            'user' => trim((string) ($db['user'] ?? '')),
            'password' => (string) ($db['password'] ?? ''),
            'charset' => 'utf8mb4',
        ];
        if ($clean['host'] === '' || $clean['name'] === '' || $clean['user'] === '') {
            throw new RuntimeException('Database host, database name, and database username are required.');
        }
        if (preg_match('/[;\x00-\x1F\x7F]/', $clean['host']) || !preg_match('/^[A-Za-z0-9_.:\-\[\]]+$/', $clean['host'])) {
            throw new RuntimeException('The database host contains unsupported characters.');
        }
        if (!preg_match('/^[A-Za-z0-9_$.-]+$/', $clean['name'])) {
            throw new RuntimeException('The database name contains unsupported characters.');
        }
        if ($clean['port'] < 1 || $clean['port'] > 65535) {
            throw new RuntimeException('The database port must be between 1 and 65535.');
        }
        return $clean;
    }

    private function validateOwner(array $owner): array
    {
        $name = trim((string) ($owner['name'] ?? ''));
        $rawEmail = $owner['email'] ?? '';
        $email = EmailAddress::normalize($rawEmail);
        $password = (string) ($owner['password'] ?? '');
        if ($name === '' || !EmailAddress::isValid($rawEmail)) {
            throw new RuntimeException('Enter the owner name and a valid owner email address.');
        }
        $length = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
        if ($length < 12) {
            throw new RuntimeException('The owner password must contain at least 12 characters.');
        }
        if ($length > 4096) {
            throw new RuntimeException('The owner password is too long.');
        }
        return ['name' => $name, 'email' => $email, 'password' => $password];
    }

    private function validateSite(array $site): array
    {
        $baseUrl = rtrim(trim((string) ($site['base_url'] ?? '')), '/');
        $siteName = trim((string) ($site['site_name'] ?? 'Nikki Wilder — The Velvet Vixxen'));
        $timezone = trim((string) ($site['timezone'] ?? 'America/Chicago'));
        $currency = strtoupper(trim((string) ($site['currency'] ?? 'USD')));
        $rawSupportEmail = $site['support_email'] ?? '';
        $supportEmail = EmailAddress::normalize($rawSupportEmail);
        $ageGateEnabled = filter_var($site['age_gate_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $ageGateEnabled = $ageGateEnabled ?? true;
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $baseUrl)) {
            throw new RuntimeException('Enter a complete website URL beginning with http:// or https://.');
        }
        if ($siteName === '') {
            throw new RuntimeException('The site name is required.');
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Choose a valid time zone.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('Currency must be a three-letter code such as USD.');
        }
        if (!EmailAddress::isValid($rawSupportEmail)) {
            throw new RuntimeException('Enter a valid support email address.');
        }
        return compact('baseUrl', 'siteName', 'timezone', 'currency', 'supportEmail', 'ageGateEnabled') + [
            'base_url' => $baseUrl,
            'site_name' => $siteName,
            'support_email' => $supportEmail,
            'age_gate_enabled' => $ageGateEnabled,
        ];
    }

    private function validateMail(array $mail, string $ownerEmail, string $siteName): array
    {
        $mode = strtolower(trim((string) ($mail['mode'] ?? 'disabled')));
        if (!in_array($mode, ['disabled', 'php', 'smtp'], true)) {
            throw new RuntimeException('Choose Disabled, PHP mail, or SMTP for email delivery.');
        }

        if (!EmailAddress::isValid($ownerEmail)) {
            throw new RuntimeException('Enter a valid owner email address.');
        }
        $ownerEmail = EmailAddress::normalize($ownerEmail);
        $rawFromName = $mail['from_name'] ?? '';
        $fromName = is_string($rawFromName) ? trim($rawFromName) : '';

        // Email delivery is optional during installation. When it is disabled,
        // ignore stale or invisible browser-autofill values in hidden mail
        // fields instead of blocking an otherwise valid installation.
        if ($mode === 'disabled') {
            if (EmailAddress::hasForbiddenControls($rawFromName)) $fromName = '';
            $safeSiteName = EmailAddress::hasForbiddenControls($siteName) ? 'Nikki Wilder' : $siteName;
            return [
                'mode' => 'disabled',
                'host' => '',
                'port' => 587,
                'encryption' => 'tls',
                'username' => '',
                'password' => '',
                'from_email' => $ownerEmail,
                'from_name' => $fromName !== '' ? $fromName : $safeSiteName,
            ];
        }

        if (EmailAddress::hasForbiddenControls($rawFromName)
            || ($fromName === '' && EmailAddress::hasForbiddenControls($siteName))) {
            throw new RuntimeException('The From name cannot contain line breaks or control characters.');
        }
        if ($mode === 'smtp') {
            foreach (['host' => 'SMTP hostname', 'username' => 'SMTP username'] as $field => $label) {
                if (EmailAddress::hasForbiddenControls($mail[$field] ?? '')) {
                    throw new RuntimeException($label . ' cannot contain line breaks or control characters.');
                }
            }
        }
        $fromEmail = EmailAddress::resolveSender($mail['from_email'] ?? '', $mode === 'smtp' ? ($mail['username'] ?? '') : '', $ownerEmail);
        $clean = [
            'mode' => $mode,
            'host' => trim((string) ($mail['host'] ?? '')),
            'port' => (int) ($mail['port'] ?? 587),
            'encryption' => strtolower(trim((string) ($mail['encryption'] ?? 'tls'))),
            'username' => trim((string) ($mail['username'] ?? '')),
            'password' => (string) ($mail['password'] ?? ''),
            'from_email' => $fromEmail,
            'from_name' => $fromName !== '' ? $fromName : $siteName,
        ];
        if ($clean['mode'] === 'smtp') {
            if ($clean['host'] === '') {
                throw new RuntimeException('Enter the SMTP server hostname or disable email setup for now.');
            }
            $address = str_starts_with($clean['host'], '[') && str_ends_with($clean['host'], ']')
                ? substr($clean['host'], 1, -1) : $clean['host'];
            if (strlen($clean['host']) > 255 || (!filter_var($address, FILTER_VALIDATE_IP)
                && !filter_var($clean['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
                throw new RuntimeException('Enter a valid SMTP hostname without a URL, path, or port.');
            }
            if ($clean['port'] < 1 || $clean['port'] > 65535) {
                throw new RuntimeException('The SMTP port must be between 1 and 65535.');
            }
            if (!in_array($clean['encryption'], ['none', 'tls', 'ssl'], true)) {
                throw new RuntimeException('Choose a valid SMTP encryption method.');
            }
            if (($clean['username'] === '') xor ($clean['password'] === '')) {
                throw new RuntimeException('Enter both the SMTP username and password, or leave both blank.');
            }
        }
        return $clean;
    }

    /** @return array<string,true> */
    private function databaseTables(PDO $pdo): array
    {
        $tables = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) $tables[strtolower((string) $table)] = true;
        return $tables;
    }

    private function completeKeyConfiguration(array $config): bool
    {
        $id = $config['installation_id'] ?? null;
        if (!is_string($id) || strlen($id) < 8 || strlen($id) > 190 || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) return false;
        foreach (['app_key', 'signing_key', 'media_key'] as $key) {
            if (!is_string($config['security'][$key] ?? null) || strlen($config['security'][$key]) < 32) return false;
        }
        foreach (['host', 'name', 'user'] as $key) {
            if (!is_string($config['db'][$key] ?? null) || trim($config['db'][$key]) === '') return false;
        }
        return is_string($config['base_url'] ?? null) && filter_var($config['base_url'], FILTER_VALIDATE_URL)
            && preg_match('#^https?://#i', $config['base_url']) === 1;
    }

    private function sameDatabase(array $left, array $right): bool
    {
        return strtolower(rtrim((string) ($left['host'] ?? ''), '.')) === strtolower(rtrim((string) ($right['host'] ?? ''), '.'))
            && (int) ($left['port'] ?? 3306) === (int) ($right['port'] ?? 3306)
            && (string) ($left['name'] ?? '') === (string) ($right['name'] ?? '');
    }

    /** @return array<string,mixed> */
    private function existingInstallationState(PDO $pdo, array $tables): array
    {
        $state = [];
        foreach (['installation_state' => ['state_value', 'state_key'], 'settings' => ['setting_value', 'setting_key']] as $table => [$value, $key]) {
            if (!isset($tables[$table])) continue;
            $statement = $pdo->prepare("SELECT {$value} FROM {$table} WHERE {$key}=? LIMIT 1");
            $statement->execute(['installation']);
            $raw = $statement->fetchColumn();
            if ($raw === false) continue;
            $record = json_decode((string) $raw, true);
            if (!is_array($record)) throw new RuntimeException('The existing installation identity record is unreadable. Restore that record before continuing; it has not been changed.');
            if (!isset($record['installation_id'])) continue; // Older default settings are not a completed installation identity.
            $id = $record['installation_id'];
            if (!is_string($id) || strlen($id) < 8 || strlen($id) > 190 || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
                throw new RuntimeException('The existing database installation ID is invalid. Its records have been preserved.');
            }
            if ($state !== [] && !hash_equals((string) $state['installation_id'], $id)) {
                throw new RuntimeException('The database contains conflicting installation identities. Existing records were preserved; restore the matching installation metadata.');
            }
            if ($state === []) $state = $record;
        }
        return $state;
    }

    private function existingOwner(PDO $pdo, array $tables, array $owner): ?array
    {
        if (!isset($tables['admins']) || (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0) return null;
        $statement = $pdo->prepare('SELECT id,name,email,password_hash,role,is_active FROM admins WHERE email=? LIMIT 1');
        $statement->execute([$owner['email']]);
        $existing = $statement->fetch();
        if (!is_array($existing) || (string) $existing['role'] !== 'owner' || (int) $existing['is_active'] !== 1
            || !password_verify($owner['password'], (string) $existing['password_hash'])) {
            throw new RuntimeException('This database already has an owner account. Enter its existing owner email and password in this form to continue; that account and its records will be kept.');
        }
        return $existing;
    }

    /** Recover only a matching database/installation identity; never choose an unrelated key set. */
    private function configurationForResume(array $active, array $db, array $state): array
    {
        $id = (string) ($state['installation_id'] ?? '');
        if ($this->completeKeyConfiguration($active)) {
            if (!$this->sameDatabase((array) $active['db'], $db) || ($id !== '' && !hash_equals((string) $active['installation_id'], $id))) {
                throw new RuntimeException('The selected database does not match the saved installation identity. Its tables and the saved security keys have been preserved.');
            }
            return $active;
        }
        $matches = [];
        foreach (\vvx_recovery_config_candidates($this->root) as $path) {
            $candidate = \vvx_recovery_load_config($path);
            if (!$this->completeKeyConfiguration($candidate) || !$this->sameDatabase((array) $candidate['db'], $db)) continue;
            if ($id !== '' && !hash_equals((string) $candidate['installation_id'], $id)) continue;
            if ($id === '' && ($candidate['installed'] ?? null) !== false) continue;
            $fingerprint = hash('sha256', json_encode([$candidate['installation_id'], $candidate['security']], JSON_THROW_ON_ERROR));
            $matches[$fingerprint] ??= $candidate;
        }
        if (count($matches) > 1) {
            throw new RuntimeException('More than one different saved security-key set matches this database. Restore the correct config/app.php so setup can preserve the original keys.');
        }
        return $matches === [] ? [] : array_values($matches)[0];
    }

    private function requiresOriginalKeys(PDO $pdo, array $tables): bool
    {
        if (isset($tables['addon_license_keys']) && (int) $pdo->query('SELECT COUNT(*) FROM addon_license_keys')->fetchColumn() > 0) return true;
        if (!isset($tables['addon_licenses'])) return false;
        $columns = $pdo->query('SHOW COLUMNS FROM addon_licenses')->fetchAll(PDO::FETCH_COLUMN);
        return in_array('license_key_encrypted', $columns, true)
            && (int) $pdo->query("SELECT COUNT(*) FROM addon_licenses WHERE license_key_encrypted IS NOT NULL AND license_key_encrypted<>''")->fetchColumn() > 0;
    }

    /** @return resource */
    private function acquireInstallationGuard()
    {
        $path = $this->root . '/storage/private/.installer-apply.lock';
        if (!\vvx_recovery_safe_path($this->root, $path, true) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('The private installation lock path is unavailable or linked.');
        }
        $guard = @fopen($path, 'c+b');
        if ($guard === false) throw new RuntimeException('The private installation lock could not be opened. Check storage permissions.');
        if (is_link($path) || !flock($guard, LOCK_EX | LOCK_NB)) {
            fclose($guard);
            throw new RuntimeException('Another installation request is still running. Retry after it finishes.');
        }
        @chmod($path, 0600);
        return $guard;
    }

    private function preserveConfigurationFiles(): void
    {
        $sources = [];
        foreach (['app.php', 'app.php.previous'] as $name) {
            $path = $this->root . '/config/' . $name;
            if (!file_exists($path)) continue;
            if (!\vvx_recovery_safe_path($this->root, $path) || !is_file($path) || !is_readable($path)) {
                throw new RuntimeException('The existing configuration backup cannot be preserved safely. Check config folder permissions.');
            }
            $contents = file_get_contents($path);
            if (!is_string($contents)) throw new RuntimeException('The existing configuration could not be read for preservation.');
            $sources[$name] = $contents;
        }
        if ($sources === []) return;
        $directory = $this->root . '/storage/private/repair-backups/installer-resume-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
        if (!\vvx_recovery_safe_path($this->root, $directory, true)
            || (!@mkdir($directory, 0750, true) && !is_dir($directory))) {
            throw new RuntimeException('The original configuration backup could not be prepared. Existing configuration was preserved.');
        }
        $sources['.htaccess'] = "Require all denied\n";
        foreach ($sources as $name => $contents) {
            if (!\vvx_recovery_atomic_write($directory . '/' . $name, $contents, 0600)) {
                throw new RuntimeException('The original configuration could not be saved before resuming setup.');
            }
        }
    }

    private function seedDefaults(Store $store, PDO $pdo, array $site, bool $completedBefore): void
    {
        $defaultsPath = $this->root . '/storage/defaults/data.json';
        $json = is_file($defaultsPath) ? (string) file_get_contents($defaultsPath) : '';
        $defaults = safe_json_decode($json);
        if ($defaults === []) throw new RuntimeException('Default site data is missing or invalid.');

        $settingExists = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key=? LIMIT 1');
        foreach ((array) ($defaults['settings'] ?? []) as $key => $value) {
            $settingExists->execute([(string) $key]);
            if ($settingExists->fetchColumn() !== false) continue;
            if ($key === 'site' && is_array($value)) {
                foreach (['site_name', 'support_email', 'timezone', 'currency'] as $field) $value[$field] = $site[$field];
                $value['age_gate_enabled'] = (bool) ($site['age_gate_enabled'] ?? true);
            }
            $store->setSetting((string) $key, $value);
        }
        $settingExists->execute(['installation']);
        if ($settingExists->fetchColumn() === false) $store->setSetting('installation', ['version' => '0.8.1', 'installed_at' => gmdate('c')]);
        if ($completedBefore) return; // Do not re-add artwork or offers an established owner intentionally removed.

        $planExists = $pdo->prepare('SELECT 1 FROM membership_plans WHERE plan_key=? LIMIT 1');
        foreach ((array) ($defaults['membershipPlans'] ?? []) as $plan) {
            $plan = (array) $plan;
            $planExists->execute([(string) ($plan['key'] ?? '')]);
            if ($planExists->fetchColumn() !== false) continue;
            unset($plan['id']);
            $store->saveMembershipPlan($plan);
        }
        $mediaExists = $pdo->prepare('SELECT 1 FROM media_assets WHERE file_path=? LIMIT 1');
        foreach ((array) ($defaults['media'] ?? []) as $asset) {
            $asset = (array) $asset;
            $mediaExists->execute([(string) ($asset['file'] ?? '')]);
            if ($mediaExists->fetchColumn() === false) $store->addMedia($asset);
        }
        $templateExists = $pdo->prepare('SELECT 1 FROM canva_templates WHERE starter_file=? OR (title=? AND category=? AND device_target=?) LIMIT 1');
        foreach ((array) ($defaults['templates'] ?? []) as $template) {
            $template = (array) $template;
            $templateExists->execute([(string) ($template['starterFile'] ?? ''), (string) ($template['title'] ?? ''),
                (string) ($template['category'] ?? ''), (string) ($template['device'] ?? 'universal')]);
            if ($templateExists->fetchColumn() !== false) continue;
            unset($template['id']);
            $store->saveTemplate($template);
        }
        $sceneExists = $pdo->prepare('SELECT 1 FROM scenes WHERE section_key=? LIMIT 1');
        foreach (['hero', 'footer'] as $section) {
            $sceneExists->execute([$section]);
            if ($sceneExists->fetchColumn() !== false) continue;
            $scene = (array) ($defaults['scenes'][$section]['published'] ?? []);
            if ($scene !== []) $store->saveScene($section, $scene, true);
        }
    }

    private function seedRoles(PDO $pdo, int $adminId): void
    {
        $roles = [
            ['owner', 'Owner', 'Full control over the website and installation.', 1],
            ['administrator', 'Administrator', 'Site administration without ownership-only recovery controls.', 1],
            ['content_editor', 'Content Editor', 'Manage pages and media content.', 1],
            ['support', 'Customer Support', 'Manage members, messages, and support records.', 1],
        ];
        $roleStmt = $pdo->prepare('INSERT IGNORE INTO roles (role_key, name, description, is_system, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
        foreach ($roles as $role) {
            $roleStmt->execute($role);
        }

        $permissions = [
            ['site.manage', 'Manage Site', 'Edit public site settings and navigation.'],
            ['design.manage', 'Manage Design', 'Use the Visual Composer and Canva template library.'],
            ['media.manage', 'Manage Media', 'Upload, organize, and remove media.'],
            ['members.manage', 'Manage Members', 'Review and manage member accounts.'],
            ['commerce.manage', 'Manage Commerce', 'Manage subscriptions, PPV, orders, and refunds.'],
            ['addons.manage', 'Manage Add-ons', 'Install, update, activate, and remove add-ons.'],
            ['system.manage', 'Manage System', 'Run upgrades, backups, diagnostics, and recovery.'],
        ];
        $permissionStmt = $pdo->prepare('INSERT IGNORE INTO permissions (permission_key, name, description, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
        foreach ($permissions as $permission) {
            $permissionStmt->execute($permission);
        }

        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE role_key='owner' LIMIT 1")->fetchColumn();
        $pdo->prepare('INSERT IGNORE INTO admin_role_assignments (admin_id, role_id, created_at) VALUES (?, ?, NOW())')->execute([$adminId, $roleId]);
        $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at) SELECT {$roleId}, id, NOW() FROM permissions");
    }

    private function writeInstallationState(PDO $pdo, string $installId, string $baseUrl, string $installedAt): void
    {
        $state = [
            'installation_id' => $installId,
            'version' => '0.8.1',
            'installed_at' => $installedAt,
            'base_url' => $baseUrl,
        ];
        $stmt = $pdo->prepare('INSERT IGNORE INTO installation_state (state_key, state_value, updated_at) VALUES (?, ?, NOW())');
        $stmt->execute(['installation', json_encode($state, JSON_UNESCAPED_SLASHES)]);
    }

    private function writeLock(array $lock): void
    {
        $path = $this->root . '/storage/install.lock';
        $temp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $payload = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        if (file_put_contents($temp, $payload, LOCK_EX) !== strlen($payload)) {
            @unlink($temp);
            throw new RuntimeException('The installation lock could not be written.');
        }
        @chmod($temp, 0640);
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('The installation lock could not be activated.');
        }
        @chmod($path, 0640);
    }

    private function verifyBoot(array $configValues): void
    {
        $configPath = $this->root . '/config/app.php';

        try {
            $loaded = Config::loadFresh($configPath);
        } catch (Throwable $e) {
            throw new RuntimeException('The final configuration file could not be loaded from disk: ' . $e->getMessage(), 0, $e);
        }

        if (($loaded['installed'] ?? false) !== true) {
            throw new RuntimeException('The final configuration file was readable, but it was not marked installed. PHP may have returned an older cached copy.');
        }
        if ((string) ($loaded['version'] ?? '') !== '0.8.1') {
            throw new RuntimeException('The final configuration file contains the wrong application version.');
        }
        if (!hash_equals((string) ($configValues['installation_id'] ?? ''), (string) ($loaded['installation_id'] ?? ''))) {
            throw new RuntimeException('The final configuration installation ID did not match this setup session.');
        }
        if (!hash_equals((string) ($configValues['base_url'] ?? ''), (string) ($loaded['base_url'] ?? ''))) {
            throw new RuntimeException('The final configuration website URL did not match the verified setup value.');
        }

        foreach (['app_key', 'signing_key', 'media_key'] as $key) {
            if (strlen((string) ($loaded['security'][$key] ?? '')) < 32) {
                throw new RuntimeException('The final configuration is missing a generated security key.');
            }
        }

        try {
            $pdo = Database::connect((array) ($loaded['db'] ?? []));
            $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute(['site']);
            $siteValue = $stmt->fetchColumn();
            $site = $siteValue === false ? null : json_decode((string) $siteValue, true);
        } catch (Throwable $e) {
            throw new RuntimeException('The application could not reconnect using the final configuration: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($site) || $site === []) {
            throw new RuntimeException('The application could not read its seeded site settings.');
        }

        $lockPath = $this->root . '/storage/install.lock';
        if (!is_file($lockPath)) {
            throw new RuntimeException('The installation lock disappeared during verification.');
        }
        $lock = json_decode((string) @file_get_contents($lockPath), true);
        if (!is_array($lock) || ($lock['installed'] ?? false) !== true) {
            throw new RuntimeException('The installation lock could not be read back after it was created.');
        }
        if (!hash_equals((string) ($loaded['installation_id'] ?? ''), (string) ($lock['installation_id'] ?? ''))) {
            throw new RuntimeException('The installation lock did not match the final configuration.');
        }

        clearstatcache(true, $configPath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($configPath, true);
        }
    }

    private function report(string $stage, string $label, int $percent, string $status = 'running', string $detail = ''): void
    {
        if (is_callable($this->progress)) {
            ($this->progress)($stage, $label, $percent, $status, $detail);
        }
    }

    private function secret(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
