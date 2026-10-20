<?php
declare(strict_types=1);

// Operates only inside the exclusively owned MySQL acceptance runtime. This
// helper is outside the runtime ZIP and is never reachable through HTTP.
$root = realpath((string) ($argv[1] ?? ''));
if (PHP_SAPI !== 'cli' || $root === false || !str_starts_with($root, rtrim(sys_get_temp_dir(), '/') . '/')
    || !is_file($root . '/.vvx-installer-db-fixture') || !is_file($root . '/.vvx-addon-acceptance')) {
    throw new RuntimeException('A marked disposable add-on runtime is required.');
}
$config = require $root . '/config/app.php';
$db = $config['db'] ?? [];
if (($config['storage_mode'] ?? '') !== 'mysql' || ($config['addon_acceptance_fixture'] ?? false) !== true
    || ($db['host'] ?? '') !== '127.0.0.1' || !preg_match('/(?:^|_)test(?:_|$)/i', (string) ($db['name'] ?? ''))
    || parse_url((string) ($config['base_url'] ?? ''), PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Only the local, explicitly marked MySQL acceptance fixture is supported.');
}
$pdo = new PDO(sprintf('mysql:host=127.0.0.1;port=%d;dbname=%s;charset=utf8mb4', $db['port'], $db['name']),
    $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$owner = $pdo->query("SELECT id,email FROM admins WHERE email='addon-owner@example.test'")->fetch();
if (!$owner) throw new RuntimeException('The known acceptance owner must already exist.');
$id = 'fixture.mysql-lifecycle';
$hash = hash('sha256', $id);
$sqlPath = $root . '/storage/backups/addons/migrations/' . $hash . '.json';
$intentPath = $root . '/storage/backups/addons/lifecycle/' . $hash . '.json';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$action = (string) ($argv[2] ?? '');
$output = [];
if ($action === 'origin') {
    $port = $input['port'] ?? null;
    if (!is_int($port) || $port < 1024 || $port > 65535) throw new RuntimeException('An ephemeral loopback port is required.');
    $config['base_url'] = 'http://127.0.0.1:' . $port;
    file_put_contents($root . '/config/app.php', "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n", LOCK_EX);
    chmod($root . '/config/app.php', 0600);
} elseif ($action === 'owner') {
    $role = $input['role'] ?? null; $active = $input['active'] ?? null;
    if (!in_array($role, ['owner', 'editor'], true) || !is_bool($active)) throw new RuntimeException('Unsupported fixture account change.');
    $pdo->prepare('UPDATE admins SET role=?,is_active=? WHERE id=? AND email=?')->execute([$role, $active ? 1 : 0, $owner['id'], $owner['email']]);
} elseif ($action === 'unblock-known-down') {
    $record = json_decode((string) file_get_contents($sqlPath), true, 512, JSON_THROW_ON_ERROR);
    if (($record['operation'] ?? '') !== 'compensate' || ($record['pendingStatement'] ?? null) !== false
        || ($record['steps'][0]['migration']['id'] ?? '') !== '003_held' || ($record['steps'][0]['next'] ?? null) !== 1) {
        throw new RuntimeException('Only the known fixture FK blocker may be removed.');
    }
    $pdo->exec('DROP TABLE vvx_addon_recovery_child');
} elseif ($action === 'snapshot') {
    $output = ['sql' => is_file($sqlPath) ? hash_file('sha256', $sqlPath) : null,
        'lifecycle' => is_file($intentPath) ? hash_file('sha256', $intentPath) : null,
        'addon' => $pdo->query("SELECT version,status,package_sha256 FROM addons WHERE addon_key='fixture.mysql-lifecycle'")->fetch(),
        'ledger' => $pdo->query("SELECT migration_key,checksum,batch,executed_at FROM addon_migrations WHERE addon_key='fixture.mysql-lifecycle' ORDER BY migration_key")->fetchAll(),
        'rows' => $pdo->query('SELECT id,value_text FROM vvx_addon_records ORDER BY id')->fetchAll()];
} else {
    throw new RuntimeException('Unknown add-on acceptance action.');
}
echo json_encode($output, JSON_THROW_ON_ERROR) . PHP_EOL;
