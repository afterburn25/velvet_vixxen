<?php
declare(strict_types=1);

/**
 * Test-only provisioning plan for a disposable CI database service.
 * This helper never connects as root, accepts a root password, or drops a schema.
 * Import its private SQL through the owned service's local administrative client,
 * then verify the newly created account over loopback before running any test.
 */
final class OperationsCiFixture
{
    public static function prepare(string $directory, string $engine, int $port): array
    {
        self::engine($engine);
        if ($port < 1024 || $port > 65535) throw new RuntimeException('Choose an unprivileged loopback service port.');
        self::directory($directory, false);
        $nonce = bin2hex(random_bytes(12));
        $fixture = ['schema' => 1, 'purpose' => 'owned-disposable-operations-ci', 'engine' => $engine,
            'host' => '127.0.0.1', 'port' => $port, 'nonce' => $nonce,
            'source' => 'vvx_ops_test_' . $nonce . '_source',
            'restore' => 'vvx_ops_test_' . $nonce . '_restore',
            'user' => 'vvxops' . $nonce, 'password' => 'aA9!' . bin2hex(random_bytes(32)),
            'prepared_at' => gmdate(DATE_ATOM)];
        $old = umask(0077);
        try {
            if (!mkdir($directory, 0700)) throw new RuntimeException('The new private fixture directory could not be created.');
            self::writeNew($directory . '/fixture.json', self::json($fixture));
            self::writeNew($directory . '/provision.sql', self::sql($fixture));
        } finally { umask($old); }
        // No credential or SQL text is returned to the log.
        return ['schema' => 1, 'phase' => 'prepared', 'engine' => $engine,
            'directory' => $directory, 'provision_sql' => $directory . '/provision.sql',
            'source_environment' => $directory . '/source.env', 'restore_configuration' => $directory . '/restore.json'];
    }

    public static function load(string $directory): array
    {
        self::directory($directory, true);
        $fixture = json_decode(self::readPrivate($directory . '/fixture.json'), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($fixture) || array_keys($fixture) !== ['schema', 'purpose', 'engine', 'host', 'port', 'nonce', 'source', 'restore', 'user', 'password', 'prepared_at']
            || $fixture['schema'] !== 1 || $fixture['purpose'] !== 'owned-disposable-operations-ci'
            || $fixture['host'] !== '127.0.0.1' || !is_int($fixture['port']) || $fixture['port'] < 1024 || $fixture['port'] > 65535
            || !is_string($fixture['nonce']) || preg_match('/\A[a-f0-9]{24}\z/D', $fixture['nonce']) !== 1
            || $fixture['source'] !== 'vvx_ops_test_' . $fixture['nonce'] . '_source'
            || $fixture['restore'] !== 'vvx_ops_test_' . $fixture['nonce'] . '_restore'
            || $fixture['user'] !== 'vvxops' . $fixture['nonce'] || !is_string($fixture['password'])
            || preg_match('/\AaA9![a-f0-9]{64}\z/D', $fixture['password']) !== 1
            || !is_string($fixture['prepared_at']) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/D', $fixture['prepared_at']) !== 1) {
            throw new RuntimeException('The owned fixture manifest is invalid. Existing data was preserved.');
        }
        self::engine($fixture['engine']);
        if (!hash_equals(self::sql($fixture), self::readPrivate($directory . '/provision.sql'))) {
            throw new RuntimeException('The private provisioning plan changed. Use a fresh disposable service fixture.');
        }
        return $fixture;
    }

