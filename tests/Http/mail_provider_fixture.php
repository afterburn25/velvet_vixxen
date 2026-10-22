<?php
declare(strict_types=1);

// CLI-only ephemeral signing and actual-source setup in an owned disposable runtime.
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
$config = new App\Core\Config($root . '/config/app.php');
$store = new App\Core\Store($config);
$private = new App\Core\PrivateState($config, $root);
$service = new App\Core\MailProviderService($config, $store, $private);
$input = json_decode((string) ($argv[3] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
if (!is_array($input)) throw new RuntimeException('A fixture input object is required.');
$keyPath = $root . '/storage/temp/provider-signing.pem';
if ($action === 'key') {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if (!$key || !openssl_pkey_export($key, $pem)) throw new RuntimeException('Actual ephemeral signing key generation failed.');
    $file = fopen($keyPath, 'xb');
    if (!$file || !chmod($keyPath, 0600) || fwrite($file, $pem) !== strlen($pem) || !fflush($file)) throw new RuntimeException('The private fixture key could not be saved safely.');
    fclose($file);
    $public = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', openssl_pkey_get_details($key)['key']);
    echo json_encode(['public_key' => $public], JSON_THROW_ON_ERROR);
} elseif ($action === 'sign') {
    $body = $input['raw'] ?? null;
    $timestamp = $input['timestamp'] ?? (string) time();
    if (!is_string($body) || strlen($body) > 1048576 || !is_string($timestamp) || preg_match('/\A[0-9]{10,12}\z/D', $timestamp) !== 1
        || is_link($keyPath) || !is_file($keyPath) || (fileperms($keyPath) & 0777) !== 0600) throw new RuntimeException('Bounded fixture bytes and a private owned signing key are required.');
    $key = openssl_pkey_get_private((string) file_get_contents($keyPath));
    if (!$key || !openssl_sign($timestamp . $body, $signature, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Actual fixture signature failed.');
    echo json_encode(['raw' => $body, 'signature' => base64_encode($signature), 'timestamp' => $timestamp], JSON_THROW_ON_ERROR);
} elseif ($action === 'inspect' || $action === 'private-only') {
    $raw = $private->read('mail_provider');
    $savedBytes = $owned['mode'] === 'file' && is_file($root . '/storage/private/operations/mail_provider.json') ? file_get_contents($root . '/storage/private/operations/mail_provider.json') : null;
    $sqlRows = $owned['mode'] === 'mysql' ? $pdo->query("SELECT * FROM private_state WHERE namespace='mail_provider'")->fetchAll(PDO::FETCH_ASSOC) : null;
    $result = ['overview' => $service->overview(), 'raw' => $raw, 'committed_file' => $savedBytes, 'committed_sql' => $sqlRows,
        'mysql_version' => $owned['mode'] === 'mysql' ? $pdo->query('SELECT VERSION()')->fetchColumn() : null];
    if ($action === 'inspect') {
        $result['marketing'] = (new Velvet\Core\MarketingStore($root))->all();
        $result['notifications'] = $private->read('notifications');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} elseif ($action === 'receive') {
    // Same production service and real clock. This process can be killed after
    // its verified commit while the actual independent marketing lock is held.
    echo json_encode($service->receive($input['raw'], $input['signature'], $input['timestamp']), JSON_THROW_ON_ERROR);
} elseif (in_array($action, ['seed', 'campaign', 'post', 'unknown-contact'], true)) {
    $marketing = new Velvet\Core\MarketingStore($root);
    $owner = $store->findAdminByEmail('owner@example.test');
    if (!$owner || $owner['role'] !== 'owner') throw new RuntimeException('The owned fixture owner is required.');
    $notices = new App\Core\NotificationService($config, $store, null, $private);
    $name = $input['name'] ?? 'initial';
    if (!is_string($name) || preg_match('/\A[a-z][a-z0-9-]{0,40}\z/D', $name) !== 1) throw new RuntimeException('A dedicated fixture label is required.');
    $result = [];
    if ($action === 'seed') {
        $marketing->setSettings(['campaign_delivery_enabled' => true]);
        foreach (['alpha', 'beta'] as $label) {
            $email = $label . '@example.test';
            if ($store->member($email)) throw new RuntimeException('The fixture recipients must start empty.');
            $member = $store->createMember(['displayName' => 'Provider ' . $label, 'email' => $email, 'password' => 'Provider-Fixture-781!',
                'status' => 'active', 'emailVerifiedAt' => gmdate('Y-m-d H:i:s', time() - 10), 'preferences' => ['newContentAlerts' => true]]);
            if ($owned['mode'] === 'file') $store->mutateFileStore(static function (array &$data) use ($member): void { $data['members'][$member['id']]['emailVerifiedAt'] = gmdate('c', time() - 10); });
            else $pdo->prepare('UPDATE members SET email_verified_at=DATE_SUB(NOW(),INTERVAL 10 SECOND) WHERE id=?')->execute([$member['id']]);
            $contact = $marketing->subscribe($email, 'Provider ' . $label, 'owned provider acceptance', true);
            if (!$marketing->confirm($contact['token'])) throw new RuntimeException('Actual newsletter consent failed.');
            $result['members'][$label] = $member['id'];
        }
        $notices->saveSettings(['enabled' => true, 'types' => ['new_content']], $notices->overview()['revision'], $owner);
    }
    if ($action === 'unknown-contact') {
        $contact = $marketing->subscribe('unknown@example.test', 'Unknown transport fixture', 'owned provider acceptance', true);
        if (!$marketing->confirm($contact['token'])) throw new RuntimeException('Actual newsletter consent failed.');
    }
    if (in_array($action, ['seed', 'campaign', 'unknown-contact'], true)) {
        $campaign = $marketing->saveCampaignMessage(['subject' => 'Provider fixture ' . $name, 'body' => 'A consented local delivery for signed event acceptance.', 'audience' => ['mode' => 'all']], $owner);
        $result['campaign'] = $campaign['id']; $result['campaign_queued'] = $marketing->queueCampaign($campaign['id']);
    }
    if (in_array($action, ['seed', 'post'], true)) {
        $post = $marketing->upsert('posts', ['title' => 'PRIVATE_PROVIDER_TITLE ' . $name, 'slug' => 'provider-' . $name, 'body' => 'PRIVATE_PROVIDER_BODY',
            'status' => 'published', 'access' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []]]);
        $result['post'] = $post['id']; $result['discovery'] = $notices->discover(100, $owner);
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} else throw new RuntimeException('Unknown private provider fixture action.');
