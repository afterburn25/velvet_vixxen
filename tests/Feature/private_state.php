<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\PrivateState;

$source = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require_once $source . '/tests/Installer/database_fixture.php';

if (($argv[1] ?? '') === '--worker') {
    $root = realpath($argv[2] ?? '');
    if (!$root || !str_starts_with($root, sys_get_temp_dir() . '/vvx-private-state-') || !is_file($root . '/.owned-fixture')) throw new RuntimeException('An owned private-state fixture is required.');
    $state = new PrivateState(new Config($root . '/config/app.php'), $root);
    if (($argv[3] ?? '') === 'interrupted') {
        $state->update('interruption', static function (array &$data) use ($root): void {
            $data['value'] = 'uncommitted';
            file_put_contents($root . '/callback-started', 'Owned subprocess entered its real transaction.');
            usleep(10000000);
        });
    } else {
        for ($i = 0; $i < 20; ++$i) $state->update('concurrency', static function (array &$data): void {
            $data['count'] = ($data['count'] ?? 0) + 1;
            usleep(2000);
        });
    }
    exit(0);
}

$mysql = in_array('--mysql', $argv, true);
if (count($argv) !== ($mysql ? 2 : 1)) throw new RuntimeException('Usage: php tests/Feature/private_state.php [--mysql]');
$root = sys_get_temp_dir() . '/vvx-private-state-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true);
file_put_contents($root . '/.owned-fixture', 'Disposable private-state source acceptance.');
$config = new Config($root . '/config/app.php');
$pdo = null; $owns = false; $checks = 0; $children = [];
$assert = static function (bool $condition, string $message) use (&$checks): void { ++$checks; if (!$condition) throw new RuntimeException($message); };
$reject = static function (callable $action, string $message, ?int $code = null) use ($assert): void {
    try { $action(); } catch (InvalidArgumentException|RuntimeException $error) { $assert($code === null || $error->getCode() === $code, $message . ' (expected rejection)'); return; }
    $assert(false, $message);
};
$spawn = static function (string $mode) use ($root, &$children): array {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', $root, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) throw new RuntimeException('Could not run a real concurrent PHP worker.');
    fclose($pipes[0]);
    $children[] = $process;
    return [$process, $pipes];
};
$join = static function (array $child) use ($assert): void {
    [$process, $pipes] = $child;
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $assert(proc_close($process) === 0 && $out === '' && $error === '', 'Concurrent PHP worker did not finish cleanly: ' . $error);
};
try {
    if ($mysql) {
        [$db, $pdo] = vvx_installer_fixture_database();
        vvx_installer_fixture_empty($pdo); $owns = true;
        $config->write(['storage_mode' => 'mysql', 'db' => $db]);
    } else $config->write(['storage_mode' => 'file']);
    $state = new PrivateState($config, $root);
    $file = $root . '/storage/private/operations/main.json';
    foreach (['', '../escape', 'Upper', 'with space', 'x/y', "x\0y", str_repeat('a', 65)] as $bad) {
        $reject(fn() => $state->read($bad), 'Unsafe read namespace was accepted.');
        $reject(fn() => $state->update($bad, static fn(array &$data): null => null), 'Unsafe write namespace was accepted.');
    }
    if ($mysql) {
        $reject(fn() => $state->read('main'), 'Missing SQL schema silently fell back to files.', 503);
        $reject(fn() => $state->update('main', static function (array &$data): void { $data = ['bad' => 1]; }), 'SQL write initialized an undeclared schema.', 503);
        $assert($pdo->query('SHOW TABLES')->fetchAll() === [] && !is_dir($root . '/storage'), 'Missing-schema checks wrote storage.');
        $migration = require $source . '/database/migrations/202610210017_private_state.php';
        (new App\Core\MigrationRunner($pdo, $source . '/database/migrations'))->migrate(false, true);
        $assert(in_array('private_state', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), true), 'The actual migration runner did not apply private operational storage.');
        $pdo->exec('CREATE TABLE independent_private_fixture (id INT PRIMARY KEY, value_text VARCHAR(50) NOT NULL) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO independent_private_fixture VALUES (1,'Keep the original fixture')");
    }
    $assert($state->read('main') === [] && !is_dir($root . '/storage'), 'Absent read initialized private storage.');
    $assert($state->update('absent', static fn(array &$data): string => 'unchanged') === 'unchanged' && $state->read('absent') === [], 'No-change callback lost its result or created data.');
    $assert($mysql ? (int) $pdo->query('SELECT COUNT(*) FROM private_state')->fetchColumn() === 0 : !is_file($root . '/storage/private/operations/absent.json'), 'No-change update created durable data.');
    $value = ['count' => 1, 'nested' => ['flag' => true, 'none' => null, 'list' => [1, 'two', 1.0]], 'text' => "Exact UTF-8 \u{1F495}"];
    $result = $state->update('main', static function (array &$data) use ($value): array { $data = $value; return ['saved' => true]; });
    $assert($result === ['saved' => true] && $state->read('main') === $value, 'Exact data/callback result did not survive fresh read-back.');
    $before = $mysql ? $pdo->query('SELECT * FROM private_state ORDER BY namespace')->fetchAll() : file_get_contents($file);
    $reject(fn() => $state->update('main', static function (array &$data): void { $data['count'] = 500; throw new RuntimeException('Fixture rollback.'); }), 'Throwing callback committed its changed state.');
    $assert($state->read('main') === $value && ($mysql ? $pdo->query('SELECT * FROM private_state ORDER BY namespace')->fetchAll() : file_get_contents($file)) === $before, 'Rollback changed committed bytes/revision.');
    foreach ([new stdClass(), INF, "\xff"] as $invalid) {
        $reject(fn() => $state->update('main', static function (array &$data) use ($invalid): void { $data['bad'] = $invalid; }), 'Non-JSON data entered the journal.');
        $assert($state->read('main') === $value, 'Non-JSON rejection changed state.');
    }
    $reject(fn() => $state->update('main', function (array &$data) use ($config, $root): void {
        $data['count'] = 999;
        (new PrivateState($config, $root))->update('different_namespace', static function (array &$other): void { $other = ['unexpected' => true]; });
    }), 'Nested cross-namespace mutation was allowed to commit independently.', 409);
    $assert($state->read('main') === $value && $state->read('different_namespace') === [], 'Nested rejection failed to roll back both effects.');
    $state->update('independent', static function (array &$data): void { $data = ['kept' => 781]; });
    $assert($state->update('main', function (array &$data) use ($state): array { return $state->read('independent'); }) === ['kept' => 781], 'A read of another namespace inside an update was blocked.');

    $state->update('concurrency', static function (array &$data): void { $data = ['count' => 0]; });
    $running = [$spawn('increment'), $spawn('increment'), $spawn('increment'), $spawn('increment')];
    foreach ($running as $child) $join($child);
    $assert($state->read('concurrency') === ['count' => 80], 'Concurrent workers lost fresh increments.');
    $state->update('interruption', static function (array &$data): void { $data = ['value' => 'original']; });
    [$process, $pipes] = $spawn('interrupted');
    $deadline = microtime(true) + 5;
    while (!is_file($root . '/callback-started') && microtime(true) < $deadline) usleep(10000);
    $assert(is_file($root . '/callback-started'), 'Interrupted-worker test did not enter the real update.');
    proc_terminate($process, 9);
    foreach ([1, 2] as $pipe) { stream_get_contents($pipes[$pipe]); fclose($pipes[$pipe]); }
    proc_close($process);
    $assert($state->read('interruption') === ['value' => 'original'], 'Killed worker committed its partial state.');
    $state->update('interruption', static function (array &$data): void { $data['value'] = 'recovered'; });
    $assert($state->read('interruption') === ['value' => 'recovered'], 'Killed worker left a permanently held lock.');

    $valid = $mysql ? $pdo->query("SELECT payload_json FROM private_state WHERE namespace='main'")->fetchColumn() : file_get_contents($file);
    foreach (['', '{}', '{broken', '[1]', '{"schema":2,"namespace":"main","data":[]}', '{"schema":1,"namespace":"other","data":[]}', '{"schema":1,"namespace":"main","data":null}', '{"schema":1,"namespace":"main","data":[],"extra":true}'] as $bad) {
        if ($mysql) $pdo->prepare("UPDATE private_state SET payload_json=? WHERE namespace='main'")->execute([$bad]);
        else file_put_contents($file, $bad);
        $reject(fn() => $state->read('main'), 'Malformed durable envelope was read as empty state.', 503);
        $reject(fn() => $state->update('main', static function (array &$data): void { $data = ['replace' => true]; }), 'Malformed state was overwritten.', 503);
        $assert(($mysql ? $pdo->query("SELECT payload_json FROM private_state WHERE namespace='main'")->fetchColumn() : file_get_contents($file)) === $bad, 'Malformed-state rejection failed to preserve evidence.');
    }
    if ($mysql) $pdo->prepare("UPDATE private_state SET payload_json=? WHERE namespace='main'")->execute([$valid]);
    else file_put_contents($file, $valid);
    $assert($state->read('main') === $value && $state->read('independent') === ['kept' => 781], 'Independent journals changed during failures.');
    if ($mysql) {
        $assert($pdo->query('SELECT value_text FROM independent_private_fixture')->fetchColumn() === 'Keep the original fixture', 'Private journal changed an unrelated table.');
        $pdo->exec('ALTER TABLE private_state ENGINE=MyISAM');
        $reject(fn() => $state->read('main'), 'Nontransactional SQL storage was accepted.', 503);
        $pdo->exec('ALTER TABLE private_state ENGINE=InnoDB');
        foreach ($migration['down'] as $sql) $pdo->exec($sql);
        $reject(fn() => $state->read('main'), 'Rolled-back migration fell back to files.', 503);
        $assert(!is_dir($root . '/storage'), 'SQL journal created hidden file fallback state.');
    } else {
        foreach ([$root . '/storage/private', $root . '/storage/private/operations'] as $dir) $assert((fileperms($dir) & 0777) === 0700, 'Private directory permissions are not 0700.');
        foreach ([$file, $root . '/storage/private/operations/main.lock'] as $path) $assert((fileperms($path) & 0777) === 0600, 'Private file/lock permissions are not 0600.');
        $target = $root . '/outside-target'; file_put_contents($target, 'Untouched outside bytes.');
        $link = $root . '/storage/private/operations/link.json'; symlink($target, $link);
        $reject(fn() => $state->read('link'), 'Symlinked state was read.', 503);
        $reject(fn() => $state->update('link', static function (array &$data): void { $data = ['bad' => 1]; }), 'Symlinked state was overwritten.', 503);
        unlink($link);
        link($target, $link);
        $reject(fn() => $state->update('link', static function (array &$data): void { $data = ['bad' => 1]; }), 'Hard-linked state was overwritten.', 503);
        unlink($link);
        symlink($target, $root . '/storage/private/operations/linked_lock.lock');
        $reject(fn() => $state->update('linked_lock', static function (array &$data): void { $data = ['bad' => 1]; }), 'Symlinked lock was followed.', 503);
        unlink($root . '/storage/private/operations/linked_lock.lock');
        rename($root . '/storage/private/operations', $root . '/operations-preserved');
        symlink($root, $root . '/storage/private/operations');
        $reject(fn() => $state->read('main'), 'Symlinked operations directory was read.', 503);
        $reject(fn() => $state->update('main', static function (array &$data): void { $data = ['bad' => 1]; }), 'Symlinked operations directory was written.', 503);
        unlink($root . '/storage/private/operations'); rename($root . '/operations-preserved', $root . '/storage/private/operations');
        $assert(file_get_contents($target) === 'Untouched outside bytes.' && $state->read('main') === $value, 'Path rejection altered an outside target or source journal.');
        $before = file_get_contents($file);
        $conflict = json_encode(['schema' => 1, 'namespace' => 'main', 'data' => ['external' => 'Conflicting saved change.']], JSON_THROW_ON_ERROR);
        $reject(fn() => $state->update('main', function (array &$data) use ($file, $conflict): void { $data['count'] = 500; file_put_contents($file, $conflict); }), 'Out-of-band change was overwritten.', 409);
        $assert(file_get_contents($file) === $conflict, 'Conflicting evidence was not preserved.');
        file_put_contents($file, $before);
    }
    echo 'Private-state source acceptance passed (' . $checks . ' checks; ' . ($mysql ? 'MySQL ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) : 'file') . "; real rollback/concurrency/interruption).\n";
} finally {
    foreach ($children as $process) if (is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
    if ($pdo && $owns) vvx_installer_fixture_reset_database($pdo, true);
    vvx_installer_fixture_remove($root);
}
