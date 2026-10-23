<?php
declare(strict_types=1);

// Private CLI-only recurring UI fixture. Never copied into customer packages.
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
$recordsPath=$root.'/storage/temp/recurring-ui-records.json';
$records=is_file($recordsPath)?json_decode(file_get_contents($recordsPath),true,32,JSON_THROW_ON_ERROR):null;
if($action==='read'){
    if(!$records)throw new RuntimeException('Seed the owned recurring fixture first.');
    if($owned['mode']==='file'){
        $all=json_decode(file_get_contents($root.'/storage/data.json'),true,512,JSON_THROW_ON_ERROR);
        $agreements=array_values(array_filter($all['recurringAgreements']??[],static fn(array $r):bool=>$r['memberId']===$records['member']));
        $ids=array_fill_keys(array_column($agreements,'id'),true);
        $journal=array_values(array_filter($all['recurringJournal']??[],static fn(array $r):bool=>isset($ids[$r['agreementId']])));
        $financial=array_intersect_key($all,array_flip(['recurringAgreements','recurringJournal','orders','orderItems','paymentTransactions','subscriptions']));
    }else{
        $q=$pdo->prepare('SELECT record_json FROM recurring_agreements WHERE member_id=? ORDER BY id');$q->execute([$records['member']]);
        $agreements=array_map(static fn(string $s):array=>json_decode($s,true,32,JSON_THROW_ON_ERROR),$q->fetchAll(PDO::FETCH_COLUMN));
        $q=$pdo->prepare('SELECT j.record_json FROM recurring_journal j JOIN recurring_agreements a ON a.id=j.agreement_id WHERE a.member_id=? ORDER BY j.id');$q->execute([$records['member']]);
        $journal=array_map(static fn(string $s):array=>json_decode($s,true,32,JSON_THROW_ON_ERROR),$q->fetchAll(PDO::FETCH_COLUMN));
        $financial=[];foreach(['recurring_agreements','recurring_journal','orders','order_items','payment_transactions','subscriptions']as$table){$rows=array_map(static fn(array $r):string=>json_encode($r,JSON_THROW_ON_ERROR),$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));sort($rows);$financial[$table]=$rows;}
    }
    echo json_encode(['agreements'=>$agreements,'journal'=>$journal,'records'=>$records,'financial_sha256'=>hash('sha256',json_encode($financial,JSON_THROW_ON_ERROR)),
        'database'=>isset($pdo)?$pdo->query('SELECT VERSION()')->fetchColumn():null],JSON_THROW_ON_ERROR);exit;
}
define('ROOT_PATH',$root);require_once $root.'/includes/version.php';require_once $root.'/includes/autoload.php';require_once $root.'/app/Support/functions.php';
$store=app_store();
if($action==='seed'){
    if($records!==null)throw new RuntimeException('Recurring fixture already seeded.');
    $member=$store->createMember(['displayName'=>'Recurring UI fixture','email'=>'recurring-ui@example.test','password'=>'Recurring-UI-123!','status'=>'active','emailVerifiedAt'=>gmdate('c',time()-10)]);
    $plan=$store->saveMembershipPlan(['name'=>'Original recurring UI membership','key'=>'recurring-ui','price'=>19.25,'currency'=>'USD','billingInterval'=>'month','intervalCount'=>1,'trialDays'=>0,'benefits'=>['Original UI benefit'],'isActive'=>true]);
    $store->savePaymentProvider(['key'=>'sandbox','enabled'=>true,'supportsRecurring'=>true]);$records=['member'=>(string)$member['id'],'plan'=>$plan,'pending'=>null];
}elseif(in_array($action,['pending','confirm'],true)){
    if(!$records)throw new RuntimeException('Owned recurring records missing.');
    $page=$store->recurringAgreementPage(null,10,$records['member']);if(count($page['records'])!==1)throw new RuntimeException('One real checkout agreement is required.');$agreement=$page['records'][0];
    $store->withCommerceLock(function()use($store,$agreement,$action,&$records):void{
        $store->paymentAtomic(function()use($store,$agreement,$action,&$records):void{
            if($action==='pending'){
                $requestId='ui:interrupted-cancel';$hash=hash('sha256',$agreement['id'].':'.$requestId);$id='rcmd_'.$hash;
                $binding=App\Core\RecurringContract::binding(['schema'=>1,'recordId'=>$id,'agreementId'=>$agreement['id'],'scope'=>$agreement['scope'],'remoteId'=>$agreement['remoteId'],'termsHash'=>$agreement['termsHash'],
                    'action'=>'cancel','requestId'=>$requestId,'agreementRevision'=>$agreement['revision'],'providerRevision'=>$agreement['providerRevision'],'periodStart'=>$agreement['periodStart'],'periodEnd'=>$agreement['periodEnd'],'cancelAtPeriodEnd'=>true,'payment'=>null]);
                $row=$store->saveRecurringJournal(['schema'=>1,'id'=>$id,'agreementId'=>$agreement['id'],'kind'=>'command','dedupHash'=>$hash,'orderId'=>null,'state'=>'prepared','binding'=>$binding,'result'=>null,'issue'=>null,'createdAt'=>time(),'updatedAt'=>time()]);
                $row['state']='attempting';$row=$store->saveRecurringJournal($row);$row['state']='unknown';$row['issue']='transport_unknown';$store->saveRecurringJournal($row);$records['pending']=$id;
            }else{
                $row=$store->recurringJournal($records['pending']);if(!in_array($row['state'],['unknown','held'],true))throw new RuntimeException('Original unresolved recurring fixture request required.');
                // Normalized synthetic evidence for that same request, never a
                // new transport attempt or an arbitrary manual paid override.
                $row['result']=['schema'=>1,'binding'=>$row['binding'],'outcome'=>'succeeded','remoteId'=>$agreement['remoteId'],'providerRevision'=>$row['binding']['providerRevision']+1,'transactionId'=>null,'observedAt'=>time(),'cancelAtPeriodEnd'=>true];
                $row['state']='confirmed';$row['issue']=null;$store->saveRecurringJournal($row);
            }
        },$agreement['memberId']);
    });
}else throw new RuntimeException('Unknown recurring UI fixture action.');
file_put_contents($recordsPath,json_encode($records,JSON_THROW_ON_ERROR));echo json_encode($records,JSON_THROW_ON_ERROR);
