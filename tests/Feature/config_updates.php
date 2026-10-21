<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Config.php';
use App\Core\Config;

if (($argv[1] ?? '') === '--worker') {
    [$script, $mode, $path, $operation, $identity, $barrier] = $argv;
    $config = new Config($path);
    file_put_contents($barrier . '.' . $identity . '.ready', 'ready');
    $deadline = microtime(true) + 10;
    while (!is_file($barrier . '.go')) {
        if (microtime(true) > $deadline) throw new RuntimeException('Worker barrier timed out.');
        usleep(10000);
    }
    try {
        if ($operation === 'write') $config->write(array_replace($config->all(), ['winner' => $identity]));
        elseif ($operation === 'increment') {
            for ($i = 0; $i < 20; ++$i) $config->update(static function (array &$data) use ($identity): void { $data['counters'][$identity] = ($data['counters'][$identity] ?? 0) + 1; });
        } else {
            $config->update(static function (array &$data) use ($barrier): void {
                $data['interrupted'] = true; file_put_contents($barrier . '.locked', 'locked');
                while (!is_file($barrier . '.finish')) usleep(10000);
            });
        }
        echo json_encode(['status' => 'saved']) . "\n";
    } catch (RuntimeException $error) {
        echo json_encode(['status' => 'rejected', 'code' => $error->getCode()]) . "\n";
    }
    exit;
}

