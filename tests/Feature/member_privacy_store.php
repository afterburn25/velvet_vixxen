<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Store;

$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/vvx-privacy-store-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true); mkdir($root . '/storage', 0700, true);
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require $source . '/app/Support/functions.php';
require $source . '/tests/Installer/database_fixture.php';
$mysql = in_array('--mysql', $argv, true); $pdo = null; $owns = false; $checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call, string $message) use ($assert): void { try { $call(); } catch (RuntimeException $error) { $assert(true, $message); return; } $assert(false, $message); };
$remove = static function (string $path) use (&$remove): void { if (is_link($path) || !is_dir($path)) { @unlink($path); return; } foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name); rmdir($path); };
try {
    $config = new Config($root . '/config/app.php');
    $values = ['storage_mode' => $mysql ? 'mysql' : 'file', 'file_store_path' => 'storage/data.json', 'installed' => true,
        'installation_id' => 'privacy-store-' . bin2hex(random_bytes(12)), 'base_url' => 'http://127.0.0.1/fixture',
        'mail' => ['mode' => 'disabled'], 'security' => ['app_key' => bin2hex(random_bytes(32))]];
    if ($mysql) { [$db, $pdo] = vvx_installer_fixture_database(); vvx_installer_fixture_empty($pdo); $owns = true; $values['db'] = $db; }
    $config->write($values);
    if ($mysql) (new App\Core\MigrationRunner($pdo, $source . '/database/migrations'))->migrate(false, true);
    else file_put_contents($root . '/storage/data.json', '{}');
    $store = new Store($config);
    $member = $store->createMember(['displayName' => 'Privacy member', 'email' => 'privacy-member@example.test', 'password' => 'Privacy-Member-781!', 'status' => 'active', 'emailVerifiedAt' => gmdate('c', time() - 10)]);
    $other = $store->createMember(['displayName' => 'Other member', 'email' => 'privacy-other@example.test', 'password' => 'Other-Privacy-781!', 'status' => 'active', 'emailVerifiedAt' => gmdate('c', time() - 10)]);
    $store->createMemberSession($member['id'], 'member-device-token');
    $store->createMemberToken($member['id'], 'password_reset', 3600);
    $store->saveMemberMediaState($member['id'], 'video', '1', ['viewed' => true, 'isFavorite' => true]);
    $order = $store->createOrder(['memberId' => $member['id'], 'orderNumber' => 'PRIVACY-1007-0', 'status' => 'paid', 'subtotal' => 100.17, 'discountTotal' => 3.05, 'taxTotal' => 2.11, 'grandTotal' => 99.23, 'refundedTotal' => 4.08, 'providerKey' => 'sandbox', 'providerCheckoutId' => 'retain-provider-ref', 'metadata' => ['shippingName' => 'PRIVATE-CONTACT']], [['itemType' => 'video', 'itemId' => '1', 'title' => 'PRIVATE-DESCRIPTION', 'quantity' => 2, 'unitPrice' => 50.085, 'lineTotal' => 100.17, 'metadata' => ['address' => 'PRIVATE-ADDRESS']]]);
    if ($mysql) {
        $pdo->beginTransaction();
        $insert = $pdo->prepare("INSERT INTO orders(member_id,order_number,status,subtotal,discount_total,tax_total,grand_total,refunded_total,currency,billing_email,created_at,updated_at) SELECT member_id,?,'paid',subtotal,discount_total,tax_total,grand_total,refunded_total,currency,billing_email,created_at,updated_at FROM orders WHERE id=?");
        for ($i=1; $i<1007; ++$i) $insert->execute(['PRIVACY-1007-' . $i, $order['id']]);
        $pdo->commit();
    } else $store->mutateFileStore(static function (array &$data) use ($order): void {
        for ($i=1; $i<1007; ++$i) { $copy=$order; $copy['id']='privacy-order-'.$i; $copy['orderNumber']='PRIVACY-1007-'.$i; unset($copy['items']); $data['orders'][$copy['id']]=$copy; }
    });
    $transaction = $store->savePaymentTransaction(['orderId' => $order['id'], 'providerKey' => 'sandbox', 'providerTransactionId' => 'retain-transaction-ref', 'status' => 'paid', 'amount' => 99.23, 'payload' => ['private' => 'PRIVATE-PAYLOAD'], 'brand' => 'privatebrand', 'last4' => '8888']);
    $store->saveWebhookEvent('sandbox', 'privacy-webhook', 'order.paid', ['order_number' => $order['orderNumber'], 'contact' => 'PRIVATE-CONTACT']);
    $otherOrder = $store->createOrder(['memberId' => $other['id'], 'status' => 'paid', 'grandTotal' => 17], []);
    $thread = $store->createCommunicationThread(['memberId' => $member['id'], 'subject' => 'Private conversation']);
    $public = $store->sendCommunicationMessage(['threadId' => $thread['id'], 'body' => 'My visible message', 'attachments' => [['filePath' => 'private:communications/public.txt', 'originalName' => 'visible.txt', 'mimeType' => 'text/plain', 'fileSize' => 3]]]);
    $internal = $store->sendCommunicationMessage(['threadId' => $thread['id'], 'senderType' => 'system', 'visibility' => 'internal', 'body' => 'PRIVATE-STAFF', 'attachments' => [['filePath' => 'private:communications/internal.txt', 'originalName' => 'internal.txt', 'mimeType' => 'text/plain', 'fileSize' => 3]]]);
    $booking = $store->createBookingRequest(['memberId' => $member['id'], 'contactName' => 'Privacy member', 'contactEmail' => 'privacy-member@example.test', 'details' => 'My owned request']);
    $guest = $store->createBookingRequest(['contactName' => 'Guest same email', 'contactEmail' => 'privacy-member@example.test', 'details' => 'UNRELATED-GUEST']);
    $requestFile = $store->addRequestAttachment('booking_request', $booking['id'], ['filePath' => 'private:communications/request.txt', 'originalName' => 'request.txt', 'mimeType' => 'text/plain', 'fileSize' => 3], 'member');
    $staffFile = $store->addRequestAttachment('booking_request', $booking['id'], ['filePath' => 'private:communications/staff.txt', 'originalName' => 'staff.txt', 'mimeType' => 'text/plain', 'fileSize' => 3], 'admin');
    $assert($store->memberCanReadCommunicationAttachment($public['attachments'][0], $member['id']), 'Owned visible message was inaccessible.');
    $assert(!$store->memberCanReadCommunicationAttachment($internal['attachments'][0], $member['id']), 'Internal message attachment escaped.');
    $assert(!$store->memberCanReadCommunicationAttachment($staffFile, $member['id']) && !$store->memberCanReadCommunicationAttachment($requestFile, $other['id']), 'A staff/cross-member request attachment escaped.');
    if ($mysql) $assert($public['attachments'][0]['id'] === $requestFile['id'] && $store->communicationAttachment($requestFile['id']) === null && $store->communicationAttachment($requestFile['id'], 'request')['filePath'] === $requestFile['filePath'], 'Independent SQL ids were confused.');
    $data = $store->privacyCoreData($member['id']);
    $assert(count($data['orders']) === 1007 && count($data['orderItems']) === 1 && count($data['transactions']) === 1, 'Complete projection truncated 1007 orders.');
    $assert(count($data['bookingRequests']) === 1 && count($data['requestAttachments']) === 2 && count($data['webhooks']) === 1, 'Projection broadened guest ownership or omitted linked records.');
    $otherBefore = $store->privacyCoreData($other['id']);
    $financial = array_intersect_key($data['orders'][array_search($order['id'], array_column($data['orders'], 'id'), true)], array_flip(['subtotal','discountTotal','taxTotal','grandTotal','refundedTotal','currency','status','paidAt','providerCheckoutId','createdAt']));
    $reject(fn() => $store->privacyFreeze($member['id'], str_repeat('a',32), str_repeat('0',64), static function (): void {}), 'Stale inventory froze a member.');
    $request = str_repeat('a',32); $revision = Store::privacyDataRevision($store->privacyCoreData($member['id']));
    $store->privacyFreeze($member['id'], $request, $revision, static function (): void {});
    $assert($store->memberSession('member-device-token') === null && $store->memberForToken('unused','password_reset') === null, 'Freeze did not invalidate devices.');
    foreach ([fn() => $store->updateMember($member['id'], ['displayName'=>'RECREATE']), fn() => $store->createMemberSession($member['id'],'late-device'), fn() => $store->saveMemberMediaState($member['id'],'video','1',['viewed'=>true]), fn() => $store->createOrder(['memberId'=>$member['id']],[]), fn() => $store->sendCommunicationMessage(['threadId'=>$thread['id'],'body'=>'RECREATE']), fn() => $store->queueMemberMail($member['id'],'privacy-member@example.test','subject','template','RECREATE')] as $call) $reject($call, 'A frozen-identity writer recreated data.');
    $store->privacyEraseCore($member['id'],$request,static function (): void {});
    $erasedOnce = $store->privacyCoreData($member['id']);
    $store->privacyEraseCore($member['id'],$request,static function (): void {});
    $assert($erasedOnce === $store->privacyCoreData($member['id']), 'Core recovery changed an already committed erase checkpoint.');
    $store->privacyEraseCommunications($member['id'],static function (): void {});
    $after = $store->privacyCoreData($member['id']);
    $assert(count($after['orders'])===1007 && $after['sessions']===[] && $after['tokens']===[] && $after['mediaState']===[] && $after['threads']===[] && $after['bookingRequests']===[], 'Core deletion lost finances or retained direct member records.');
    $retained = $after['orders'][array_search($order['id'], array_column($after['orders'], 'id'), true)];
    $assert(array_intersect_key($retained,array_fill_keys(array_keys($financial),true))===$financial, 'Exact financial facts changed during erasure.');
    $assert($store->bookingRequest($guest['id'])['details']==='UNRELATED-GUEST' && $store->privacyCoreData($other['id'])===$otherBefore, 'Erasure changed another member or an unlinked guest.');
    $store->updateOrder($order['id'], ['status'=>'partially_refunded','refundedTotal'=>9.17,'billingEmail'=>'RECREATE@example.test','metadata'=>['private'=>'RECREATE']]);
    $store->savePaymentTransaction(array_replace($transaction, ['payload'=>['private'=>'RECREATE'], 'last4'=>'1111']));
    $store->saveWebhookEvent('sandbox','late-privacy-webhook','order.refunded',['order_number'=>$order['orderNumber'],'contact'=>'RECREATE']);
    $store->recordMemberEvent($member['id'],'late.event',['secret'=>'RECREATE']);
    $after = $store->privacyCoreData($member['id']);
    $assert(!str_contains(json_encode($after),'RECREATE') && $store->webhookEvent('sandbox','late-privacy-webhook')['payload']===[] && $after['events']===[], 'A late financial/activity callback recreated personal data.');
    $assert($store->order($order['id'])['refundedTotal']===9.17 && $store->order($order['id'])['status']==='partially_refunded', 'A legitimate later refund was blocked.');
    echo json_encode(['ok'=>true,'assertions'=>$checks,'mode'=>$mysql?'mysql':'file','mysql_version'=>$pdo?->query('SELECT VERSION()')->fetchColumn(),'orders_exported'=>1007,'financial_facts_preserved'=>true,'delayed_writers_blocked'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} finally { if ($owns && $pdo) vvx_installer_fixture_reset_database($pdo, true); $remove($root); }