    public static function verify(string $directory): array
    {
        $fixture = self::load($directory);
        if (!extension_loaded('pdo_mysql')) throw new RuntimeException('PDO MySQL is required; this gate cannot be skipped.');
        $identities = []; $grants = [];
        foreach (['source', 'restore'] as $kind) {
            $pdo = new PDO('mysql:host=127.0.0.1;port=' . $fixture['port'] . ';dbname=' . $fixture[$kind] . ';charset=utf8mb4',
                $fixture['user'], $fixture['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
            $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            if (($fixture['engine'] === 'mysql-8.0' && (!str_starts_with($version, '8.0.') || stripos($version, 'mariadb') !== false))
                || ($fixture['engine'] === 'mariadb-10.11' && (!str_starts_with($version, '10.11.') || stripos($version, 'mariadb') === false))) {
                throw new RuntimeException('The actual engine does not match this explicit CI target.');
            }
            if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $fixture[$kind]
                || (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn() !== $fixture['user'] . '@%') {
                throw new RuntimeException('The actual database or account differs from the provisioning plan.');
            }
            $currentGrants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
            self::grants($currentGrants, $fixture);
            $grants[$kind] = count($currentGrants);
            if ($pdo->query('SHOW FULL TABLES')->fetchAll() !== []
                || (int) $pdo->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn() !== 0
                || (int) $pdo->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn() !== 0) {
                throw new RuntimeException('Both dedicated databases must be empty before starting the Operations gates. No cleanup was attempted.');
            }
            $identity = $pdo->query('SELECT @@hostname AS hostname, @@port AS port, @@server_id AS server_id, @@datadir AS directory')->fetch();
            $identity['engine'] = $fixture['engine']; $identity['version'] = $version;
            // MariaDB has no server_uuid variable. Its absence is never used to
            // infer that a second database belongs to a different server.
            $identity['uuid'] = $fixture['engine'] === 'mysql-8.0' ? (string) $pdo->query('SELECT @@server_uuid')->fetchColumn() : null;
            $identities[$kind] = $identity;
        }
        if ($identities['source'] !== $identities['restore']) throw new RuntimeException('The dedicated source and restore must belong to the same explicitly provisioned service.');
        $source = self::configuration($fixture, 'source'); $restore = self::configuration($fixture, 'restore');
        $environment = ['VELVET_TEST_DB_HOST' => '127.0.0.1', 'VELVET_TEST_DB_PORT' => (string) $fixture['port'],
            'VELVET_TEST_DB_NAME' => $source['name'], 'VELVET_TEST_DB_USER' => $source['user'],
            'VELVET_TEST_DB_PASSWORD' => $source['password'], 'VELVET_TEST_DB_ENGINE' => $fixture['engine'],
            'VELVET_OPERATIONS_TARGET_DB_FILE' => $directory . '/restore.json'];
        $lines = "# Generated private credentials for this owned disposable Operations fixture.\n";
        foreach ($environment as $name => $value) $lines .= 'export ' . $name . '=' . self::shell($value) . "\n";
        // Only successful, empty-schema verification publishes executable env.
        // Repeated verification refuses modified credentials and preserves bytes.
        self::writeOrMatch($directory . '/restore.json', self::json($restore));
        self::writeOrMatch($directory . '/source.env', $lines);
        $proof = ['schema' => 1, 'phase' => 'verified', 'purpose' => $fixture['purpose'],
            'engine' => $fixture['engine'], 'actual_server_version' => $identities['source']['version'],
            'loopback_host' => '127.0.0.1', 'loopback_port' => $fixture['port'],
            'source_database' => $source['name'], 'restore_database' => $restore['name'],
            'different_database_names' => true, 'initially_empty' => true,
            'account_privileges' => 'Only the two exact escaped schemas; no global privilege or grant option',
            'grant_count' => $grants['source'], 'server_identity_sha256' => hash('sha256', self::json($identities['source'])),
            'provision_sql_sha256' => hash('sha256', self::sql($fixture)),
            'helper_sha256' => hash_file('sha256', __FILE__), 'verified_at' => gmdate(DATE_ATOM)];
        return $proof;
    }

    private static function engine(mixed $engine): void
    {
        if (!is_string($engine) || !in_array($engine, ['mysql-8.0', 'mariadb-10.11'], true)) throw new RuntimeException('Choose mysql-8.0 or mariadb-10.11 explicitly.');
    }

    private static function sql(array $fixture): string
    {
        $sql = "-- Test-only owned ephemeral service. Never import into an existing installation.\n";
        // CREATE without IF NOT EXISTS makes a collision or retry fail. A
        // partially imported plan is preserved for review, never adopted/reset.
        foreach (['source', 'restore'] as $kind) $sql .= 'CREATE DATABASE `' . $fixture[$kind] . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
        $sql .= "CREATE USER '" . $fixture['user'] . "'@'%' IDENTIFIED BY '" . $fixture['password'] . "';\n";
        foreach (['source', 'restore'] as $kind) {
            // Database-level underscores are wildcards in MySQL/MariaDB GRANT.
            // Escaping them prevents privileges on similarly named databases.
            $schema = str_replace('_', '\\_', $fixture[$kind]);
            $sql .= 'GRANT ALL PRIVILEGES ON `' . $schema . "`.* TO '" . $fixture['user'] . "'@'%';\n";
        }
        return $sql;
    }

    private static function grants(array $grants, array $fixture): void
    {
        $account = '`' . $fixture['user'] . '`@`%`';
        $expected = [];
        foreach (['source', 'restore'] as $kind) $expected[] = 'GRANT ALL PRIVILEGES ON `' . str_replace('_', '\\_', $fixture[$kind]) . '`.* TO ' . $account;
        $usage = false; $seen = [];
        foreach ($grants as $grant) {
            if (!is_string($grant)) throw new RuntimeException('The fixture grants are unreadable.');
            if ($grant === 'GRANT USAGE ON *.* TO ' . $account
                || ($fixture['engine'] === 'mariadb-10.11' && preg_match('/\A' . preg_quote('GRANT USAGE ON *.* TO ' . $account, '/') . " IDENTIFIED BY PASSWORD '[*A-F0-9]+'\\z/D", $grant) === 1)) {
                if ($usage) throw new RuntimeException('The fixture account has unexpected duplicate global grants.');
                $usage = true; continue;
            }
            if (!in_array($grant, $expected, true) || in_array($grant, $seen, true)) throw new RuntimeException('The fixture account has extra, wildcard or global privileges. Refusing all destructive tests.');
            $seen[] = $grant;
        }
        sort($seen); sort($expected);
        if (!$usage || $seen !== $expected || count($grants) !== 3) throw new RuntimeException('The fixture account must have only two exact database grants.');
    }

    private static function configuration(array $fixture, string $kind): array
    {
        return ['host' => '127.0.0.1', 'port' => $fixture['port'], 'name' => $fixture[$kind],
            'user' => $fixture['user'], 'password' => $fixture['password'], 'charset' => 'utf8mb4'];
    }

    private static function directory(string $directory, bool $exists): void
    {
        if ($directory === '' || !str_starts_with($directory, '/') || preg_match('#/vvx-operations-ci-[A-Za-z0-9_-]{1,100}\z#D', $directory) !== 1
            || str_contains($directory, "\0") || str_contains($directory, "\n") || str_contains($directory, "\r")
            || realpath(dirname($directory)) !== dirname($directory)) throw new RuntimeException('Choose a new canonical private temporary directory named vvx-operations-ci-*.');
        $roots = array_filter([realpath(sys_get_temp_dir()), (($value = getenv('RUNNER_TEMP')) !== false ? realpath($value) : false)]);
        $allowed = false;
        foreach ($roots as $root) if (str_starts_with($directory, rtrim($root, '/') . '/')) $allowed = true;
        $repository = realpath(dirname(__DIR__, 2));
        if (!$allowed || ($repository !== false && str_starts_with($directory, $repository . '/'))) throw new RuntimeException('Fixture credentials must stay under a private temporary root, outside the repository and artifacts.');
        if ($exists) self::privateStat($directory, true);
        elseif (file_exists($directory) || is_link($directory)) throw new RuntimeException('Refusing to adopt an existing fixture directory. Choose a fresh name.');
    }

    private static function privateStat(string $path, bool $directory): array
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        if (!function_exists('posix_geteuid') || $stat === false || is_link($path)
            || ($stat['mode'] & 0170000) !== ($directory ? 0040000 : 0100000)
            || ($stat['mode'] & 0777) !== ($directory ? 0700 : 0600)
            || $stat['uid'] !== posix_geteuid() || (!$directory && ($stat['nlink'] !== 1 || $stat['size'] > 65536))) {
            throw new RuntimeException('Fixture paths must be owned, private, ordinary and unshared. Existing paths were preserved.');
        }
        return $stat;
    }

    private static function readPrivate(string $path): string
    {
        $before = self::privateStat($path, false); $raw = file_get_contents($path); $after = self::privateStat($path, false);
        if (!is_string($raw) || $before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino'] || strlen($raw) !== $after['size']) throw new RuntimeException('A private fixture file changed while reading.');
        return $raw;
    }

    private static function writeNew(string $path, string $bytes): void
    {
        $old = umask(0077);
        try { $handle = @fopen($path, 'xb'); } finally { umask($old); }
        if ($handle === false) throw new RuntimeException('A private fixture output already exists or cannot be created.');
        try {
            if (!chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) throw new RuntimeException('A private fixture output could not be written.');
        } finally { fclose($handle); }
        self::privateStat($path, false);
    }

    private static function writeOrMatch(string $path, string $bytes): void
    {
        if (file_exists($path) || is_link($path)) {
            if (!hash_equals($bytes, self::readPrivate($path))) throw new RuntimeException('Existing fixture credentials changed. They were not overwritten.');
        } else self::writeNew($path, $bytes);
    }

    private static function shell(string $value): string { return "'" . str_replace("'", "'\\''", $value) . "'"; }
    private static function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"; }
}

if (PHP_SAPI === 'cli' && realpath((string) ($argv[0] ?? '')) === __FILE__) {
    try {
        $command = $argv[1] ?? ''; $directory = $argv[2] ?? '';
        if ($command === 'prepare' && count($argv) === 5 && preg_match('/\A[0-9]{4,5}\z/D', $argv[4]) === 1) {
            $result = OperationsCiFixture::prepare($directory, $argv[3], (int) $argv[4]);
        } elseif ($command === 'verify' && count($argv) === 3) $result = OperationsCiFixture::verify($directory);
        else throw new RuntimeException('Usage: php tests/Database/operations_ci_fixture.php prepare /tmp/vvx-operations-ci-NAME mysql-8.0|mariadb-10.11 PORT; then verify DIRECTORY.');
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $error) {
        // PDO errors can contain account/DSN details. Never serialize credentials,
        // statements, grants, hashes of passwords, or a full exception/trace.
        fwrite(STDERR, ($error instanceof PDOException ? 'The disposable service could not be verified. Check its private setup and readiness; no cleanup was attempted.' : $error->getMessage()) . "\n");
        exit(1);
    }
}
