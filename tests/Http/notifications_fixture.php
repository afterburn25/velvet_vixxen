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
$config = new App\Core\Config($root . '/config/app.php');
$store = new App\Core\Store($config);
$private = new App\Core\PrivateState($config, $root);
$service = new App\Core\NotificationService($config, $store, null, $private);
$input = json_decode((string) ($argv[3] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
if (!is_array($input)) throw new RuntimeException('A fixture input object is required.');
$marketing = new Velvet\Core\MarketingStore($root);
$reader = static function () use ($store): array {
    $member = $store->member('notice-reader@example.test');
    if (!$member) throw new RuntimeException('The dedicated notification reader is absent.');
    return $member;
};
if ($action === 'inspect') {
    $raw = $private->read('notifications');
    $savedBytes = $owned['mode'] === 'file' ? (is_file($root . '/storage/private/operations/notifications.json')
        ? file_get_contents($root . '/storage/private/operations/notifications.json') : null) : null;
    $sqlRows = $owned['mode'] === 'mysql' ? $pdo->query('SELECT * FROM private_state')->fetchAll(PDO::FETCH_ASSOC) : null;
    echo json_encode(['overview' => $service->overview(), 'raw' => $raw, 'committed_file' => $savedBytes,
        'committed_sql' => $sqlRows, 'members' => $store->members(), 'mysql_version' => $owned['mode'] === 'mysql' ? $pdo->query('SELECT VERSION()')->fetchColumn() : null], JSON_THROW_ON_ERROR);
} elseif ($action === 'seed') {
    if ($store->member('notice-reader@example.test')) throw new RuntimeException('The notification fixture must start empty.');
    $reader = $store->createMember(['displayName' => 'Notice reader', 'email' => 'notice-reader@example.test', 'password' => 'Notification-Fixture-781!',
        'status' => 'active', 'emailVerifiedAt' => gmdate('Y-m-d H:i:s', time() - 10),
        'preferences' => ['newContentAlerts' => true, 'emailUpdates' => true]]);
    if ($owned['mode'] === 'file') $store->mutateFileStore(static function (array &$data) use ($reader): void { $data['members'][$reader['id']]['emailVerifiedAt'] = gmdate('c', time() - 10); });
    else $pdo->prepare('UPDATE members SET email_verified_at=DATE_SUB(NOW(),INTERVAL 10 SECOND) WHERE id=?')->execute([$reader['id']]);
    $pending = $store->createMember(['displayName' => 'Notice pending', 'email' => 'notice-pending@example.test', 'password' => 'Notification-Fixture-781!',
        'status' => 'pending', 'preferences' => ['registrationReminder' => true, 'registrationReminderConsentedAt' => gmdate('c', time() - 90000)]]);
    $store->createMember(['displayName' => 'Notice without consent', 'email' => 'notice-no-consent@example.test', 'password' => 'Notification-Fixture-781!',
        'status' => 'pending', 'preferences' => []]);
    if ($owned['mode'] === 'file') $store->mutateFileStore(static function (array &$data) use ($pending): void { $data['members'][$pending['id']]['createdAt'] = gmdate('c', time() - 90000); });
    else $pdo->prepare('UPDATE members SET created_at=DATE_SUB(NOW(),INTERVAL 25 HOUR) WHERE id=?')->execute([$pending['id']]);
    $plan = $store->saveMembershipPlan(['name' => 'Notification plan', 'key' => 'notification-plan', 'active' => true, 'price' => 10]);
    $subscription = $store->saveSubscription(['memberId' => $reader['id'], 'planId' => $plan['id'], 'status' => 'active', 'currentPeriodEnd' => gmdate('c', time() + 3600)]);
    $renewal = $store->createOrder(['memberId' => $reader['id'], 'status' => 'paid', 'paidAt' => gmdate('c'), 'metadata' => ['renewal' => true], 'providerKey' => 'sandbox'], []);
    $failed = $store->createOrder(['memberId' => $reader['id'], 'status' => 'failed'], []);
    $post = $marketing->upsert('posts', ['title' => 'PRIVATE_TITLE_SENTINEL_781', 'slug' => 'notification-first-post', 'body' => 'PRIVATE_BODY_SENTINEL_781', 'status' => 'published', 'access' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []]]);
    $service->enqueueOwnerAlert('suspicious_admin_login', 'security:notification-fixture', ['when' => gmdate('c'), 'url' => 'admin/']);
    echo json_encode(['reader' => $reader['id'], 'pending' => $pending['id'], 'post' => $post['id'], 'subscription' => $subscription['id'], 'renewal' => $renewal['id'], 'failed' => $failed['id']], JSON_THROW_ON_ERROR);
} elseif ($action === 'post') {
    $name = $input['name'] ?? '';
    if (!is_string($name) || preg_match('/\A[a-z][a-z0-9-]{0,50}\z/D', $name) !== 1) throw new RuntimeException('A dedicated fixture publication label is required.');
    $post = $marketing->upsert('posts', ['title' => 'PRIVATE_TITLE_SENTINEL_781 ' . $name, 'slug' => 'notification-' . $name, 'body' => 'PRIVATE_BODY_SENTINEL_781', 'status' => 'published', 'access' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []]]);
    echo json_encode(['post' => $post['id']], JSON_THROW_ON_ERROR);
} elseif ($action === 'consent') {
    if (!is_bool($input['enabled'] ?? null)) throw new RuntimeException('An explicit notification preference is required.');
    $member = $reader();
    $store->updateMember($member['id'], ['preferences' => array_replace($member['preferences'], ['newContentAlerts' => $input['enabled']])]);
    echo json_encode(['enabled' => $input['enabled']], JSON_THROW_ON_ERROR);
} elseif ($action === 'claim') {
    $claim = (new ReflectionMethod($service, 'claim'))->invoke($service, null);
    if (!$claim['job']) throw new RuntimeException('A real queued notice is required for interruption testing.');
    echo json_encode($claim['job'], JSON_THROW_ON_ERROR);
} elseif ($action === 'expire-claim') {
    $id = $input['id'] ?? '';
    $sending = $input['sending'] ?? false;
    if (!is_string($id) || !is_bool($sending)) throw new RuntimeException('A real claim is required.');
    $private->update('notifications', static function (array &$data) use ($id, $sending): void {
        if (($data['jobs'][$id]['status'] ?? '') !== 'claimed' || !is_array($data['jobs'][$id]['claim'])) throw new RuntimeException('Only a real pre-body fixture claim may be interrupted.');
        $data['jobs'][$id]['claim']['until'] = time() - 1;
        if ($sending) { $data['jobs'][$id]['status'] = 'sending'; $data['jobs'][$id]['recipient_hash'] = $data['jobs'][$id]['email_hash']; }
    });
    echo json_encode(['expired' => $id, 'sending' => $sending], JSON_THROW_ON_ERROR);
} elseif ($action === 'retry-age') {
    $id = $input['id'] ?? '';
    $private->update('notifications', static function (array &$data) use ($id): void {
        if (!is_string($id) || ($data['jobs'][$id]['status'] ?? '') !== 'failed' || ($data['jobs'][$id]['retryable'] ?? null) !== true) throw new RuntimeException('Only a definitely failed fixture notice can age its retry cooldown.');
        $data['jobs'][$id]['next_attempt_at'] = time() - 1;
    });
    echo json_encode(['aged' => $id], JSON_THROW_ON_ERROR);
} else throw new RuntimeException('Unknown private notification fixture action.');
