<?php
declare(strict_types=1);

// CLI fixture only. It is never served or copied into a customer package.
$root = realpath((string) ($argv[1] ?? ''));
$action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 64, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || !$root || !is_array($input)
    || !str_starts_with($root, sys_get_temp_dir() . '/vvx-payment-recovery-')
    || !is_file($root . '/.vvx-test-runtime')
    || !is_file($root . '/storage/temp/editorial-access-ownership.json')) {
    throw new RuntimeException('An owned disposable payment recovery installation is required.');
}
$owned = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 32, JSON_THROW_ON_ERROR);
$saved = require $root . '/config/app.php';
if (($owned['runtime'] ?? '') !== $root || !in_array($owned['mode'] ?? '', ['file', 'mysql'], true)
    || ($saved['storage_mode'] ?? '') !== $owned['mode'] || ($saved['base_url'] ?? '') !== ($owned['base'] ?? '')
    || parse_url($saved['base_url'], PHP_URL_SCHEME) !== 'http' || parse_url($saved['base_url'], PHP_URL_HOST) !== '127.0.0.1'
    || ($saved['mail']['mode'] ?? '') !== 'smtp' || ($saved['mail']['host'] ?? '') !== '127.0.0.1'
    || ($saved['mail']['port'] ?? null) !== ($owned['smtp_port'] ?? null) || !is_file($root . '/storage/install.lock')) {
    throw new RuntimeException('Only the marked loopback installation and capture transport are allowed.');
}
$pdo = null;
if ($owned['mode'] === 'mysql') {
    require_once dirname(__DIR__) . '/Installer/database_fixture.php';
    [$db, $pdo] = vvx_installer_fixture_database();
    if ($db['host'] !== '127.0.0.1' || $db['name'] !== ($owned['database'] ?? '')
        || $saved['db']['name'] !== $db['name'] || $saved['db']['user'] !== $db['user']
        || (int) $saved['db']['port'] !== $db['port']) throw new RuntimeException('The owned database identity changed.');
}
$recordsPath = $root . '/storage/temp/payment-recovery-records.json';
$records = is_file($recordsPath) ? json_decode((string) file_get_contents($recordsPath), true, 64, JSON_THROW_ON_ERROR) : null;
$json = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

