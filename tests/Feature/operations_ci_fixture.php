<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/Database/operations_ci_fixture.php';
$checks = 0; $owned = [];
$assert = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); ++$checks; };
$reject = static function (callable $operation, string $message) use ($assert): void {
    try { $operation(); } catch (Throwable) { $assert(true, $message); return; }
    $assert(false, $message);
};
$new = static function () use (&$owned): string {
    $path = sys_get_temp_dir() . '/vvx-operations-ci-guard-' . bin2hex(random_bytes(8)); $owned[] = $path; return $path;
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    rmdir($path);
};
try {
    $directory = $new(); $result = OperationsCiFixture::prepare($directory, 'mysql-8.0', 13306);
    $fixture = OperationsCiFixture::load($directory); $original = file_get_contents($directory . '/fixture.json');
    $assert($result['phase'] === 'prepared' && !str_contains(json_encode($result, JSON_THROW_ON_ERROR), $fixture['password']), 'Preparation output exposed a credential.');
    $assert(($fixture['source'] !== $fixture['restore']) && $fixture['host'] === '127.0.0.1', 'Preparation did not separate loopback source and restore.');
    $assert(!file_exists($directory . '/source.env') && !file_exists($directory . '/restore.json'), 'Unverified credentials were published to the tests.');
    $assert((fileperms($directory) & 0777) === 0700, 'Fixture directory is not private.');
    foreach (['fixture.json', 'provision.sql'] as $file) $assert((fileperms($directory . '/' . $file) & 0777) === 0600, 'Private fixture file is readable by other users.');
    $reject(fn() => OperationsCiFixture::prepare($directory, 'mysql-8.0', 13306), 'Preparation adopted an existing private fixture.');
    $assert(file_get_contents($directory . '/fixture.json') === $original, 'Refused preparation changed the original credentials.');
    $reject(fn() => OperationsCiFixture::prepare($new(), 'mariadb-10.11;DROP', 13306), 'An unrecognized engine was accepted.');
    foreach ([0, 330, 65536] as $port) $reject(fn() => OperationsCiFixture::prepare($new(), 'mysql-8.0', $port), 'An invalid service port was accepted.');
    $reject(fn() => OperationsCiFixture::prepare(dirname(__DIR__, 2) . '/vvx-operations-ci-repository', 'mysql-8.0', 13306), 'Credentials were placed inside the repository.');
    $linked = $new(); symlink($directory, $linked);
    $reject(fn() => OperationsCiFixture::prepare($linked, 'mysql-8.0', 13306), 'Preparation followed a linked fixture path.');
    $reject(fn() => OperationsCiFixture::load($linked), 'Verification followed a linked fixture path.');
    foreach (['source' => 'existing_test_live', 'restore' => $fixture['source'], 'user' => 'root', 'host' => 'remote.invalid', 'engine' => 'mysql-8.4', 'port' => '13306', 'nonce' => []] as $key => $value) {
        $bad = $fixture; $bad[$key] = $value; file_put_contents($directory . '/fixture.json', json_encode($bad, JSON_THROW_ON_ERROR));
        $reject(fn() => OperationsCiFixture::load($directory), 'A modified fixture identity was accepted: ' . $key);
        file_put_contents($directory . '/fixture.json', $original);
    }
    $sql = file_get_contents($directory . '/provision.sql');
    file_put_contents($directory . '/provision.sql', $sql . "GRANT ALL ON *.* TO 'root'@'%';\n");
    $reject(fn() => OperationsCiFixture::load($directory), 'A modified private provisioning plan was accepted.');
    file_put_contents($directory . '/provision.sql', $sql);
    chmod($directory . '/fixture.json', 0644);
    $reject(fn() => OperationsCiFixture::load($directory), 'A public credential file was accepted.');
    $assert((fileperms($directory . '/fixture.json') & 0777) === 0644, 'Read-only verification silently repaired credentials.');
    chmod($directory . '/fixture.json', 0600);
    link($directory . '/fixture.json', $directory . '/shared.json');
    $reject(fn() => OperationsCiFixture::load($directory), 'Shared hard-linked credentials were accepted.');
    unlink($directory . '/shared.json');
    rename($directory . '/fixture.json', $directory . '/original.json'); symlink($directory . '/original.json', $directory . '/fixture.json');
    $reject(fn() => OperationsCiFixture::load($directory), 'A linked credential file was accepted.');
    unlink($directory . '/fixture.json'); rename($directory . '/original.json', $directory . '/fixture.json');
    $assert(OperationsCiFixture::load($directory) === $fixture, 'The original owned fixture could not be read after rejected changes.');

    $grants = new ReflectionMethod(OperationsCiFixture::class, 'grants');
    $account = '`' . $fixture['user'] . '`@`%`';
    $valid = ['GRANT USAGE ON *.* TO ' . $account];
    foreach (['source', 'restore'] as $kind) $valid[] = 'GRANT ALL PRIVILEGES ON `' . str_replace('_', '\\_', $fixture[$kind]) . '`.* TO ' . $account;
    $grants->invoke(null, $valid, $fixture); $assert(true, 'The exact scoped MySQL account was rejected.');
    $maria = $fixture; $maria['engine'] = 'mariadb-10.11'; $mariaGrants = $valid;
    $mariaGrants[0] .= " IDENTIFIED BY PASSWORD '*" . str_repeat('A', 40) . "'";
    $grants->invoke(null, $mariaGrants, $maria); $assert(true, 'The exact scoped MariaDB account was rejected.');
    $invalid = [array_slice($valid, 0, 2), [...$valid, 'GRANT SELECT ON *.* TO ' . $account], [...$valid, $valid[1]],
        [$valid[0], str_replace('\\_', '_', $valid[1]), $valid[2]],
        [$valid[0], $valid[1] . ' WITH GRANT OPTION', $valid[2]],
        [$valid[0], $valid[1], 'GRANT ALL PRIVILEGES ON `another_test_schema`.* TO ' . $account],
        [$valid[0], $valid[1], $valid[2], 'GRANT `privileged_role` TO ' . $account]];
    foreach ($invalid as $rows) $reject(fn() => $grants->invoke(null, $rows, $fixture), 'A global, broad, extra, duplicate or incomplete grant set was accepted.');

    // The real CLI must print only its redacted plan, including on failure.
    $cliDirectory = $new(); $pipes = [];
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Database/operations_ci_fixture.php', 'prepare', $cliDirectory, 'mariadb-10.11', '13306'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $assert(is_resource($process), 'The fixture CLI could not start.'); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $assert(proc_close($process) === 0 && $error === '', 'The real fixture CLI failed.');
    $cliFixture = OperationsCiFixture::load($cliDirectory);
    $assert(!str_contains($output, $cliFixture['password']) && !str_contains($output, 'CREATE USER') && json_decode($output, true, 8, JSON_THROW_ON_ERROR)['phase'] === 'prepared', 'The fixture CLI leaked provisioning material.');
    echo 'Operations CI fixture guards: ' . $checks . " checks passed.\n";
} finally { foreach (array_reverse($owned) as $path) $remove($path); }
