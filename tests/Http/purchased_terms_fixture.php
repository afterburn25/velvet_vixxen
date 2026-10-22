<?php
declare(strict_types=1);

// Private CLI-only lease/cooldown fixture. Never copied into customer packages.
$root = realpath((string) ($argv[1] ?? ''));
$action = $argv[2] ?? '';
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 64, JSON_THROW_ON_ERROR);
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
require_once dirname(__DIR__) . '/Installer/database_fixture.php';
$recordsPath = $root . '/storage/temp/purchased-terms-records.json';
$records = is_file($recordsPath) ? json_decode((string) file_get_contents($recordsPath), true, 32, JSON_THROW_ON_ERROR) : null;
if ($action === 'read') {
    if (!is_array($records)) throw new RuntimeException('Seed the owned terms fixture first.');
    if ($owned['mode'] === 'file') {
        $raw = json_decode((string) file_get_contents($root . '/storage/data.json'), true, 512, JSON_THROW_ON_ERROR);
        $orders = array_values(array_filter($raw['orders'] ?? [], static fn(array $row):bool => (string)$row['memberId'] === $records['member']));
        foreach ($orders as &$order) $order['items'] = $raw['orderItems'][$order['id']] ?? []; unset($order);
        $transactions = array_values($raw['paymentTransactions'] ?? []); $journal = array_values($raw['paymentJournal'] ?? []);
        $entitlements = array_values($raw['entitlements'] ?? []);
    } else {
        $orders = [];
        $statement = $pdo->prepare('SELECT * FROM orders WHERE member_id=? ORDER BY id'); $statement->execute([$records['member']]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = $pdo->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id'); $item->execute([$row['id']]);
            $items = array_map(static fn(array $i):array => ['id'=>(string)$i['id'],'itemType'=>$i['item_type'],'itemId'=>(string)$i['item_id'],'metadata'=>json_decode($i['metadata_json'],true,32,JSON_THROW_ON_ERROR),'fulfillmentStatus'=>$i['fulfillment_status']],$item->fetchAll(PDO::FETCH_ASSOC));
            $orders[] = ['id'=>(string)$row['id'],'orderNumber'=>$row['order_number'],'status'=>$row['status'],'grandTotal'=>(float)$row['grand_total'],'currency'=>$row['currency'],'items'=>$items];
        }
        $transactions = $pdo->query('SELECT id,order_id,status FROM payment_transactions')->fetchAll(PDO::FETCH_ASSOC);
        $journal = array_map(static fn(string $json):array=>json_decode($json,true,32,JSON_THROW_ON_ERROR),$pdo->query('SELECT record_json FROM payment_journal')->fetchAll(PDO::FETCH_COLUMN));
        $entitlements = array_map(static fn(array $row):array=>['sourceId'=>(string)$row['source_id'],'contentId'=>(string)$row['content_id'],'status'=>$row['status']],$pdo->query('SELECT * FROM content_entitlements')->fetchAll(PDO::FETCH_ASSOC));
    }
    echo json_encode(['orders'=>$orders,'transactions'=>$transactions,'journal'=>$journal,'entitlements'=>$entitlements,'database'=>isset($pdo)?$pdo?->query('SELECT VERSION()')->fetchColumn():null],JSON_THROW_ON_ERROR);exit;
}
define('ROOT_PATH', $root);
require_once $root . '/includes/version.php'; require_once $root . '/includes/autoload.php'; require_once $root . '/app/Support/functions.php';
$config = app_config(); $store = app_store();
if ($action === 'seed') {
    if ($records !== null || $store->member('purchased-terms-buyer@example.test')) throw new RuntimeException('Owned terms fixture already seeded.');
    $member = $store->createMember(['displayName'=>'Purchased Terms Buyer','email'=>'purchased-terms-buyer@example.test','password'=>'Purchased-Terms-123!','status'=>'active','emailVerifiedAt'=>gmdate('c',time()-10)]);
    $plan = $store->saveMembershipPlan(['name'=>'Purchased membership','key'=>'purchased-terms','price'=>19.25,'currency'=>'USD','billingInterval'=>'month','intervalCount'=>2,'trialDays'=>0,'ppvDiscountPercent'=>20,'benefits'=>['Purchased benefit'],'isActive'=>true]);
    $a = $store->saveVideo(['title'=>'Purchased content A','status'=>'published','accessMode'=>'purchase','standardPrice'=>10,'currency'=>'USD']);
    $b = $store->saveVideo(['title'=>'Replacement content B','status'=>'published','accessMode'=>'purchase','standardPrice'=>10,'currency'=>'USD']);
    $bundle = $store->saveBundle(['title'=>'Purchased bundle','price'=>7.50,'currency'=>'USD','isActive'=>true,'items'=>[['itemType'=>'video','itemId'=>$a['id']]]]);
    $store->saveSubscription(['memberId'=>$member['id'],'planId'=>$plan['id'],'status'=>'expired','providerKey'=>'sandbox','currentPeriodEnd'=>gmdate('c',time()-86400)]);
    $store->savePaymentProvider(['key'=>'sandbox','enabled'=>true,'supportsRecurring'=>true]);
    $records = ['member'=>(string)$member['id'],'plan'=>$plan,'bundle'=>$bundle,'a'=>$a,'b'=>$b];
    file_put_contents($recordsPath,json_encode($records,JSON_THROW_ON_ERROR));echo json_encode($records,JSON_THROW_ON_ERROR);exit;
}
if (!is_array($records)) throw new RuntimeException('Seed the owned terms fixture first.');
if ($action === 'edit-plan') {
    $value = (int)($input['price'] ?? 99);
    $store->saveMembershipPlan(array_merge($records['plan'],['name'=>'Edited membership','price'=>$value,'currency'=>'EUR','billingInterval'=>'year','intervalCount'=>1,'trialDays'=>90,'ppvDiscountPercent'=>85,'benefits'=>['Edited benefit']]));
} elseif ($action === 'edit-bundle') {
    $store->saveBundle(array_merge($records['bundle'],['title'=>'Edited bundle','price'=>99,'items'=>[['itemType'=>'video','itemId'=>$records['b']['id']]]]));
} elseif ($action === 'confirm-only') {
    $order = $store->order((string)($input['order'] ?? ''));
    if (!$order || $order['memberId'] !== $records['member'] || $order['status'] !== 'processing') throw new RuntimeException('Only the owned pending purchase can receive fixture evidence.');
    $operations = array_values(array_filter($store->paymentJournalForOrder($order['id']),static fn(array $row):bool=>$row['kind']==='operation'&&$row['data']['binding']['kind']==='charge'));
    if (count($operations)!==1 || $operations[0]['state']!=='pending') throw new RuntimeException('Owned sandbox pending operation required.');
    $operation = $operations[0];
    $payments = new App\Core\PaymentOperationService($store,$config,new App\Payments\PaymentProviderRegistry());
    // Simulated normalized provider evidence. No transport or second charge is invoked.
    $confirmed = $store->withCommerceLock(fn():array=>$payments->receive($operation,['schema'=>1,'binding'=>$operation['data']['binding'],'outcome'=>'succeeded','transactionId'=>'terms-fixture-confirmed-'.$order['id'],'observedAt'=>time()]));
    echo json_encode(['record'=>$confirmed['operation']['id'],'state'=>$confirmed['operation']['state']],JSON_THROW_ON_ERROR);exit;
} else throw new RuntimeException('Unknown owned terms fixture action.');
echo json_encode(['ok'=>true],JSON_THROW_ON_ERROR);