if ($action === 'read') {
    // Independent committed-state observer: no bootstrap, Store, service or session.
    if (!is_array($records)) throw new RuntimeException('Seed the owned recovery fixture first.');
    $journal = $orders = $transactions = $entitlements = [];
    if ($pdo) {
        $durable = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (!preg_match('/\A[a-zA-Z0-9_]+\z/D', $table)) throw new RuntimeException('Unexpected owned table name.');
            $rows = array_map($json, $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC));
            sort($rows, SORT_STRING); $durable[$table] = $rows;
        }
        foreach ($pdo->query('SELECT record_json FROM payment_journal ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $row) {
            $value = json_decode($row, true, 32, JSON_THROW_ON_ERROR); $journal[$value['id']] = $value;
        }
        foreach ($pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orders[(string) $row['id']] = ['id' => (string) $row['id'], 'status' => $row['status'], 'orderNumber' => $row['order_number'],
                'memberId' => (string) $row['member_id'], 'grandTotal' => (float) $row['grand_total'], 'refundedTotal' => (float) $row['refunded_total'],
                'providerCheckoutId' => $row['provider_checkout_id'], 'currency' => $row['currency'], 'paidAt' => $row['paid_at']];
        }
        foreach ($pdo->query('SELECT * FROM payment_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $transactions[] = ['id' => (string) $row['id'], 'orderId' => (string) $row['order_id'], 'status' => $row['status'],
                'transactionType' => $row['transaction_type'], 'providerTransactionId' => $row['provider_transaction_id'],
                'amount' => (float) $row['amount'], 'currency' => $row['currency']];
        }
        foreach ($pdo->query('SELECT * FROM content_entitlements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entitlements[] = ['id' => (string) $row['id'], 'sourceId' => (string) $row['source_id'], 'status' => $row['status']];
        }
        $financial = array_intersect_key($durable, array_flip(['orders', 'order_items', 'payment_transactions', 'content_entitlements', 'coupon_redemptions']));
        $durable = ['sql' => $durable, 'private' => []];
        foreach (glob($root . '/storage/private/operations/*.json') ?: [] as $path) $durable['private'][basename($path)] = file_get_contents($path);
    } else {
        $data = json_decode((string) file_get_contents($root . '/storage/data.json'), true, 512, JSON_THROW_ON_ERROR);
        $durable = ['core' => $data, 'private' => []];
        foreach (glob($root . '/storage/private/operations/*.json') ?: [] as $path) $durable['private'][basename($path)] = file_get_contents($path);
        $journal = $data['paymentJournal'] ?? [];
        foreach ($data['orders'] ?? [] as $row) $orders[$row['id']] = array_intersect_key($row, array_flip(['id', 'status', 'orderNumber', 'memberId', 'grandTotal', 'refundedTotal', 'providerCheckoutId', 'currency', 'paidAt']));
        foreach ($data['paymentTransactions'] ?? [] as $row) $transactions[] = array_intersect_key($row, array_flip(['id', 'orderId', 'status', 'transactionType', 'providerTransactionId', 'amount', 'currency']));
        foreach ($data['entitlements'] ?? [] as $row) $entitlements[] = array_intersect_key($row, array_flip(['id', 'sourceId', 'status']));
        $financial = array_intersect_key($data, array_flip(['orders', 'orderItems', 'paymentTransactions', 'entitlements', 'couponRedemptions']));
    }
    ksort($journal, SORT_STRING); ksort($orders, SORT_STRING);
    $protected = [];
    foreach (['config/app.php', 'storage/install.lock', 'uploads/editorial-existing.bin', 'storage/private/editorial-existing.bin', 'storage/backups/editorial-existing.txt'] as $path) $protected[$path] = hash_file('sha256', $root . '/' . $path);
    $lines = static fn(string $path): array => is_file($path) ? array_values(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
    echo $json(['mode' => $owned['mode'], 'mysql_version' => $pdo?->query('SELECT VERSION()')->fetchColumn(), 'php_cli_version' => PHP_VERSION,
        'journal' => $journal, 'orders' => $orders, 'transactions' => $transactions, 'entitlements' => $entitlements,
        'all_sha256' => hash('sha256', $json($durable)), 'financial_sha256' => hash('sha256', $json($financial)), 'protected' => $protected,
        'addon_boots' => count($lines($root . '/storage/temp/payment-recovery-addon-boots.jsonl')),
        'addon_deliveries' => array_map(static fn(string $line): array => json_decode($line, true, 16, JSON_THROW_ON_ERROR), $lines($root . '/storage/temp/payment-recovery-addon-deliveries.jsonl'))]);
    exit;
}

define('ROOT_PATH', $root);
require_once $root . '/includes/version.php';
require_once $root . '/includes/autoload.php';
require_once $root . '/app/Support/functions.php';
$config = app_config(); $store = app_store();
if ($action === 'seed') {
    if ($records !== null || $store->member('recovery-buyer@example.test')) throw new RuntimeException('The recovery fixture is already initialized.');
    $owner = $store->findAdminByEmail('owner@example.test');
    if (!$owner) throw new RuntimeException('The reserved fixture owner is missing.');
    $store->savePaymentProvider(['key' => 'sandbox', 'enabled' => true, 'supportsRecurring' => true]);
    $member = $store->createMember(['displayName' => 'RECOVERY_PRIVATE_CUSTOMER_NAME', 'email' => 'recovery-buyer@example.test',
        'password' => 'VelvetMember12', 'status' => 'active', 'emailVerifiedAt' => gmdate('c', time() - 10)]);
    $payments = new App\Core\PaymentOperationService($store, $config, new App\Payments\PaymentProviderRegistry());
    $records = ['member' => (string) $member['id'], 'owner' => (string) $owner['id'], 'owner_original_hash' => $owner['password_hash'], 'operations' => [], 'orders' => []];
    $create = static function (string $label, bool $execute = true) use ($store, $payments, $member): array {
        $video = $store->saveVideo(['title' => 'Owned recovery video ' . $label, 'status' => 'published', 'accessMode' => 'purchase', 'standardPrice' => 19.25, 'currency' => 'USD']);
        return $store->withCommerceLock(static function () use ($store, $payments, $member, $label, $video, $execute): array {
            $scope = $payments->captureScope('sandbox');
            [$order, $operation] = $store->paymentAtomic(static function () use ($store, $payments, $member, $label, $video, $scope): array {
                $payments->assertCurrentScope($scope);
                $order = $store->createOrder(['memberId' => $member['id'], 'orderNumber' => 'RECOVERY-' . strtoupper($label), 'status' => 'processing',
                    'subtotal' => 19.25, 'grandTotal' => 19.25, 'currency' => 'USD', 'providerKey' => 'sandbox',
                    'metadata' => ['private' => 'RECOVERY_PRIVATE_ORDER_METADATA', 'shippingAddress' => ['street' => 'RECOVERY_PRIVATE_ADDRESS']]],
                    [['itemType' => 'video', 'itemId' => $video['id'], 'title' => 'Owned recovery purchase', 'unitPrice' => 19.25, 'lineTotal' => 19.25]]);
                return [$order, $payments->prepare($payments->bindingInScope($order, $scope['scope'], 'charge', 1925), 'checkout')];
            }, (string) $member['id']);
            if ($execute) $operation = $payments->execute($operation, $order, ['sandboxResult' => 'success', 'unusedPrivateInput' => 'RECOVERY_RAW_PAYMENT_INPUT'])['operation'];
            if ($operation['state'] !== ($execute ? 'confirmed' : 'prepared')) throw new RuntimeException('The genuine sandbox operation did not reach its expected checkpoint.');
            return ['order' => $order, 'operation' => $operation];
        });
    };
    foreach (['confirmed', 'temporary', 'uncertain', 'addon-retry', 'script-free'] as $label) {
        $made = $create($label); $records['orders'][$label] = (string) $made['order']['id']; $records['operations'][$label] = $made['operation']['id'];
    }
    $video = $store->saveVideo(['title' => 'Owned pending sandbox video', 'status' => 'published', 'accessMode' => 'purchase', 'standardPrice' => 9.50, 'currency' => 'USD']);
    $pending = commerce_service()->checkout($member['id'], [['type' => 'video', 'id' => (string) $video['id'], 'mode' => 'purchase']], '', 'sandbox', ['sandboxResult' => 'processing'], 'recovery:pending');
    if ($pending['paymentOperation']['state'] !== 'pending' || $pending['order']['status'] !== 'processing') throw new RuntimeException('Normal sandbox processing checkout did not remain pending.');
    $records['orders']['pending'] = (string) $pending['order']['id']; $records['operations']['pending'] = $pending['paymentOperation']['id'];
    for ($i = 0; $i < 55; ++$i) {
        $made = $create('page-' . $i, false); $records['orders']['page-' . $i] = (string) $made['order']['id']; $records['operations']['page-' . $i] = $made['operation']['id'];
    }
    $addonPath = $root . '/addons/installed/local.recovery.observer';
    if (!is_dir($addonPath)) mkdir($addonPath, 0700, true);
    $manifest = ['id' => 'local.recovery.observer', 'name' => 'Owned recovery observer', 'vendor' => 'Acceptance fixture', 'version' => '1.0.0',
        'minimum_core_version' => '0.9.0', 'minimum_php_version' => '8.2.0', 'permissions' => ['events.subscribe'], 'bootstrap' => 'bootstrap.php'];
    file_put_contents($addonPath . '/addon.json', $json($manifest));
    file_put_contents($addonPath . '/bootstrap.php', <<<'ADDON'
<?php
declare(strict_types=1);
if (!defined('ROOT_PATH') || !str_starts_with(ROOT_PATH, sys_get_temp_dir() . '/vvx-payment-recovery-')
    || !is_file(ROOT_PATH . '/storage/temp/payment-recovery-records.json')) throw new RuntimeException('Owned recovery observer only.');
file_put_contents(ROOT_PATH . '/storage/temp/payment-recovery-addon-boots.jsonl', "boot\n", FILE_APPEND | LOCK_EX);
return static function (\App\Core\AddonContext $addon): void {
    $addon->on('order.completed', static function (array $payload): array {
        $path = ROOT_PATH . '/storage/temp/payment-recovery-addon-deliveries.jsonl';
        file_put_contents($path, json_encode(['orderId' => (string) $payload['order']['id'], 'effectId' => $payload['paymentEffectId'] ?? null], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        chmod($path, 0600);
        $mode = is_file(ROOT_PATH . '/storage/temp/payment-recovery-addon-mode') ? trim((string) file_get_contents(ROOT_PATH . '/storage/temp/payment-recovery-addon-mode')) : 'accepted';
        if ($mode === 'unknown') throw new RuntimeException('Owned observer received the effect before acknowledgement was lost.');
        return $payload;
    });
};
ADDON);
    $store->saveAddon(['addonKey' => $manifest['id'], 'status' => 'active', 'manifest' => $manifest]);
    file_put_contents($recordsPath, $json($records)); chmod($recordsPath, 0600);
    $public = $records; unset($public['owner_original_hash']); echo $json($public); exit;
}
if (!is_array($records)) throw new RuntimeException('Seed the owned recovery fixture first.');
if ($action === 'age-session') {
    $id = $input['id'] ?? '';
    if (!is_string($id) || !preg_match('/\A[a-zA-Z0-9,-]{20,128}\z/D', $id)) throw new RuntimeException('Choose the owned owner session.');
    $directory = $root . '/storage/sessions'; $path = $directory . '/sess_' . $id;
    if (!is_file($path) || is_link($path) || realpath(dirname($path)) !== $directory) throw new RuntimeException('The owned session file is unavailable.');
    session_save_path($directory); session_id($id); session_start();
    if (($_SESSION['admin']['email'] ?? '') !== 'owner@example.test') throw new RuntimeException('Only the reserved owner session may be aged.');
    $_SESSION['admin']['authenticated_at'] = time() - 901; session_write_close(); echo '{"aged":true}'; exit;
}
if ($action === 'owner-state') {
    $value = $input['value'] ?? '';
    if (!in_array($value, ['owner', 'demoted', 'inactive', 'password-changed'], true)) throw new RuntimeException('Choose an owned authority negative control.');
    $role = $value === 'demoted' ? 'editor' : 'owner'; $active = $value === 'inactive' ? 0 : 1;
    $password = $value === 'password-changed' ? password_hash('ChangedOwnedPassword12', PASSWORD_DEFAULT) : $records['owner_original_hash'];
    if ($pdo) $pdo->prepare('UPDATE admins SET role=?,is_active=?,password_hash=? WHERE id=? AND email=?')->execute([$role, $active, $password, $records['owner'], 'owner@example.test']);
    else $store->mutateFileStore(static function (array &$data) use ($records, $role, $active, $password): void {
        if (($data['admins'][$records['owner']]['email'] ?? '') !== 'owner@example.test') throw new RuntimeException('Owned administrator identity changed.');
        $data['admins'][$records['owner']]['role'] = $role; $data['admins'][$records['owner']]['is_active'] = $active; $data['admins'][$records['owner']]['password_hash'] = $password;
    });
    echo $json(['state' => $value]); exit;
}
if ($action === 'addon-mode') {
    $value = $input['value'] ?? '';
    if (!in_array($value, ['accepted', 'unknown', 'safe', 'enabled'], true)) throw new RuntimeException('Choose an owned addon mode.');
    if (in_array($value, ['safe', 'enabled'], true)) $store->setAddonsSafeMode($value === 'safe');
    else { file_put_contents($root . '/storage/temp/payment-recovery-addon-mode', $value); chmod($root . '/storage/temp/payment-recovery-addon-mode', 0600); }
    echo $json(['mode' => $value]); exit;
}
if (in_array($action, ['hold-journal', 'restore-journal'], true)) {
    // Corrupt a record on the requested first keyset page, independently of random operation IDs.
    $id = min(array_values($records['operations'])); $held = $root . '/storage/temp/payment-recovery-held-journal.json';
    if ($action === 'hold-journal') {
        if (file_exists($held)) throw new RuntimeException('The owned journal is already held.');
        if ($pdo) {
            $statement = $pdo->prepare('SELECT record_json FROM payment_journal WHERE id=?'); $statement->execute([$id]); $original = $statement->fetchColumn();
            if (!is_string($original)) throw new RuntimeException('The owned journal is absent.');
            file_put_contents($held, $original); $pdo->prepare('UPDATE payment_journal SET record_json=? WHERE id=?')->execute(['{', $id]);
        } else $store->mutateFileStore(static function (array &$data) use ($id, $held, $json): void {
            file_put_contents($held, $json($data['paymentJournal'][$id])); $data['paymentJournal'][$id]['state'] = 'owned-corrupt-negative-control';
        });
        chmod($held, 0600);
    } else {
        if (!is_file($held)) throw new RuntimeException('The owned journal backup is absent.');
        $original = (string) file_get_contents($held); json_decode($original, true, 32, JSON_THROW_ON_ERROR);
        if ($pdo) $pdo->prepare('UPDATE payment_journal SET record_json=? WHERE id=?')->execute([$original, $id]);
        else $store->mutateFileStore(static function (array &$data) use ($id, $original): void { $data['paymentJournal'][$id] = json_decode($original, true, 32, JSON_THROW_ON_ERROR); });
        unlink($held);
    }
    echo '{}'; exit;
}
throw new RuntimeException('Unknown owned payment recovery fixture action.');
