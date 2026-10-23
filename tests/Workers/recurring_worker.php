<?php
declare(strict_types=1);
$source=dirname(__DIR__,2);$root=$argv[1]??'';$agreementId=$argv[2]??'';$stage=$argv[3]??'';
if(!is_file($root.'/.payment-foundation-fixture') || !in_array($stage,['before_dispatch','after_acceptance','after_evidence','inside_commit','concurrent','guarded_reconcile','event'],true))exit(90);
define('ROOT_PATH',$root);
spl_autoload_register(static function(string $class)use($source):void{if(str_starts_with($class,'App\\'))require_once $source.'/app/'.str_replace('\\','/',substr($class,4)).'.php';});
require $source.'/includes/version.php';require $source.'/includes/installation_recovery.php';require $source.'/app/Support/functions.php';require $source.'/tests/Support/RecurringFixture.php';
$config=new App\Core\Config($root.'/config/app.php');[$store,$commerce,$provider]=recurring_fixture_service($config);
$agreement=$store->recurringAgreement($agreementId);if(!$agreement)exit(91);
if($stage==='event'){
    $event=json_decode(file_get_contents($root.'/concurrent-recurring-event.json'),true,32,JSON_THROW_ON_ERROR);
    $result=$commerce->handleWebhook('fixture-recurring',$event['body'],$event['signature']);
    if(!in_array($result['state']??null,['applied'],true))exit(94);exit(0);
}
if($stage==='guarded_reconcile'){
    $record=array_values(array_filter($store->recurringJournalForAgreement($agreementId),static fn(array $r):bool=>$r['state']==='unknown'))[0];
    file_put_contents($root.'/recurring-guard-ready','ready');
    try{$commerce->reconcileRecurringRecord($record['id'],static fn():bool=>file_get_contents(ROOT_PATH.'/recurring-authority')==='yes');}
    catch(App\Core\PaymentRecoveryAuthorizationException){file_put_contents($root.'/recurring-guard-denied','denied');exit(0);}
    exit(93);
}
if($stage==='before_dispatch')$provider->context=static function()use($store,$agreement):void{
    foreach($store->recurringJournalForAgreement($agreement['id'])as$row)if($row['kind']==='cycle'&&$row['state']==='prepared'){posix_kill(getmypid(),SIGKILL);exit(98);}
};
elseif($stage==='after_acceptance')$provider->mode='kill';
elseif($stage==='inside_commit')$provider->context=static function()use($store,$commerce,$agreement,$root):void{
    foreach($store->recurringJournalForAgreement($agreement['id'])as$row)if($row['kind']==='cycle'&&$row['state']==='confirmed'){
        // Exercise the actual production cycle reducer inside the real financial
        // boundary, after normal money/order writes, then kill before commit.
        // No replacement Store or customer-runtime interruption hook is used.
        $store->paymentAtomic(function()use($store,$commerce,$row,$root):void{
            $order=$store->order($row['orderId']);$binding=$row['binding']['payment'];
            $store->savePaymentTransaction(['orderId'=>$order['id'],'providerKey'=>$binding['provider'],'providerTransactionId'=>$row['result']['transactionId'],'transactionType'=>'charge','status'=>'paid','amount'=>$binding['amountMinor']/100,'currency'=>$binding['currency']]);
            $order=$store->updateOrder($order['id'],['status'=>'paid']);
            (new ReflectionMethod($commerce,'applyRecurringCycleCore'))->invoke($commerce,$order,true);
            file_put_contents($root.'/inside-recurring-commit-reached','Real order/transaction, interval and cycle mutations reached before financial commit.');
            posix_kill(getmypid(),SIGKILL);exit(98);
        },$agreement['memberId']);
    }
};

elseif($stage==='after_evidence') $provider->context=static function()use($store,$agreement):void{
    foreach($store->recurringJournalForAgreement($agreement['id'])as$row)if($row['kind']==='cycle'&&$row['state']==='confirmed'){posix_kill(getmypid(),SIGKILL);exit(98);}
};
$commerce->processRecurringAgreements(recurring_fixture_predecessor($agreementId),1);
if($stage==='concurrent')exit(0);
exit(92); // The expected checkpoint must terminate a real worker, not return success.