$root = sys_get_temp_dir() . '/vvx-config-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$path = $root . '/config/app.php';
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void { ++$checks; if (!$value) throw new RuntimeException($message); };
$reject = static function (callable $operation, int $code, string $message) use ($assert): void {
    try { $operation(); } catch (RuntimeException $error) { $assert($error->getCode() === $code, $message . ' Wrong rejection.'); return; }
    $assert(false, $message);
};
$wait = static function (string $file): void {
    $deadline = microtime(true) + 10;
    while (!is_file($file)) { if (microtime(true) > $deadline) throw new RuntimeException('Process coordination timed out.'); usleep(10000); }
};
$start = static function (string $operation, string $id, string $barrier) use ($path): array {
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', $path, $operation, $id, $barrier], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not create a real configuration writer.');
    fclose($pipes[0]); return [$process, $pipes];
};
$finish = static function (array $worker): array {
    [$process, $pipes] = $worker;
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $err !== '') throw new RuntimeException('Configuration worker failed: ' . $err);
    return json_decode($out, true, 10, JSON_THROW_ON_ERROR);
};
$remove = static function (string $directory) use (&$remove): void {
    foreach (new DirectoryIterator($directory) as $item) {
        if ($item->isDot()) continue;
        if ($item->isDir() && !$item->isLink()) $remove($item->getPathname()); else unlink($item->getPathname());
    }
    rmdir($directory);
};
try {
    $config = new Config($path);
    $assert($config->all() === [] && $config->revision() === 'missing', 'Missing configuration did not remain empty.');
    $assert(!file_exists($root . '/config'), 'A read initialized storage.');
    $config->write(['mail' => ['password' => 'synthetic-fixture-secret'], 'storage_mode' => 'file', 'counter' => 0]);
    $assert(Config::loadFresh($path) === $config->all(), 'Fresh PHP configuration differed from the activated values.');
    $assert(hash_file('sha256', $path) === $config->revision(), 'Revision did not bind the exact bytes.');
    $assert((fileperms($root . '/config/.vvx-config.lock') & 0777) === 0600, 'Configuration lock is not private.');
    $first = new Config($path); $stale = new Config($path);
    $first->write(array_replace($first->all(), ['operations' => ['enabled' => true]]));
    $safeBytes = file_get_contents($path);
    $reject(static fn() => $stale->write(array_replace($stale->all(), ['mail' => ['password' => 'stale-value']])), 409, 'A stale full snapshot overwrote newer settings.');
    $assert(file_get_contents($path) === $safeBytes, 'The rejected stale write changed configuration.');
    $value = $stale->update(static function (array &$data): string { $data['protection']['enabled'] = true; return 'merged'; });
    $assert($value === 'merged' && $stale->get('operations.enabled') === true && $stale->get('mail.password') === 'synthetic-fixture-secret', 'The fresh merge lost an unrelated setting.');
    $safeBytes = file_get_contents($path); $revision = $stale->revision();
    $reject(static fn() => $stale->update(static function (array &$data): void { $data['unexpected'] = true; }, str_repeat('a', 64)), 409, 'A stale submitted revision was accepted.');
    $reject(static fn() => $stale->update(static function (array &$data): void { $data['unexpected'] = true; throw new RuntimeException('abort', 422); }), 422, 'A failed mutation was not rolled back.');
    $assert(file_get_contents($path) === $safeBytes, 'An aborted mutation changed bytes.');
    $stale->update(static fn(array &$data): bool => true, $revision);
    $assert($stale->revision() === $revision, 'A no-change update changed its revision.');
    $reject(static fn() => $stale->update(static fn(array &$data) => (new Config($path))->update(static function (array &$nested): void { $nested['nested'] = true; })), 409, 'A nested writer could deadlock or partially commit.');
    $assert(file_get_contents($path) === $safeBytes, 'A nested mutation changed bytes.');
    $external = "<?php return ['external' => 'preserved'];\n";
    $reject(static fn() => $stale->update(static function (array &$data) use ($path, $external): void { file_put_contents($path, $external); $data['unexpected'] = true; }), 409, 'An outside-lock writer was overwritten.');
    $assert(file_get_contents($path) === $external, 'The external newer bytes were lost.');
    $config->reload(); $config->write(['winner' => '', 'counters' => [], 'mail' => ['password' => 'fixture']]);

    $barrier = $root . '/race'; $a = $start('write', 'first', $barrier); $b = $start('write', 'second', $barrier);
    $wait($barrier . '.first.ready'); $wait($barrier . '.second.ready'); file_put_contents($barrier . '.go', 'go');
    $results = [$finish($a), $finish($b)];
    $assert(count(array_filter($results, static fn(array $row): bool => $row['status'] === 'saved')) === 1, 'Concurrent snapshots did not produce one successful writer.');
    $assert(count(array_filter($results, static fn(array $row): bool => $row === ['status' => 'rejected', 'code' => 409])) === 1, 'Concurrent stale writer was not rejected.');
    $config->reload(); $assert(in_array($config->get('winner'), ['first', 'second'], true), 'The successful concurrent write was not retained.');
    $barrier = $root . '/merge'; $a = $start('increment', 'first', $barrier); $b = $start('increment', 'second', $barrier);
    $wait($barrier . '.first.ready'); $wait($barrier . '.second.ready'); file_put_contents($barrier . '.go', 'go');
    $assert($finish($a) === ['status' => 'saved'] && $finish($b) === ['status' => 'saved'], 'Fresh concurrent merges did not both finish.');
    $config->reload(); $assert($config->get('counters') === ['first' => 20, 'second' => 20] || $config->get('counters') === ['second' => 20, 'first' => 20], 'Serialized merges lost an increment.');
    $assert($config->get('mail.password') === 'fixture', 'Concurrent merges changed the mail credential.');
    $safeBytes = file_get_contents($path);
    $barrier = $root . '/interruption'; $worker = $start('interrupt', 'writer', $barrier);
    $wait($barrier . '.writer.ready'); file_put_contents($barrier . '.go', 'go'); $wait($barrier . '.locked');
    proc_terminate($worker[0], 9); fclose($worker[1][1]); fclose($worker[1][2]); proc_close($worker[0]);
    $assert(file_get_contents($path) === $safeBytes, 'Interrupted validation committed a partial setting.');
    $config->update(static function (array &$data): void { $data['after_interruption'] = true; });
    $assert($config->get('after_interruption') === true, 'A dead worker left a permanent lock.');
    $assert(glob($path . '.tmp-*') === [], 'Configuration updates leaked temporary payloads.');
    unlink($root . '/config/.vvx-config.lock'); symlink($path, $root . '/config/.vvx-config.lock');
    $safeBytes = file_get_contents($path);
    $reject(static fn() => $config->update(static function (array &$data): void { $data['unsafe'] = true; }), 503, 'A linked lock was accepted.');
    $assert(file_get_contents($path) === $safeBytes, 'A linked lock damaged configuration.');
    unlink($root . '/config/.vvx-config.lock');
    rename($path, $path . '.real'); symlink($path . '.real', $path);
    $reject(static fn() => new Config($path), 503, 'A linked configuration was followed.');
    echo "Configuration update checks passed: {$checks}\n";
} finally { $remove($root); }
