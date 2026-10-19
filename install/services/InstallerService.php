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
     * @return array{install_id:string,base_url:string,admin_email:string,log_path:string,migrations:list<string>}
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
        // An absent/corrupt lock must never turn an existing site into a fresh
        // install. Stop before validating a new database or changing any files.
        if ($hasIdentity || file_exists($lockPath) || is_link($lockPath) || is_link($activePath)
            || !\vvx_recovery_safe_path($this->root, $activePath, true)
            || !\vvx_recovery_safe_path($this->root, $lockPath, true)
            || (is_file($activePath) && $active === [])) {
            throw new RuntimeException('Existing installation state was found. The installer will not overwrite it; restore its matching configuration and installation lock.');
        }

        $installId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payload['install_id'] ?? ''));
        if ($installId === '') {
            $installId = bin2hex(random_bytes(12));
        }
        $this->report('validation', 'Validating installation details', 4, 'running', 'Checking database, owner, website, and email settings.');
        $db = $this->validateDatabase((array) ($payload['db'] ?? []));
        $owner = $this->validateOwner((array) ($payload['owner'] ?? []));
        $site = $this->validateSite((array) ($payload['site'] ?? []));
        $mail = $this->validateMail((array) ($payload['mail'] ?? []), $owner['email'], $site['site_name']);

        $this->log->write('installation.started', [
            'install_id' => $installId,
            'db' => $db,
            'owner' => ['name' => $owner['name'], 'email' => $owner['email']],
            'site' => $site,
            'mail' => $mail,
        ]);

        $this->report('filesystem', 'Preparing folders and permissions', 12, 'running', 'Creating organized directories and verifying write, rename, read, and delete access.');
        $filesystemResults = $this->filesystem->prepareAndProbe();
        if (!$this->filesystem->allRequiredWritable($filesystemResults)) {
            throw new RuntimeException('One or more required folders failed the write, rename, read, or delete test. Return to Server & Files to review the exact folder.');
        }

        $this->report('database', 'Testing database permissions', 24, 'running', 'Confirming connection and table create/write/drop privileges.');
        Database::test($db, true);
        $pdo = Database::connect($db);
        $this->assertFreshDatabase($pdo);

        $configPath = $this->root . '/config/app.php';
        $previousConfig = is_file($configPath) ? (string) file_get_contents($configPath) : null;
        $config = new Config($configPath);
        $runner = new MigrationRunner(
            $pdo,
            $this->root . '/database/migrations',
            fn(string $event, array $context): mixed => $this->log->write($event, $context),
        );
        $applied = [];

        $baseConfig = [
            'installed' => false,
            'installation_id' => $installId,
            'app_name' => $site['site_name'],
            'version' => '0.8.1',
            'base_url' => $site['base_url'],
            'timezone' => $site['timezone'],
            'currency' => $site['currency'],
            'support_email' => $site['support_email'],
            'storage_mode' => 'mysql',
            'file_store_path' => 'storage/data.json',
            'db' => $db,
            'mail' => $mail,
            'security' => [
                'app_key' => $this->secret(48),
                'signing_key' => $this->secret(48),
                'media_key' => $this->secret(48),
            ],
            'paths' => [
                'storage' => 'storage',
                'private' => 'storage/private',
                'uploads' => 'uploads',
                'images' => 'images',
                'addons' => 'addons',
                'templates' => 'templates',
            ],
            'canva' => [
                'client_id' => '',
                'client_secret' => '',
                'redirect_uri' => $site['base_url'] . '/admin/?page=canva-callback',
            ],
        ];

        try {
            $this->report('configuration', 'Writing secure configuration', 34, 'running', 'Generating internal secrets and staging protected configuration.');
            $config->write($baseConfig);
            $this->log->write('configuration.staged', ['path' => 'config/app.php']);

            $this->report('migrations', 'Creating database tables', 48, 'running', 'Running versioned core, media, commerce, entitlement, and add-on migrations.');
            $applied = $runner->migrate();
            $this->log->write('database.migrations_complete', ['migrations' => $applied]);

            $this->report('owner', 'Creating owner account', 67, 'running', 'Hashing the 12-character-or-longer password and assigning owner permissions.');
            $store = new Store($config);
            $adminId = $store->createAdmin($owner['name'], $owner['email'], $owner['password']);
            $this->report('defaults', 'Loading site defaults', 74, 'running', 'Loading the approved design, Visual Composer scenes, and Canva templates.');
            $this->seedDefaults($store, $site);
            $this->seedRoles($pdo, (int) $adminId);
            $this->writeInstallationState($pdo, $installId, $site);
            $store->audit((int) $adminId, 'installation.completed', [
                'version' => '0.8.1',
                'install_id' => $installId,
            ]);

            $finalConfig = $baseConfig;
            $finalConfig['installed'] = true;
            $finalConfig['installed_at'] = gmdate('c');
            $config->write($finalConfig);

            $lock = [
                'installed' => true,
                'installation_id' => $installId,
                'version' => '0.8.1',
                'base_url' => $site['base_url'],
                'installed_at' => gmdate('c'),
            ];
            $this->report('lock', 'Creating installation lock', 86, 'running', 'Protecting the finished website from accidental reinstall.');
            $this->writeLock($lock);
            $this->report('verification', 'Verifying application startup', 92, 'running', 'Confirming the configuration, site settings, database, and lock are readable.');
            $this->verifyBoot($finalConfig);
            @unlink($configPath . '.previous');
            $this->log->write('installation.verified', [
                'install_id' => $installId,
                'admin_id' => $adminId,
            ]);

            $this->report('installed', 'Core installation complete', 95, 'success', 'The website is installed. The public installer is ready for cleanup.');

            return [
                'install_id' => $installId,
                'base_url' => $site['base_url'],
                'admin_email' => $owner['email'],
                'log_path' => $this->log->path(),
                'migrations' => $applied,
            ];
        } catch (Throwable $e) {
            $this->report('failed', 'Installation stopped', 100, 'failed', $e->getMessage());
            $this->log->write('installation.failed', ['error' => $e->getMessage()]);
            @unlink($this->root . '/storage/install.lock');
            if ($applied !== []) {
                try {
                    $runner->rollbackKeys(array_reverse($applied));
                } catch (Throwable $rollbackError) {
                    $this->log->write('installation.rollback_failed', ['error' => $rollbackError->getMessage()]);
                }
            }
            try {
                $remaining = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
                if ($remaining === 0) {
                    $pdo->exec('DROP TABLE IF EXISTS migrations');
                }
            } catch (Throwable $registryError) {
                $this->log->write('installation.registry_cleanup_warning', ['error' => $registryError->getMessage()]);
            }
            $this->restoreConfig($configPath, $previousConfig);
            @unlink($configPath . '.previous');
            throw new RuntimeException('Installation stopped and attempted to roll back safely: ' . $e->getMessage(), 0, $e);
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
        $email = EmailAddress::normalize($owner['email'] ?? '');
        $password = (string) ($owner['password'] ?? '');
        if ($name === '' || !EmailAddress::isValid($email)) {
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
        $supportEmail = EmailAddress::normalize($site['support_email'] ?? '');
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
        if (!EmailAddress::isValid($supportEmail)) {
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

        $ownerEmail = EmailAddress::normalize($ownerEmail);
        $fromName = trim((string) ($mail['from_name'] ?? ''));

        // Email delivery is optional during installation. When it is disabled,
        // ignore stale or invisible browser-autofill values in hidden mail
        // fields instead of blocking an otherwise valid installation.
        if ($mode === 'disabled') {
            return [
                'mode' => 'disabled',
                'host' => '',
                'port' => 587,
                'encryption' => 'tls',
                'username' => '',
                'password' => '',
                'from_email' => $ownerEmail,
                'from_name' => $fromName !== '' ? $fromName : $siteName,
            ];
        }

        $fromEmail = EmailAddress::normalize($mail['from_email'] ?? '');
        $clean = [
            'mode' => $mode,
            'host' => trim((string) ($mail['host'] ?? '')),
            'port' => (int) ($mail['port'] ?? 587),
            'encryption' => strtolower(trim((string) ($mail['encryption'] ?? 'tls'))),
            'username' => trim((string) ($mail['username'] ?? '')),
            'password' => (string) ($mail['password'] ?? ''),
            'from_email' => $fromEmail !== '' ? $fromEmail : $ownerEmail,
            'from_name' => $fromName !== '' ? $fromName : $siteName,
        ];
        if (!EmailAddress::isValid($clean['from_email'])) {
            throw new RuntimeException('Enter a valid email From address. A normal address such as red.pixie93@gmail.com is accepted; retype or paste it without display-name text.');
        }
        if ($clean['mode'] === 'smtp') {
            if ($clean['host'] === '') {
                throw new RuntimeException('Enter the SMTP server hostname or disable email setup for now.');
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

    private function assertFreshDatabase(PDO $pdo): void
    {
        $reserved = ['migrations'];
        $files = glob($this->root . '/database/migrations/*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $migration = require $file;
            foreach ((array) ($migration['up'] ?? []) as $sql) {
                if (!is_string($sql)) {
                    continue;
                }
                if (preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?/i', $sql, $matches)) {
                    foreach ($matches[1] as $table) {
                        $reserved[] = strtolower((string) $table);
                    }
                }
            }
        }
        $reserved = array_values(array_unique($reserved));
        $tables = array_map(
            static fn(mixed $table): string => strtolower((string) $table),
            $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),
        );
        $conflicts = array_values(array_intersect($reserved, $tables));
        if ($conflicts !== []) {
            throw new RuntimeException('The selected database already contains Velvet Vixxen tables (' . implode(', ', array_slice($conflicts, 0, 8)) . '). Choose an empty database; the installer will not overwrite an existing installation.');
        }
    }

    private function seedDefaults(Store $store, array $site): void
    {
        $defaultsPath = $this->root . '/storage/defaults/data.json';
        $json = is_file($defaultsPath) ? (string) file_get_contents($defaultsPath) : '';
        $defaults = safe_json_decode($json);
        if ($defaults === []) {
            throw new RuntimeException('Default site data is missing or invalid.');
        }

        foreach ((array) ($defaults['settings'] ?? []) as $key => $value) {
            if ($key === 'site' && is_array($value)) {
                $value['site_name'] = $site['site_name'];
                $value['support_email'] = $site['support_email'];
                $value['timezone'] = $site['timezone'];
                $value['currency'] = $site['currency'];
                $value['age_gate_enabled'] = (bool) ($site['age_gate_enabled'] ?? true);
            }
            $store->setSetting((string) $key, $value);
        }
        $store->setSetting('installation', [
            'version' => '0.8.1',
            'installed_at' => gmdate('c'),
        ]);

        foreach ((array) ($defaults['membershipPlans'] ?? []) as $plan) {
            $plan = (array) $plan;
            unset($plan['id']);
            $store->saveMembershipPlan($plan);
        }

        foreach ((array) ($defaults['media'] ?? []) as $asset) {
            $store->addMedia((array) $asset);
        }
        foreach ((array) ($defaults['templates'] ?? []) as $template) {
            unset($template['id']);
            $store->saveTemplate((array) $template);
        }
        foreach (['hero', 'footer'] as $section) {
            $scene = (array) ($defaults['scenes'][$section]['published'] ?? []);
            if ($scene !== []) {
                $store->saveScene($section, $scene, true);
            }
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
        $roleStmt = $pdo->prepare('INSERT INTO roles (role_key, name, description, is_system, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), updated_at=NOW()');
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
        $permissionStmt = $pdo->prepare('INSERT INTO permissions (permission_key, name, description, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), updated_at=NOW()');
        foreach ($permissions as $permission) {
            $permissionStmt->execute($permission);
        }

        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE role_key='owner' LIMIT 1")->fetchColumn();
        $pdo->prepare('INSERT IGNORE INTO admin_role_assignments (admin_id, role_id, created_at) VALUES (?, ?, NOW())')->execute([$adminId, $roleId]);
        $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at) SELECT {$roleId}, id, NOW() FROM permissions");
    }

    private function writeInstallationState(PDO $pdo, string $installId, array $site): void
    {
        $state = [
            'installation_id' => $installId,
            'version' => '0.8.1',
            'installed_at' => gmdate('c'),
            'base_url' => $site['base_url'],
        ];
        $stmt = $pdo->prepare('INSERT INTO installation_state (state_key, state_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value), updated_at=NOW()');
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

    private function restoreConfig(string $path, ?string $previous): void
    {
        if ($previous === null) {
            @unlink($path);
            $example = $this->root . '/config/app.example.php';
            if (is_file($example)) {
                @copy($example, $path);
            }
            return;
        }
        @file_put_contents($path, $previous, LOCK_EX);
        @chmod($path, 0640);
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
