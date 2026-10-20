<?php
declare(strict_types=1);

// A separately killable process for the actual activation boundary. It can
// install only a fixture ZIP inside the marked disposable acceptance directory.
$root = realpath((string) ($argv[1] ?? ''));
$archive = realpath((string) ($argv[2] ?? ''));
if (PHP_SAPI !== 'cli' || $root === false || $archive === false
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), '/') . '/')
    || !is_file($root . '/.vvx-installer-db-fixture') || !is_file($root . '/.vvx-addon-acceptance')
    || !str_starts_with($archive, dirname($root) . '/') || !str_ends_with($archive, '.zip')) {
    throw new RuntimeException('A marked disposable add-on lifecycle fixture is required.');
}
$values = require $root . '/config/app.php';
if (($values['addon_acceptance_fixture'] ?? false) !== true || ($values['storage_mode'] ?? '') !== 'mysql'
    || ($values['db']['host'] ?? '') !== '127.0.0.1' || !preg_match('/(?:^|_)test(?:_|$)/i', (string) ($values['db']['name'] ?? ''))) {
    throw new RuntimeException('Refusing a non-fixture add-on database.');
}
define('ROOT_PATH', $root);
require $root . '/includes/bootstrap.php';
$_SESSION = [];
$manager = new \App\Core\AddonManager(new \App\Core\Store(new \App\Core\Config($root . '/config/app.php')));
$review = $manager->stageRemotePackage($archive, (string) hash_file('sha256', $archive));
if (($review['manifest']['id'] ?? '') !== 'fixture.mysql-lifecycle') throw new RuntimeException('Only the known add-on fixture may run.');
$manager->install((string) $review['token'], true, true);
