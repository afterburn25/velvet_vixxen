<?php
declare(strict_types=1);

use App\Core\CommerceService;
use App\Core\Config;
use App\Core\MemberPrivacyProjection;
use App\Core\PurchasedTerms;
use App\Core\Store;

$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/vvx-payment-foundation-terms-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true); mkdir($root . '/storage', 0700, true);
file_put_contents($root . '/.payment-foundation-fixture', 'Owned disposable purchased-terms acceptance.');
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void { if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php'; });
require $source . '/includes/version.php'; require $source . '/includes/installation_recovery.php'; require $source . '/app/Support/functions.php';
require $source . '/tests/Installer/database_fixture.php'; require $source . '/tests/Support/PaymentFoundationFixture.php';
$mysql = in_array('--mysql', $argv, true); $pdo = null; $owns = false; $checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call, string $message) use ($assert): void { try { $call(); } catch (RuntimeException) { $assert(true, $message); return; } $assert(false, $message); };
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool { if (!(error_reporting() & $severity)) return false; throw new ErrorException($message, 0, $severity, $file, $line); });
try {
    $config = new Config($root . '/config/app.php'); $secret = bin2hex(random_bytes(32));
    $values = ['storage_mode'=>$mysql?'mysql':'file', 'file_store_path'=>'storage/data.json', 'installed'=>true,
        'installation_id'=>'terms-fixture-' . bin2hex(random_bytes(12)), 'base_url'=>'http://127.0.0.1/terms-fixture', 'currency'=>'USD',
        'mail'=>['mode'=>'disabled'], 'security'=>['app_key'=>bin2hex(random_bytes(32)), 'signing_key'=>bin2hex(random_bytes(32))]];
    if ($mysql) { [$db, $pdo] = vvx_installer_fixture_database(); vvx_installer_fixture_empty($pdo); $owns = true; $values['db'] = $db; }
    $config->write($values);
    file_put_contents($root . '/storage/install.lock', json_encode(['installed'=>true,'installation_id'=>$values['installation_id']], JSON_THROW_ON_ERROR));
    if ($mysql) (new App\Core\MigrationRunner($pdo, $source . '/database/migrations'))->migrate(false, true);
    else file_put_contents($root . '/storage/data.json', '{}');
    [$store, $commerce, $provider, $registry] = payment_fixture_service($config);
    $store->savePaymentProvider(['key'=>'sandbox','enabled'=>true,'supportsRecurring'=>true,'settings'=>['webhookSecret'=>$secret]]);
    $store->savePaymentProvider(['key'=>'fixture-pay','enabled'=>true,'settings'=>['webhookSecret'=>$secret]]);
    $member = $store->createMember(['displayName'=>'Terms fixture buyer','email'=>'terms-buyer@example.test','password'=>'Terms-Fixture-Pass-123!','status'=>'active','emailVerifiedAt'=>now_iso()]);
    $other = $store->createMember(['displayName'=>'Other terms buyer','email'=>'terms-other@example.test','password'=>'Terms-Fixture-Pass-123!','status'=>'active','emailVerifiedAt'=>now_iso()]);
    $videoA = $store->saveVideo(['title'=>'Purchased A','status'=>'published','accessMode'=>'purchase','standardPrice'=>10,'currency'=>'USD']);
    $videoB = $store->saveVideo(['title'=>'Replacement B','status'=>'published','accessMode'=>'purchase','standardPrice'=>10,'currency'=>'USD']);
    $plan = $store->saveMembershipPlan(['name'=>'Purchased membership','key'=>'purchased-membership','price'=>12.34,'currency'=>'USD','billingInterval'=>'month','intervalCount'=>2,'trialDays'=>3,'ppvDiscountPercent'=>20,'benefits'=>['Purchased benefit'],'isActive'=>true]);
    $store->setSetting('commerce',['tax_rate_percent'=>5]);
    $store->saveCoupon(['code'=>'TERMSFIRST','discountType'=>'fixed','value'=>2,'scope'=>'subscription','isActive'=>true]);
    $cart = [['type'=>'plan','id'=>$plan['id'],'metadata'=>['purchaseTerms'=>['hash'=>'client-forgery']]]];
    $quote = $commerce->quote($cart, $member, 'TERMSFIRST');
    $original = $quote['items'][0]['metadata']['purchaseTerms'];
    $assert(PurchasedTerms::validate($original) === $original && $original['terms']['recurringMinor'] === 1234, 'Server capture trusted client-supplied terms.');
    $assert($original['terms']['initialTotalMinor'] === 1086 && $original['terms']['discountMinor'] === 200 && $original['terms']['recurringMinor'] === 1234, 'Initial tax/coupon treatment was confused with the recurring base.');
    $bad = $original; $bad['terms']['intervalCount'] = '2'; $bad['hash'] = PurchasedTerms::hash($bad['terms']);
    $reject(fn() => PurchasedTerms::validate($bad), 'Numeric-string interval was accepted.');
    foreach ([['schema',2],['ppvDiscountBasisPoints',10001],['trialDays',-1],['discountPolicy','forever']] as [$field,$value]) {
        $bad = $original; $bad['terms'][$field] = $value; $bad['hash'] = PurchasedTerms::hash($bad['terms']);
        $reject(fn() => PurchasedTerms::validate($bad), 'Unsupported purchased field was accepted: ' . $field);
    }
    $bad = $original; $bad['terms']['providerSecret'] = 'PRIVATE-UNSAFE'; $bad['hash'] = PurchasedTerms::hash($bad['terms']);
    $reject(fn() => PurchasedTerms::validate($bad), 'Unknown commercial fields were accepted.');
    $bad = $original; $bad['hash'] = str_repeat('0',64);
    $reject(fn() => PurchasedTerms::validate($bad), 'Corrupt canonical hash was accepted.');

    $pending = $commerce->checkout($member['id'], $cart, 'TERMSFIRST', 'sandbox', ['sandboxResult'=>'processing'], 'terms:pending-plan', PurchasedTerms::quoteRevision($quote));
    $store->saveMembershipPlan(array_merge($plan, ['name'=>'Edited membership','price'=>98.76,'currency'=>'EUR','billingInterval'=>'year','intervalCount'=>1,'trialDays'=>90,'ppvDiscountPercent'=>85,'benefits'=>['Edited benefit']]));
    $reject(fn() => $commerce->checkout($other['id'], $cart, 'TERMSFIRST', 'sandbox', [], 'terms:stale-review', PurchasedTerms::quoteRevision($quote)), 'Stale displayed commercial terms reached checkout.');
    $assert($store->order('terms:stale-review') === null, 'A stale review created an order.');
    $sendSandbox = static function (array $order, string $eventId, string $type='order.paid') use ($commerce, $secret): array {
        $body = json_encode(['event_id'=>$eventId,'type'=>$type,'order_number'=>$order['orderNumber'],'transaction_id'=>'terms-' . $eventId], JSON_THROW_ON_ERROR);
        return $commerce->handleWebhook('sandbox', $body, hash_hmac('sha256',$body,$secret));
    };
    $sendSandbox($pending['order'], 'plan-confirmed');
    $sub = $store->subscriptions($member['id'])[0];
    $assert($sub['purchasedTermsState'] === 'accepted' && $sub['purchasedTerms'] === $original && $sub['planName'] === 'Purchased membership', 'Delayed fulfillment substituted edited membership terms.');
    $assert($sub['benefits'] === ['Purchased benefit'] && $sub['ppvDiscountPercent'] == 20, 'Purchased commercial benefits drifted.');
    $assert(abs(strtotime($sub['currentPeriodEnd']) - time() - 3*86400) < 10, 'Edited trial rules changed the purchased period.');
    $sendSandbox($pending['order'], 'plan-confirmed');
    $assert(count($store->subscriptions($member['id'])) === 1 && count($store->paymentTransactionsForOrder($pending['order']['id'])) === 1, 'Duplicate confirmation repeated membership or money effects.');
    $ppv = $commerce->quote([['type'=>'video','id'=>$videoA['id']]], $store->member($member['id']));
    $assert($ppv['subtotal'] === 8.0, 'PPV discount reread the edited plan.');
    $item = $store->orderItems($pending['order']['id'])[0];
    $reject(fn() => $store->updateOrderItem($pending['order']['id'],$item['id'],['metadata'=>['purchaseTerms'=>null]]), 'Snapshot removal was accepted.');
    $reject(fn() => $store->updateOrderItem($pending['order']['id'],$item['id'],['metadata'=>['subscriptionId'=>'other-subscription']]), 'Subscription source replacement was accepted.');
    $reject(fn() => $store->updateOrder($pending['order']['id'],['metadata'=>['purchaseTermsVersion'=>null]]), 'Required terms marker could be removed.');
    $reject(fn() => $store->saveSubscription(array_merge($sub,['memberId'=>$other['id']])), 'Purchased subscription could be reassigned.');
    $store->updateOrderItem($pending['order']['id'],$item['id'],['metadata'=>['fulfillmentNote'=>'safe additive fact']]);
    $assert($store->orderItems($pending['order']['id'])[0]['metadata']['purchaseTerms'] === $original, 'Ordinary fulfillment metadata lost terms.');
    $commerce->cancelSubscription($member['id'],$sub['id']); $commerce->resumeSubscription($member['id'],$sub['id']);
    $sub = $store->subscriptions($member['id'])[0];
    $store->saveSubscription(array_merge($sub,['currentPeriodEnd'=>gmdate('c',time()-10)]));
    $early=$commerce->processDueSubscriptions();
    $assert($early['renewed']===0, 'Changing an access date authorized an early authoritative charge.');
    // Move the owned fixture's authoritative clock facts together to represent
    // actual elapsed time. Runtime Store mutations cannot rewrite a paid period.
    $agreement=$store->recurringAgreementForSubscription((string)$sub['id']);
    $agreement['periodEnd']=time()-10;$agreement['periodStart']=$agreement['periodEnd']-3*86400;
    if ($mysql) $pdo->prepare('UPDATE recurring_agreements SET record_json=? WHERE id=?')->execute([json_encode($agreement,JSON_THROW_ON_ERROR),$agreement['id']]);
    else $store->mutateFileStore(static function(array &$all)use($agreement):void{$all['recurringAgreements'][$agreement['id']]=$agreement;});
    $store->saveSubscription(array_merge($store->subscriptions($member['id'])[0],['currentPeriodStart'=>gmdate('c',$agreement['periodStart'])]));
    $stats = $commerce->processDueSubscriptions();
    $renewals = array_values(array_filter($store->orders($member['id']),static fn(array $row): bool => !empty($row['metadata']['renewal'])));
    $assert($stats['renewed'] === 1 && count($renewals) === 1 && (float)$renewals[0]['grandTotal'] === 12.34 && $renewals[0]['currency'] === 'USD', 'Sandbox renewal substituted edited price or currency.');
    $renewed = $store->subscriptions($member['id'])[0];
    $assert(strtotime($renewed['currentPeriodEnd']) > time()+55*86400 && strtotime($renewed['currentPeriodEnd']) < time()+65*86400, 'Sandbox renewal substituted edited interval.');

    $bundle = $store->saveBundle(['title'=>'Purchased bundle','price'=>9,'currency'=>'USD','items'=>[['itemType'=>'video','itemId'=>$videoA['id']],['itemType'=>'video','itemId'=>$videoA['id']]]]);
    $provider->chargeMode = 'timeout'; $before = count($provider->ledger()['attempts']);
    $purchase = $commerce->checkout($other['id'],[['type'=>'bundle','id'=>$bundle['id']]],'','fixture-pay',[],'terms:bundle-timeout');
    $assert(count($purchase['order']['items'][0]['metadata']['purchaseTerms']['terms']['items']) === 1, 'Purchased bundle was not deduplicated in order.');
    $store->saveBundle(array_merge($bundle,['title'=>'Changed bundle','price'=>99,'items'=>[['itemType'=>'video','itemId'=>$videoB['id']]]]));
    if ($mysql) $pdo->prepare('DELETE FROM commerce_bundles WHERE id=?')->execute([$bundle['id']]);
    else $store->mutateFileStore(static function(array &$all) use($bundle): void { unset($all['bundles'][$bundle['id']]); });
    $result = $commerce->reconcilePaymentOperation($purchase['paymentOperation']['id']);
    $assert($result['operation']['state'] === 'applied' && count($provider->ledger()['attempts']) === $before+1, 'Deleted bundle recovery repeated transport or failed.');
    $grants = array_values(array_filter($store->entitlements($other['id']),static fn(array $row): bool => $row['sourceId'] === $purchase['order']['id']));
    $assert(count($grants) === 1 && $grants[0]['contentId'] === $videoA['id'], 'Recovery substituted edited bundle contents.');
    $commerce->reconcilePaymentOperation($purchase['paymentOperation']['id']);
    $assert(count($store->paymentTransactionsForOrder($purchase['order']['id'])) === 1 && count($provider->ledger()['attempts']) === $before+1, 'Repeated recovery duplicated settlement.');
    $commerce->refundOrder($purchase['order']['id']);
    $assert($store->activeEntitlement($other['id'],'video',$videoA['id']) === null, 'Purchased snapshot bypassed refund revocation.');
    $inactive = $store->saveBundle(['title'=>'Unavailable bundle','price'=>2,'isActive'=>false,'items'=>[['itemType'=>'video','itemId'=>$videoB['id']]]]);
    $reject(fn() => $commerce->quote([['type'=>'bundle','id'=>$inactive['id']]],$member), 'Inactive bundle accepted new purchases.');

    $corruptBundle = $store->saveBundle(['title'=>'Corrupt terms control','price'=>4,'currency'=>'USD','items'=>[['itemType'=>'video','itemId'=>$videoB['id']]]]);
    $corrupt = $commerce->checkout($other['id'],[['type'=>'bundle','id'=>$corruptBundle['id']]],'','fixture-pay',[],'terms:corrupt');
    $corruptItem = $corrupt['order']['items'][0]; $savedMetadata = $corruptItem['metadata'];
    $writeMetadata = static function(array $metadata) use($store,$pdo,$mysql,$corrupt,$corruptItem): void {
        if ($mysql) $pdo->prepare('UPDATE order_items SET metadata_json=? WHERE id=?')->execute([json_encode($metadata,JSON_THROW_ON_ERROR),$corruptItem['id']]);
        else $store->mutateFileStore(static function(array &$all) use($metadata,$corrupt,$corruptItem):void {
            foreach($all['orderItems'][$corrupt['order']['id']] as &$item) if($item['id']===$corruptItem['id']) $item['metadata']=$metadata;
            unset($item);
        });
    };
    $brokenMetadata = $savedMetadata; $brokenMetadata['purchaseTerms']['hash']=str_repeat('0',64); $writeMetadata($brokenMetadata);
    $attemptCount = count($provider->ledger()['attempts']);
    $held = $commerce->reconcilePaymentOperation($corrupt['paymentOperation']['id']);
    $assert($held['operation']['state']==='confirmed' && $held['operation']['data']['issue']==='local_effect_failed'
        && $store->order($corrupt['order']['id'])['status']==='processing' && $store->paymentTransactionsForOrder($corrupt['order']['id'])===[], 'Corrupt new terms caused a partial money/access commit or catalogue fallback.');
    $heldAgain = $commerce->reconcilePaymentOperation($corrupt['paymentOperation']['id']);
    $assert(count($provider->ledger()['attempts'])===$attemptCount && $heldAgain['operation']['state']==='confirmed','Corrupt new terms caused a second payment attempt.');
    $writeMetadata($savedMetadata);$restored=$commerce->reconcilePaymentOperation($corrupt['paymentOperation']['id']);
    $assert($restored['operation']['state']==='applied' && count($store->paymentTransactionsForOrder($corrupt['order']['id']))===1,'Restoring the original durable terms did not permit single local recovery.');

    // A pre-feature pending order has neither the required marker nor a complete snapshot.
    $old = $commerce->checkout($other['id'],[['type'=>'bundle','id'=>$corruptBundle['id']]],'','fixture-pay',[],'terms:legacy-pending');
    if($mysql) {
        $legacyMetadata=$old['order']['metadata'];unset($legacyMetadata['purchaseTermsVersion']);
        $pdo->prepare('UPDATE orders SET metadata_json=? WHERE id=?')->execute([json_encode($legacyMetadata,JSON_THROW_ON_ERROR),$old['order']['id']]);
        $pdo->prepare('UPDATE order_items SET metadata_json=? WHERE id=?')->execute(['{}',$old['order']['items'][0]['id']]);
    } else $store->mutateFileStore(static function(array &$all)use($old):void {
        unset($all['orders'][$old['order']['id']]['metadata']['purchaseTermsVersion']);
        $all['orderItems'][$old['order']['id']][0]['metadata']=[];
    });
    $legacyPending=$commerce->reconcilePaymentOperation($old['paymentOperation']['id']);
    $assert($legacyPending['operation']['state']==='confirmed' && $store->paymentTransactionsForOrder($old['order']['id'])===[], 'An incomplete legacy purchase manufactured accepted bundle contents.');

    $legacy = $store->saveSubscription(['memberId'=>$other['id'],'planId'=>$plan['id'],'status'=>'active','providerKey'=>'sandbox']);
    $beforeRead = Store::privacyDataRevision($store->privacyCoreData($other['id']));
    $legacyRows = $store->subscriptions($other['id']);
    $assert($legacyRows[0]['purchasedTermsState'] === 'legacy' && !isset($legacyRows[0]['purchasedTerms']), 'Historical subscription manufactured accepted terms.');
    $assert(Store::privacyDataRevision($store->privacyCoreData($other['id'])) === $beforeRead, 'Reading subscription history backfilled storage.');
    $archive = MemberPrivacyProjection::core($store->privacyCoreData($member['id']));
    $exported = array_values(array_filter($archive['orderItems'],static fn(array $row):bool=>isset($row['purchasedTerms'])));
    $assert(count($exported) === 1 && $exported[0]['purchasedTerms'] === $original, 'Own export omitted accepted commercial facts.');
    $assert(!str_contains(json_encode($exported),'providerSecret') && !str_contains(json_encode($exported),'fulfillmentNote'), 'Own terms export widened arbitrary metadata.');
    $request = md5('owned-purchased-terms-privacy');
    $revision = Store::privacyDataRevision($store->privacyCoreData($member['id']));
    $store->privacyFreeze($member['id'],$request,$revision,static function():void{});
    $store->privacyEraseCore($member['id'],$request,static function():void{});
    $closed = $store->privacyCoreData($member['id']);
    $assert($store->privacyMemberErased($member['id']) && $store->activeSubscriptions($member['id'])===[], 'Purchased snapshots bypassed member closure.');
    foreach($closed['orderItems'] as $closedItem) $assert($closedItem['metadata']===[], 'Immutability guard blocked authorized commercial metadata erasure.');
    $beforeLate = Store::privacyDataRevision($closed);
    $commerce->fulfillOrder($store->order($pending['order']['id']));
    $assert(Store::privacyDataRevision($store->privacyCoreData($member['id']))===$beforeLate, 'Late fulfillment reconstructed erased accepted terms or access.');
    if ($mysql) $assert((new App\Core\MigrationRunner($pdo,$source.'/database/migrations'))->migrate(false,true) === [], 'Snapshot increment changed historical migrations or resume.');
    $report = ['ok'=>true,'assertions'=>$checks,'mode'=>$mysql?'mysql':'file','php'=>PHP_VERSION,'database'=>$pdo?->query('SELECT VERSION()')->fetchColumn(),
        'storage_design'=>'existing immutable order item plus protected subscription reverse reference','live_provider_calls'=>0];
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL . $error->getTraceAsString() . PHP_EOL);
    throw $error;
} finally {
    if ($mysql && isset($store)) {
        foreach ([$store, app_store()] as $connectionStore) {
            $connection = (new ReflectionMethod(Store::class, 'pdo'))->invoke($connectionStore);
            if ($connection->inTransaction()) $connection->rollBack();
        }
    }
    if ($owns && $pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); vvx_installer_fixture_reset_database($pdo,true); }
    vvx_installer_fixture_remove($root);
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
