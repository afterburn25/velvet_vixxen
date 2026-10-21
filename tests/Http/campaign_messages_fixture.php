<?php
declare(strict_types=1);

// Private CLI-only lease/cooldown fixture. Never copied into customer packages.
$root = realpath((string) ($argv[1] ?? ''));
$action = $argv[2] ?? '';
if (PHP_SAPI !== 'cli' || !$root || !str_starts_with($root, sys_get_temp_dir() . '/')
    || !is_file($root . '/.vvx-test-runtime') || !is_file($root . '/storage/temp/editorial-access-ownership.json')) {
    throw new RuntimeException('An owned disposable runtime is required.');
}
$owned = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 512, JSON_THROW_ON_ERROR);
$saved = require $root . '/config/app.php';
if (($owned['runtime'] ?? '') !== $root || ($saved['storage_mode'] ?? '') !== ($owned['mode'] ?? '')
    || ($saved['base_url'] ?? '') !== ($owned['base'] ?? '') || parse_url($saved['base_url'], PHP_URL_HOST) !== '127.0.0.1'
    || ($saved['mail']['host'] ?? '') !== '127.0.0.1' || ($saved['mail']['port'] ?? '') !== ($owned['smtp_port'] ?? '')
    || ($saved['mail']['mode'] ?? '') !== 'smtp' || !in_array($owned['mode'], ['file', 'mysql'], true)) {
    throw new RuntimeException('The exact loopback fixture configuration is required.');
}
if ($owned['mode'] === 'mysql') {
    require_once dirname(__DIR__) . '/Installer/database_fixture.php';
    [$db, $pdo] = vvx_installer_fixture_database();
    if ($db['host'] !== '127.0.0.1' || $db['name'] !== ($owned['database'] ?? '') || $saved['db']['name'] !== $db['name']
        || $saved['db']['user'] !== $db['user'] || (int) $saved['db']['port'] !== $db['port']) throw new RuntimeException('Only the owned test database is allowed.');
}
define('ROOT_PATH', $root);
require $root . '/includes/autoload.php';
require $root . '/app/Support/functions.php';
require $root . '/app/Core/MarketingStore.php';
$marketing = new Velvet\Core\MarketingStore($root);
if ($action === 'seed-recipient') {
    foreach ($marketing->all()['contacts'] as $contact) {
        if (($contact['email'] ?? '') === 'campaign-recipient@example.test') {
            throw new RuntimeException('The dedicated fixture recipient must not already exist.');
        }
    }
    $subscription = $marketing->subscribe('campaign-recipient@example.test', 'Campaign recipient fixture', 'campaign-messages-acceptance', true);
    if (!$marketing->confirm($subscription['token'])) throw new RuntimeException('The dedicated recipient could not confirm consent.');
    echo json_encode(['recipient' => $subscription['contact']['email'], 'confirmed' => true], JSON_THROW_ON_ERROR);
} elseif ($action === 'age-tests') {
    $count = $marketing->update(static function (array &$data): int {
        $count = 0;
        foreach ($data['settings']['campaign_test_journal'] as &$row) {
            if (in_array($row['status'] ?? '', ['claimed', 'sending'], true)) throw new RuntimeException('Do not alter an active test request.');
            $row['created_epoch'] = min($row['created_epoch'], time() - 61); ++$count;
        }
        return $count;
    });
    echo json_encode(['aged' => $count], JSON_THROW_ON_ERROR);
} else throw new RuntimeException('Unknown private campaign fixture action.');
