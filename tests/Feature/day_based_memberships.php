<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\MembershipPeriod;
use App\Core\PurchasedTerms;
use App\Core\RecurringContract;

$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/vvx-recurring-test-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true); mkdir($root . '/storage', 0700, true);
file_put_contents($root . '/.payment-foundation-fixture', 'Owned fixed-day membership acceptance.');
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require $source . '/includes/version.php'; require $source . '/includes/installation_recovery.php';
require $source . '/app/Support/functions.php'; require $source . '/tests/Installer/database_fixture.php';
require $source . '/tests/Support/RecurringFixture.php';
$checks = 0; $pdo = null; $owns = false; $mysql = in_array('--mysql', $argv, true);
$assert = static function (bool $ok, string $message) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call, string $message) use ($assert): void {
    try { $call(); } catch (RuntimeException) { $assert(true, $message); return; }
    $assert(false, $message);
};
set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $level)) return false;
    throw new ErrorException($message, 0, $level, $file, $line);
});
$timezone = date_default_timezone_get();
try {
    $config = new Config($root . '/config/app.php');
    $secret = bin2hex(random_bytes(32));
    $values = ['storage_mode'=>$mysql?'mysql':'file','file_store_path'=>'storage/data.json','installed'=>true,
        'installation_id'=>'fixed-day-'.bin2hex(random_bytes(12)), 'base_url'=>'http://127.0.0.1/day-fixture',
        'mail'=>['mode'=>'disabled'],'security'=>['app_key'=>bin2hex(random_bytes(32)),'signing_key'=>bin2hex(random_bytes(32))]];
    if ($mysql) { [$db, $pdo] = vvx_installer_fixture_database(); vvx_installer_fixture_empty($pdo); $owns = true; $values['db'] = $db; }
    $config->write($values);
    file_put_contents($root . '/storage/install.lock', json_encode(['installed'=>true,'installation_id'=>$values['installation_id']], JSON_THROW_ON_ERROR));
    if ($mysql) (new App\Core\MigrationRunner($pdo, $source . '/database/migrations'))->migrate(false, true);
    else file_put_contents($root . '/storage/data.json', '{}');
    [$store, $commerce, $provider, $registry] = recurring_fixture_service($config);
    $store->savePaymentProvider(['key'=>'fixture-recurring','enabled'=>true,'supportsRecurring'=>true,'settings'=>['webhookSecret'=>$secret]]);
    $plan = $store->saveMembershipPlan(['name'=>'Calendar membership','key'=>'fixed-day-acceptance','price'=>12.34,'currency'=>'USD',
        'billingInterval'=>'month','intervalCount'=>1,'trialDays'=>0,'benefits'=>['Original benefit'],'isActive'=>true]);
    $makeMember = static fn(string $label): array => $store->createMember(['displayName'=>'Period fixture',
        'email'=>$label.'@example.test','password'=>'Owned-Fixture-Pass-123!','status'=>'active','emailVerifiedAt'=>now_iso()]);
    $oldMember = $makeMember('calendar-buyer');
    $cart = [['type'=>'plan','id'=>$plan['id']]];
    $oldResult = $commerce->checkout($oldMember['id'], $cart, '', 'fixture-recurring', [], 'period:calendar');
    $oldSnapshot = $oldResult['order']['items'][0]['metadata']['purchaseTerms'];
    $oldSub = $store->subscriptions($oldMember['id'])[0];
    $oldAgreement = $store->recurringAgreementForSubscription((string) $oldSub['id']);
    $assert($oldResult['order']['status']==='paid' && $oldSnapshot['terms']['interval']==='month', 'Calendar purchase was not captured before editing.');

    $storedPlan = $store->membershipPlan($plan['id']);
    foreach (['30.5','3e1','030','0',0,-1,121,true,false,[],null] as $badCount) {
        $reject(fn()=>$store->saveMembershipPlan(array_merge($plan,['billingInterval'=>'day','intervalCount'=>$badCount])), 'Malformed day count was silently converted: '.get_debug_type($badCount));
        $assert($store->membershipPlan($plan['id'])===$storedPlan, 'Rejected count changed the catalogue.');
    }
    $reject(fn()=>$store->saveMembershipPlan(array_merge($plan,['billingInterval'=>'weeks'])), 'Unknown interval became a monthly plan.');
    $assert(MembershipPeriod::label('day',30)==='every 30 days' && MembershipPeriod::label('month',2)==='every 2 months'
        && MembershipPeriod::label('one_time',1)==='one time', 'Commercial period labels lose their unit or count.');
    $dayPlan = $store->saveMembershipPlan(array_merge($plan,['name'=>'Explicit 30-day offer','billingInterval'=>'day','intervalCount'=>'30','price'=>11.30]));
    $assert($dayPlan['billingInterval']==='day' && $dayPlan['intervalCount']===30, 'Stored fixed-day offer lost its unit or whole count.');
    $oldNow = $store->subscriptions($oldMember['id'])[0];
    $assert($oldNow['purchasedTerms']===$oldSnapshot && $oldNow['currentPeriodEnd']===$oldSub['currentPeriodEnd']
        && $store->recurringAgreement($oldAgreement['id'])===$oldAgreement, 'New day offer rewrote an existing monthly purchase.');

    $member = $makeMember('day-buyer');
    $quote = $commerce->quote($cart, $member);
    $snapshot = $quote['items'][0]['metadata']['purchaseTerms'];
    $assert(PurchasedTerms::validate($snapshot)===$snapshot && $snapshot['terms']['interval']==='day'
        && $snapshot['terms']['intervalCount']===30 && $snapshot['terms']['recurringMinor']===1130, 'Quoted day terms are incomplete.');
    $result = $commerce->checkout($member['id'], $cart, '', 'fixture-recurring', [], 'period:day', PurchasedTerms::quoteRevision($quote));
    $sub = $store->subscriptions($member['id'])[0];
    $agreement = $store->recurringAgreementForSubscription((string) $sub['id']);
    $assert($result['order']['status']==='paid' && $agreement['state']==='active', 'Fixed-day purchase did not reach the normal bound reducer.');
    $assert(strtotime($sub['currentPeriodEnd'])-strtotime($sub['currentPeriodStart'])===30*86400
        && $agreement['periodEnd']-$agreement['periodStart']===30*86400, 'Initial day access was interpreted as calendar months.');
    $assert($sub['purchasedTerms']===$snapshot && $agreement['terms']===$snapshot, 'Subscription or agreement lost the accepted fixed-day snapshot.');

    $initialEnd = new ReflectionMethod($commerce, 'nextPeriodEnd');
    foreach (['UTC','America/Chicago','Europe/London'] as $zone) {
        date_default_timezone_set($zone);
        foreach (['2024-01-31T12:34:56Z','2026-03-07T18:10:20Z','2026-10-31T18:10:20Z'] as $instant) {
            $start = strtotime($instant); $expected = $start+30*86400;
            $assert(strtotime($initialEnd->invoke($commerce,$instant,'day',30))===$expected, 'Initial fixed-day period shifted across a month or daylight-saving boundary.');
            $assert(RecurringContract::nextEnd($start,'day',30,(int)gmdate('j',$start))===$expected, 'Renewal fixed-day period shifted across a month or daylight-saving boundary.');
        }
    }
    date_default_timezone_set('UTC');
    $jan = strtotime('2024-01-31T12:34:56Z');
    $assert(RecurringContract::nextEnd($jan,'month',1,31)===strtotime('2024-02-29T12:34:56Z')
        && RecurringContract::nextEnd($jan,'day',30,31)===strtotime('2024-03-01T12:34:56Z'), 'Calendar-month and 30-day purchases became interchangeable.');

    $store->saveMembershipPlan(array_merge($dayPlan,['name'=>'Edited annual offer','billingInterval'=>'year','intervalCount'=>1,'price'=>99,'currency'=>'EUR']));
    $staleMember = $makeMember('stale-day-buyer');
    $reject(fn()=>$commerce->checkout($staleMember['id'],$cart,'','fixture-recurring',[],'period:stale',PurchasedTerms::quoteRevision($quote)), 'A stale day offer created a different purchase.');
    $assert($store->order('period:stale')===null, 'Stale review created an order.');
    $elapsedEnd = time()-5;
    $agreement = recurring_fixture_elapsed($store,$agreement,$elapsedEnd,$pdo,30);
    $before = count($provider->ledger()['attempts']);
    $cursor = recurring_fixture_predecessor($agreement['id']);
    $stats = $commerce->processRecurringAgreements($cursor,1);
    $renewed = $store->recurringAgreement($agreement['id']);
    $assert($stats['renewed']===1 && $renewed['periodStart']===$elapsedEnd && $renewed['periodEnd']===$elapsedEnd+30*86400, 'Renewal substituted execution time or edited calendar terms.');
    $renewals = array_values(array_filter($store->orders($member['id']),static fn(array $order):bool=>!empty($order['metadata']['renewal'])));
    $assert(count($renewals)===1 && (float)$renewals[0]['grandTotal']===11.30 && $renewals[0]['currency']==='USD', 'Renewal substituted the edited annual price or currency.');
    $cycle = $store->recurringCycleForOrder((string)$renewals[0]['id']);
    $assert($cycle['state']==='applied' && count($store->paymentTransactionsForOrder($renewals[0]['id']))===1, 'Fixed-day renewal did not use the atomic refundable payment journal.');
    $commerce->processRecurringAgreements($cursor,1); $commerce->reconcileRecurringRecord($cycle['id']);
    $assert(count($provider->ledger()['attempts'])===$before+1 && $store->recurringAgreement($agreement['id'])===$renewed, 'Replay collected or extended a fixed-day cycle twice.');
    $cancel = $commerce->requestRecurringCommand($member['id'],$sub['id'],'cancel',$renewed['revision'],'period:cancel');
    $assert($cancel['cancelAtPeriodEnd'] && $cancel['periodEnd']===$renewed['periodEnd'], 'Cancellation changed paid fixed-day access.');
    $resume = $commerce->requestRecurringCommand($member['id'],$sub['id'],'resume',$cancel['revision'],'period:resume');
    $assert(!$resume['cancelAtPeriodEnd'] && $resume['periodEnd']===$renewed['periodEnd'], 'Resume shifted paid fixed-day access.');
    $assert($store->subscriptions($oldMember['id'])[0]['purchasedTerms']===$oldSnapshot, 'Day lifecycle work changed the original monthly purchase.');
    if ($mysql) {
        $type = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='membership_plans' AND COLUMN_NAME='billing_interval'")->fetchColumn();
        $assert($type==="enum('month','year','one_time','day')", 'SQL schema does not preserve existing units while appending day.');
    }
    echo json_encode(['ok'=>true,'assertions'=>$checks,'mode'=>$mysql?'mysql':'file','php'=>PHP_VERSION,
        'database'=>$pdo?$pdo->getAttribute(PDO::ATTR_SERVER_VERSION):null,'fixed_period_seconds'=>30*86400,
        'calendar_purchases_preserved'=>true,'live_provider_calls'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    date_default_timezone_set($timezone);
    if ($pdo && $owns) { if ($pdo->inTransaction()) $pdo->rollBack(); vvx_installer_fixture_reset_database($pdo,true); }
    vvx_installer_fixture_remove($root);
}
