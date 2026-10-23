<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\RecurringContract;
use App\Core\RecurringRecoveryService;

$source=dirname(__DIR__,2);$root=sys_get_temp_dir().'/vvx-recurring-test-'.bin2hex(random_bytes(8));
mkdir($root.'/config',0700,true);mkdir($root.'/storage',0700,true);file_put_contents($root.'/.payment-foundation-fixture','Owned recurring acceptance.');
define('ROOT_PATH',$root);
spl_autoload_register(static function(string $class)use($source):void{if(str_starts_with($class,'App\\'))require_once $source.'/app/'.str_replace('\\','/',substr($class,4)).'.php';});
require $source.'/includes/version.php';require $source.'/includes/installation_recovery.php';require $source.'/app/Support/functions.php';
require $source.'/tests/Installer/database_fixture.php';require $source.'/tests/Support/RecurringFixture.php';
$checks=0;$pdo=null;$owns=false;$mysql=in_array('--mysql',$argv,true);
$assert=static function(bool $ok,string $message)use(&$checks):void{++$checks;if(!$ok)throw new RuntimeException($message);};
$reject=static function(callable $call,string $message)use($assert):void{try{$call();}catch(Throwable){$assert(true,$message);return;}$assert(false,$message);};
set_error_handler(static function(int $level,string $message,string $file,int $line):bool{if(!(error_reporting()&$level))return false;throw new ErrorException($message,0,$level,$file,$line);});
try{
    $config=new Config($root.'/config/app.php');$secret=bin2hex(random_bytes(32));
    $values=['storage_mode'=>$mysql?'mysql':'file','file_store_path'=>'storage/data.json','installed'=>true,'installation_id'=>'recurring-'.bin2hex(random_bytes(12)),
        'base_url'=>'http://127.0.0.1/recurring-fixture','mail'=>['mode'=>'disabled'],'security'=>['app_key'=>bin2hex(random_bytes(32)),'signing_key'=>bin2hex(random_bytes(32))]];
    if($mysql){[$db,$pdo]=vvx_installer_fixture_database();vvx_installer_fixture_empty($pdo);$owns=true;$values['db']=$db;}
    $config->write($values);file_put_contents($root.'/storage/install.lock',json_encode(['installed'=>true,'installation_id'=>$values['installation_id']],JSON_THROW_ON_ERROR));
    if($mysql)(new App\Core\MigrationRunner($pdo,$source.'/database/migrations'))->migrate(false,true);else file_put_contents($root.'/storage/data.json','{}');
    [$store,$commerce,$provider,$registry]=recurring_fixture_service($config);
    $store->savePaymentProvider(['key'=>'fixture-recurring','enabled'=>true,'supportsRecurring'=>true,'settings'=>['webhookSecret'=>$secret]]);
    $plan=$store->saveMembershipPlan(['name'=>'Original recurring membership','key'=>'recurring-fixture','price'=>12.34,'currency'=>'USD','billingInterval'=>'month','intervalCount'=>1,'trialDays'=>0,'benefits'=>['Original benefit'],'isActive'=>true]);
    $counter=0;
    $purchase=static function()use(&$store,&$commerce,$plan,&$counter):array{
        ++$counter;$member=$store->createMember(['displayName'=>'Recurring fixture','email'=>'recurring-'.$counter.'@example.test','password'=>'Owned-Fixture-Pass-123!','status'=>'active','emailVerifiedAt'=>now_iso()]);
        $result=$commerce->checkout($member['id'],[['type'=>'plan','id'=>$plan['id']]],'','fixture-recurring',[],'recurring:purchase:'.$counter);
        $sub=$store->subscriptions($member['id'])[0];return [$member,$result['order'],$store->recurringAgreementForSubscription((string)$sub['id'])];
    };
    [$member,$order,$agreement]=$purchase();
    $assert($agreement!==null && $agreement['state']==='active' && $agreement['remoteId']!==null && $agreement['providerRevision']===1,'Paid membership activation did not use a confirmed bound agreement.');
    $assert(count($provider->ledger()['attempts'])===1,'Activation was repeated.');
    $assert(RecurringContract::nextEnd(strtotime('2024-01-31T12:34:56Z'),'month',1,31)===strtotime('2024-02-29T12:34:56Z'),'Leap-year monthly anchor overflowed.');
    $assert(RecurringContract::nextEnd(strtotime('2024-02-29T12:34:56Z'),'month',1,31)===strtotime('2024-03-31T12:34:56Z'),'Short month lost the original anchor.');
    $assert(RecurringContract::nextEnd(strtotime('2024-02-29T12:34:56Z'),'year',4,29)===strtotime('2028-02-29T12:34:56Z'),'Leap-year annual anchor drifted.');
    $reject(fn()=>RecurringContract::integer('1'),'Numeric-string recurring revision was accepted.');
    $bad=$agreement;$bad['unsafeProviderPayload']=['secret'=>'UNSAFE'];$reject(fn()=>RecurringContract::agreement($bad),'Arbitrary agreement metadata was accepted.');
    $reject(fn()=>$store->saveRecurringAgreement($agreement),'Agreement write escaped its financial/member fence.');
    $revision=$agreement['revision'];$attempts=count($provider->ledger()['attempts']);
    $reject(fn()=>$commerce->requestRecurringCommand($member['id'],$agreement['subscriptionId'],'cancel',$revision-1,'stale:cancel'),'Stale recurring form dispatched.');
    $reject(fn()=>$commerce->requestRecurringCommand('another-member',$agreement['subscriptionId'],'cancel',$revision,'foreign:cancel'),'Another member changed recurring billing.');
    $assert(count($provider->ledger()['attempts'])===$attempts,'Rejected recurring form reached the provider.');
    $provider->mode='timeout';$pending=$commerce->requestRecurringCommand($member['id'],$agreement['subscriptionId'],'cancel',$revision,'cancel:lost-response');
    $assert(!$pending['cancelAtPeriodEnd'] && $pending['pendingRequest']['state']==='unknown','Lost cancellation response was falsely confirmed.');
    $request=$pending['pendingRequest']['id'];$attempts=count($provider->ledger()['attempts']);
    $commerce->requestRecurringCommand($member['id'],$agreement['subscriptionId'],'cancel',$revision,'cancel:lost-response');
    $assert(count($provider->ledger()['attempts'])===$attempts,'Form replay resent cancellation.');
    $reject(fn()=>$commerce->requestRecurringCommand($member['id'],$agreement['subscriptionId'],'resume',$revision,'cancel:lost-response'),'Conflicting request replay was accepted.');
    $beforeLookups=count($provider->ledger()['lookups']);
    $reject(fn()=>$commerce->reconcileRecurringRecord($request,static fn():bool=>false),'Revoked owner authority queried a provider.');
    $assert(count($provider->ledger()['lookups'])===$beforeLookups,'Rejected authority reached provider lookup.');
    $resolved=$commerce->reconcileRecurringRecord($request,static fn():bool=>true,$revision);
    $assert($resolved['cancelAtPeriodEnd'] && $resolved['pendingRequest']===null,'Read-only lookup did not apply the original accepted cancellation.');
    $assert(count($provider->ledger()['attempts'])===$attempts,'Recovery resent cancellation.');
    $sub=$store->subscriptions($member['id'])[0];$assert(strtotime($sub['currentPeriodEnd'])===$agreement['periodEnd'],'Cancellation changed paid access bounds.');
    $provider->mode='pending';$revision=$resolved['revision'];$pending=$commerce->requestRecurringCommand($member['id'],$agreement['subscriptionId'],'resume',$revision,'resume:pending');
    $assert($pending['cancelAtPeriodEnd'] && $pending['pendingRequest']['action']==='resume','Pending resume asserted renewed billing.');
    $request=$store->recurringJournal($pending['pendingRequest']['id']);$evidence=$provider->ledger()['results'][$request['id']];
    $evidence['outcome']='succeeded';$evidence['providerRevision']=$request['binding']['providerRevision']+1;$provider->seed($evidence);
    $send=static function(string $eventId,array $result)use($commerce,$secret):array{$body=json_encode(['family'=>'recurring','eventId'=>$eventId,'result'=>$result],JSON_THROW_ON_ERROR);return $commerce->handleWebhook('fixture-recurring',$body,hash_hmac('sha256',$body,$secret));};
    $assert($send('resume-confirmed',$evidence)['state']==='applied','Authenticated recurring resume did not confirm.');
    $assert($send('resume-confirmed',$evidence)['duplicate']===true,'Identical recurring event replay was not idempotent.');
    $send('resume-second-delivery',$evidence);
    $conflict=$evidence;$conflict['remoteId']='wrong-agreement';$reject(fn()=>$send('resume-confirmed',$conflict),'Conflicting recurring event identity was accepted.');
    $body=json_encode(['family'=>'recurring','eventId'=>'bad-signature','result'=>$evidence],JSON_THROW_ON_ERROR);
    $reject(fn()=>$commerce->handleWebhook('fixture-recurring',$body,str_repeat('0',64)),'Unauthenticated recurring event was accepted.');
    $agreement=$store->recurringAgreement($agreement['id']);$assert(!$agreement['cancelAtPeriodEnd'],'Confirmed resume did not preserve correct renewal state.');
    $store->saveMembershipPlan(array_merge($plan,['price'=>99,'currency'=>'EUR','billingInterval'=>'year','name'=>'Edited catalog membership']));
    $agreement=recurring_fixture_elapsed($store,$agreement,time()-70*86400,$pdo);$expectedEnd=RecurringContract::nextEnd($agreement['periodEnd'],'month',1,$agreement['anchorDay']);
    $provider->mode='success';$attempts=count($provider->ledger()['attempts']);$stats=$commerce->processRecurringAgreements();
    $assert($stats['renewed']===1,'Authoritative due cycle was not applied.');
    $renewals=array_values(array_filter($store->orders($member['id']),static fn(array $r):bool=>!empty($r['metadata']['renewal'])));
    $assert(count($renewals)===1 && (float)$renewals[0]['grandTotal']===12.34 && $renewals[0]['currency']==='USD','Recurring cycle substituted edited commercial terms.');
    $after=$store->recurringAgreement($agreement['id']);$assert($after['periodStart']===$agreement['periodEnd'] && $after['periodEnd']===$expectedEnd && $expectedEnd<time(),'Delayed cycle used execution time or fabricated grace.');
    $cycle=$store->recurringCycleForOrder((string)$renewals[0]['id']);$assert($cycle['state']==='applied','Money and cycle marker did not commit together.');
    $transactions=$store->paymentTransactionsForOrder((string)$renewals[0]['id']);$assert(count($transactions)===1 && $transactions[0]['transactionType']==='charge','Recurring charge did not use the normal refundable financial journal.');
    for($i=0;$i<3;++$i)$commerce->reconcileRecurringRecord($cycle['id']);
    $assert(count($provider->ledger()['attempts'])===$attempts+1 && $store->recurringAgreement($agreement['id'])===$after,'Cycle replay repeated a charge or moved the paid interval.');
    $subBefore=$store->subscriptions($member['id'])[0];$commerce->refundOrder($order['id'],'Owned original period refund');
    $assert($store->subscriptions($member['id'])[0]['status']===$subBefore['status'] && $store->recurringAgreement($agreement['id'])===$after,'Refund of an older paid period revoked a newer unrelated interval.');
    $safe=(new RecurringRecoveryService($store))->inspect($agreement['id']);$encoded=json_encode($safe,JSON_THROW_ON_ERROR);
    $assert(!str_contains($encoded,$after['scope']['account']) && !str_contains($encoded,$after['remoteId']) && !str_contains($encoded,'binding'),'Owner projection exposed account or raw provider binding.');
    $before=$provider->ledger();$service=new RecurringRecoveryService($store);$service->page();$service->inspect($agreement['id']);
    $assert($provider->ledger()===$before,'Ordinary recurring inspection contacted the provider.');
    $store->saveMembershipPlan($plan);
    // Real local-application hold: the provider accepts, then an independent
    // membership hold prevents both finance and access from partially committing.
    [$heldMember,$heldOrder,$heldAgreement]=$purchase();
    $heldAgreement=recurring_fixture_elapsed($store,$heldAgreement,time()-10,$pdo);
    $heldSub=$store->subscriptions($heldMember['id'])[0];
    $provider->afterAccepted=static function(array $binding)use($store,$heldSub):void {
        if($binding['action']==='cycle')$store->saveSubscription(array_replace($heldSub,['status'=>'paused']));
    };
    $heldStats=$commerce->processRecurringAgreements(recurring_fixture_predecessor($heldAgreement['id']),1);
    $heldCycle=array_values(array_filter($store->recurringJournalForAgreement($heldAgreement['id']),static fn(array $r):bool=>$r['kind']==='cycle'))[0];
    $heldPayment=$store->paymentJournal($heldCycle['binding']['payment']['operationId']);
    $assert($heldCycle['state']==='confirmed' && $heldPayment['state']==='confirmed' && $heldPayment['data']['issue']==='local_effect_failed','Local policy hold lost the confirmed provider outcome.');
    $assert($store->paymentTransactionsForOrder($heldCycle['orderId'])===[] && $store->recurringAgreement($heldAgreement['id'])===$heldAgreement && $store->subscriptions($heldMember['id'])[0]['status']==='paused','Held cycle partially committed money, period or access.');
    $provider->afterAccepted=null;$store->saveSubscription($heldSub);$attempts=count($provider->ledger()['attempts']);
    $commerce->reconcileRecurringRecord($heldCycle['id']);
    $assert($store->recurringJournal($heldCycle['id'])['state']==='applied' && count($provider->ledger()['attempts'])===$attempts,'Confirmed local recovery resent the recurring charge.');
    // A real worker dies after provider acceptance and after evidence persistence.
    $killed=0;
    foreach(['before_dispatch','after_acceptance','after_evidence','inside_commit']as$stage){
        [$workerMember,$workerOrder,$workerAgreement]=$purchase();$workerAgreement=recurring_fixture_elapsed($store,$workerAgreement,time()-10,$pdo);
        $attempts=count($provider->ledger()['attempts']);
        $process=proc_open(['php',$source.'/tests/Workers/recurring_worker.php',$root,$workerAgreement['id'],$stage],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Owned recurring worker failed to start.');fclose($pipes[0]);
        $deadline=microtime(true)+15;
        do{$status=proc_get_status($process);if(!$status['running'])break;usleep(20000);}while(microtime(true)<$deadline);
        if($status['running']){proc_terminate($process,9);throw new RuntimeException('Recurring worker missed its bounded interruption checkpoint.');}
        $diagnostic=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
        $assert($status['signaled'] && $status['termsig']===9,'Worker did not die at its real checkpoint: '.$diagnostic);++$killed;
        $cycle=array_values(array_filter($store->recurringJournalForAgreement($workerAgreement['id']),static fn(array $r):bool=>$r['kind']==='cycle'))[0];
        $assert($cycle['state']===match($stage){'before_dispatch'=>'prepared','after_acceptance'=>'attempting',default=>'confirmed'} && $store->paymentTransactionsForOrder($cycle['orderId'])===[],'Interrupted worker exposed partial financial effects.');
        $assert($store->recurringAgreement($workerAgreement['id'])===$workerAgreement && count($provider->ledger()['attempts'])===$attempts+($stage==='before_dispatch'?0:1),'Interrupted cycle changed access or repeated provider transport.');
        if($stage==='inside_commit')$assert(is_file($root.'/inside-recurring-commit-reached'),'Worker did not reach the actual partial recurring financial commit.');
        if($stage==='before_dispatch')$commerce->processRecurringAgreements(recurring_fixture_predecessor($workerAgreement['id']),1);
        else $commerce->reconcileRecurringRecord($cycle['id']);
        $assert($store->recurringJournal($cycle['id'])['state']==='applied' && count($provider->ledger()['attempts'])===$attempts+1 && count($store->paymentTransactionsForOrder($cycle['orderId']))===1,'Killed recurring recovery did not apply exactly once without another charge.');
    }
    // Demonstrable commerce-lock wait followed by authority revocation. The
    // exact original unknown command must remain untouched and unqueried.
    [$guardMember,$guardOrder,$guardAgreement]=$purchase();$provider->mode='timeout';
    $commerce->requestRecurringCommand($guardMember['id'],$guardAgreement['subscriptionId'],'cancel',$guardAgreement['revision'],'guard:wait');$provider->mode='success';
    $before=$provider->ledger();file_put_contents($root.'/recurring-authority','yes');$guardChild=null;$guardPipes=[];
    $store->withCommerceLock(function()use($root,$source,$guardAgreement,&$guardChild,&$guardPipes,$assert):void{
        $guardChild=proc_open(['php',$source.'/tests/Workers/recurring_worker.php',$root,$guardAgreement['id'],'guarded_reconcile'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$guardPipes);
        if(!is_resource($guardChild))throw new RuntimeException('Waiting recurring authority worker failed.');fclose($guardPipes[0]);$deadline=microtime(true)+5;
        while(!is_file($root.'/recurring-guard-ready')&&microtime(true)<$deadline)usleep(10000);
        $assert(is_file($root.'/recurring-guard-ready'),'Recurring worker never reached its real lock wait.');usleep(150000);
        $assert(proc_get_status($guardChild)['running'] && !is_file($root.'/recurring-guard-denied'),'Recurring worker bypassed the held commerce lock.');
        file_put_contents($root.'/recurring-authority','no');
    });
    $diagnostic=stream_get_contents($guardPipes[1]).stream_get_contents($guardPipes[2]);fclose($guardPipes[1]);fclose($guardPipes[2]);
    $assert(proc_close($guardChild)===0 && is_file($root.'/recurring-guard-denied') && $provider->ledger()===$before,'Revoked post-wait recurring authority queried or dispatched a provider: '.$diagnostic);
    $guardRecord=array_values(array_filter($store->recurringJournalForAgreement($guardAgreement['id']),static fn(array $r):bool=>$r['state']==='unknown'))[0];
    $body=json_encode(['family'=>'recurring','eventId'=>'concurrent-cancel-event','result'=>$provider->ledger()['results'][$guardRecord['id']]],JSON_THROW_ON_ERROR);
    file_put_contents($root.'/concurrent-recurring-event.json',json_encode(['body'=>$body,'signature'=>hash_hmac('sha256',$body,$secret)],JSON_THROW_ON_ERROR));$children=[];$before=$provider->ledger();
    for($i=0;$i<2;++$i){$pipes=[];$child=proc_open(['php',$source.'/tests/Workers/recurring_worker.php',$root,$guardAgreement['id'],'event'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($child))throw new RuntimeException('Concurrent event worker failed.');fclose($pipes[0]);$children[]=[$child,$pipes];}
    foreach($children as[$child,$pipes]){$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$assert(proc_close($child)===0,'Concurrent recurring event failed: '.$out);}
    $eventReceipts=array_values(array_filter($store->recurringJournalForAgreement($guardAgreement['id']),static fn(array $r):bool=>$r['kind']==='event'));
    $assert(count($eventReceipts)===1 && $store->recurringJournal($guardRecord['id'])['state']==='applied' && $store->recurringAgreement($guardAgreement['id'])['cancelAtPeriodEnd'] && $provider->ledger()===$before,'Concurrent identical recurring events duplicated a receipt, dispatched transport or lost confirmation.');
    // A new request-scoped Store after independent child commits; retain the
    // production optimistic stale-snapshot refusal on the earlier instance.
    [$store,$commerce,$provider,$registry]=recurring_fixture_service($config);
    // A prior membership hold prevents preparation/transport, not only local apply.
    [$pausedMember,$pausedOrder,$pausedAgreement]=$purchase();$pausedAgreement=recurring_fixture_elapsed($store,$pausedAgreement,time()-10,$pdo);
    $pausedSub=$store->subscriptions($pausedMember['id'])[0];$store->saveSubscription(array_replace($pausedSub,['status'=>'paused']));$attempts=count($provider->ledger()['attempts']);
    $commerce->processRecurringAgreements(recurring_fixture_predecessor($pausedAgreement['id']),1);
    $assert(count($provider->ledger()['attempts'])===$attempts && count($store->recurringJournalForAgreement($pausedAgreement['id']))===1,'A paused membership prepared or dispatched a renewal.');
    // Both processes use actual locks and the same durable provider ledger. The
    // renewed interval is in the future so the second cannot collect another one.
    [$raceMember,$raceOrder,$raceAgreement]=$purchase();$raceAgreement=recurring_fixture_elapsed($store,$raceAgreement,time()-10,$pdo);$attempts=count($provider->ledger()['attempts']);$children=[];
    for($i=0;$i<2;++$i){$pipes=[];$child=proc_open(['php',$source.'/tests/Workers/recurring_worker.php',$root,$raceAgreement['id'],'concurrent'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($child))throw new RuntimeException('Concurrent recurring worker failed.');fclose($pipes[0]);$children[]=[$child,$pipes];}
    foreach($children as[$child,$pipes]){$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$assert(proc_close($child)===0,'Concurrent recurring worker failed: '.$out);}
    $raceCycles=array_values(array_filter($store->recurringJournalForAgreement($raceAgreement['id']),static fn(array $r):bool=>$r['kind']==='cycle'));
    $assert(count($raceCycles)===1 && $raceCycles[0]['state']==='applied' && count($provider->ledger()['attempts'])===$attempts+1 && count($store->paymentTransactionsForOrder($raceCycles[0]['orderId']))===1,'Concurrent due workers duplicated the original recurring charge or local application.');
    // A definitive declined period stays bound and grants no grace/retry charge.
    [$declineMember,$declineOrder,$declineAgreement]=$purchase();$declineAgreement=recurring_fixture_elapsed($store,$declineAgreement,time()-10,$pdo);$provider->mode='decline';$attempts=count($provider->ledger()['attempts']);
    $commerce->processRecurringAgreements(recurring_fixture_predecessor($declineAgreement['id']),1);$commerce->processRecurringAgreements(recurring_fixture_predecessor($declineAgreement['id']),1);
    $declineSub=$store->subscriptions($declineMember['id'])[0];$declined=$store->recurringAgreement($declineAgreement['id']);
    $assert($declined['periodEnd']===$declineAgreement['periodEnd'] && $declineSub['status']==='past_due' && $declineSub['graceEndsAt']===null && count($provider->ledger()['attempts'])===$attempts+1,'Decline created free grace, shifted access or resent the same cycle.');$provider->mode='success';
    // Strict evidence rejects changed scope, money, currency, period and identity.
    $valid=$raceCycles[0]['result'];
    foreach(['account','amount','currency','period','terms','providerRevision','remote','request']as$negative){
        $bad=$valid;
        if($negative==='account')$bad['binding']['scope']['account']=hash('sha256','wrong-account');
        elseif($negative==='amount')++$bad['binding']['payment']['amountMinor'];
        elseif($negative==='currency')$bad['binding']['payment']['currency']='EUR';
        elseif($negative==='period')++$bad['binding']['periodEnd'];
        elseif($negative==='terms')$bad['binding']['termsHash']=str_repeat('0',64);
        elseif($negative==='providerRevision')++$bad['providerRevision'];
        elseif($negative==='remote')$bad['remoteId']='wrong-remote';
        else $bad['binding']['requestId']='wrong-request';
        $reject(fn()=>RecurringContract::result($bad,$raceCycles[0]['binding'],$raceCycles[0]['createdAt']),'Altered recurring evidence accepted: '.$negative);
    }
    // Closure retains original settlement identity but irreversibly removes terms.
    [$closedMember,$closedOrder,$closedAgreement]=$purchase();$closedAgreement=recurring_fixture_elapsed($store,$closedAgreement,time()-10,$pdo);
    $provider->mode='timeout';$commerce->processRecurringAgreements(recurring_fixture_predecessor($closedAgreement['id']),1);
    $closedCycle=array_values(array_filter($store->recurringJournalForAgreement($closedAgreement['id']),static fn(array $r):bool=>$r['kind']==='cycle'))[0];
    $privacyBefore=$store->privacyCoreData($closedMember['id']);
    $assert(count($privacyBefore['recurringAgreements'])===1 && count($privacyBefore['recurringJournal'])===2,'Privacy inventory omitted real recurring agreements or unresolved cycles.');
    $export=App\Core\MemberPrivacyProjection::core($privacyBefore);
    $assert(count($export['recurringAgreements'])===1 && !str_contains(json_encode($export),$closedAgreement['scope']['account']),'Privacy export omitted recurrence or disclosed provider bindings.');
    $closure=md5('owned-recurring-closure');$store->privacyFreeze($closedMember['id'],$closure,App\Core\Store::privacyDataRevision($privacyBefore),static function():void{});
    $store->privacyEraseCore($closedMember['id'],$closure,static function():void{});
    $closed=$store->recurringAgreement($closedAgreement['id']);
    $assert($closed['erased'] && $closed['terms']===null && $closed['state']==='closed','Account closure retained full recurring terms.');
    $attempts=count($provider->ledger()['attempts']);$commerce->reconcileRecurringRecord($closedCycle['id']);
    $settled=$store->recurringAgreement($closedAgreement['id']);
    $assert($store->recurringJournal($closedCycle['id'])['state']==='applied' && $settled['terms']===null && $settled['periodEnd']===$closedAgreement['periodEnd'] && $store->activeSubscriptions($closedMember['id'])===[],'Late settlement recreated erased access or commercial terms.');
    $assert(count($provider->ledger()['attempts'])===$attempts,'Post-closure settlement sent a new charge.');
    $provider->mode='success';$commerce->processRecurringAgreements(recurring_fixture_predecessor($closedAgreement['id']),1);
    $assert($store->recurringAgreement($closedAgreement['id'])['cancelAtPeriodEnd'] && $store->recurringAgreement($closedAgreement['id'])['state']==='closed','Necessary original cancellation was lost after closure.');
    $reject(fn()=>$commerce->requestRecurringCommand($closedMember['id'],$closedAgreement['subscriptionId'],'resume',$store->recurringAgreement($closedAgreement['id'])['revision'],'closed:resume'),'Closed account resumed billing.');
    if($mysql){
        date_default_timezone_set('America/Chicago');$facts=$store->marketingAudienceFacts([$guardMember['email']]);
        $assert(($facts[$guardMember['email']]['valid']??false) && in_array((string)$plan['id'],$facts[$guardMember['email']]['active_plan_ids'],true),'UTC subscription normalization removed an eligible SQL business audience.');
        $raw=$pdo->query('SELECT current_period_start,current_period_end,grace_ends_at FROM subscriptions WHERE id='.(int)$agreement['subscriptionId'])->fetch();
        $sub=$store->subscriptions($member['id'])[0];$store->saveSubscription($sub);
        $assert($pdo->query('SELECT current_period_start,current_period_end,grace_ends_at FROM subscriptions WHERE id='.(int)$agreement['subscriptionId'])->fetch()===$raw,'SQL subscription UTC dates shifted during an unchanged non-UTC round trip.');date_default_timezone_set('UTC');
    }
    $report=['ok'=>true,'assertions'=>$checks,'mode'=>$mysql?'mysql':'file','php'=>PHP_VERSION,'database'=>$pdo?$pdo->getAttribute(PDO::ATTR_SERVER_VERSION):null,
        'killed_workers'=>$killed,'provider_attempts'=>count($provider->ledger()['attempts']),'provider_read_only_lookups'=>count($provider->ledger()['lookups']),'live_provider_calls'=>0,'production_services'=>false];
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,(string)$e.PHP_EOL);throw $e;}
finally{if($pdo && $owns){if($pdo->inTransaction())$pdo->rollBack();vvx_installer_fixture_reset_database($pdo,true);}vvx_installer_fixture_remove($root);}
