<?php
declare(strict_types=1);

use App\Core\BackupArchive;
use App\Core\BackupDatabase;
use App\Core\BackupJobs;
use App\Core\BackupRestore;
use App\Core\BackupService;
use App\Core\Config;
use App\Core\OperationsFiles;
use App\Core\OperationsGate;
use App\Core\PrivateState;
use App\Core\Store;

if (PHP_SAPI !== 'cli') exit(1);
$source = dirname(__DIR__,2); $mysql = defined('VVX_OPERATIONS_SQL') && VVX_OPERATIONS_SQL;

if (($argv[1] ?? '') === '--worker') {
    $workerRoot = realpath((string)($argv[2] ?? '')); $task = $argv[3] ?? '';
    if ($workerRoot === false || !str_starts_with($workerRoot,sys_get_temp_dir().'/vvx-operations-') || !is_file(dirname($workerRoot).'/.owned-operations-fixture')) throw new RuntimeException('Only an owned operations fixture may run a child worker.');
    define('ROOT_PATH',$workerRoot); require $source.'/includes/version.php';
    spl_autoload_register(static function(string $class)use($source):void{if(str_starts_with($class,'App\\')){$path=$source.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($path))require_once $path;}});
    require $source.'/app/Support/functions.php';$workerConfig=new Config($workerRoot.'/config/app.php');
    if ($workerConfig->get('installation_id')!=='operations-source-fixture'||$workerConfig->get('base_url')!=='http://127.0.0.1:8879') throw new RuntimeException('Owned fixture identity changed.');
    if($task==='shared') {
        OperationsGate::bootstrap($workerRoot);echo "ready\n";fflush(STDOUT);fgets(STDIN);
        file_put_contents($workerRoot.'/uploads/drained-write.txt','The earlier request finished before capture.');exit;
    }
    $worker=new BackupService($workerConfig,$workerRoot);
    if($task==='claim') {
        $lock=OperationsGate::exclusive($workerRoot,$workerConfig);
        $key=OperationsFiles::key((string)$workerConfig->get('operations.backup_key_file'),$workerRoot);
        $job=(new ReflectionMethod($worker,'claim'))->invoke($worker,hash('sha256',$key));
        echo json_encode(['id'=>$job['id'],'status'=>$job['status']],JSON_THROW_ON_ERROR);exit;
    }
    if($task==='run'){echo json_encode($worker->run(),JSON_THROW_ON_ERROR);exit;}
    throw new RuntimeException('Unsupported owned worker task.');
}

$work = sys_get_temp_dir().'/vvx-operations-'.bin2hex(random_bytes(8)); $root = $work.'/source';
mkdir($root.'/config',0700,true); mkdir($root.'/storage/private',0700,true); mkdir($root.'/uploads',0700,true);
file_put_contents($work.'/.owned-operations-fixture','Exclusively owned synthetic backup and isolated restore acceptance.');
define('ROOT_PATH',$root); require $source.'/includes/version.php';
spl_autoload_register(static function(string $class) use($source): void { if (str_starts_with($class,'App\\')) { $path=$source.'/app/'.str_replace('\\','/',substr($class,4)).'.php'; if(is_file($path)) require_once $path; } });
require $source.'/app/Support/functions.php'; require $source.'/tests/Installer/database_fixture.php';
$checks=0; $owns=false; $targetOwns=false; $pdo=null; $targetPdo=null;
$assert=static function(bool $condition,string $message) use(&$checks): void { if(!$condition) throw new RuntimeException($message);$checks++; };
$reject=static function(callable $call,string $message) use($assert): void { try{$call();}catch(Throwable){$assert(true,$message);return;}$assert(false,$message); };
$authorize=static function(): void {};

$children=[];
$spawn=static function(string $task)use($root,&$children):array{
    $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--worker',$root,$task],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Could not start the actual isolated PHP worker.');$children[]=$process;return[$process,$pipes];
};
$join=static function(array $child)use($assert):string{[$process,$pipes]=$child;fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$assert(proc_close($process)===0&&$error==='','Actual PHP worker failed: '.$error);return$out;};

try {
    // MySQL can make a redundant column character set explicit on restore;
    // that normalization must not hide changed literals or real collations.
    $schemaFixture=['table'=>'fixture','create_sql'=>"CREATE TABLE `fixture` (`label` varchar(100) COLLATE utf8mb4_bin DEFAULT 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='preserved'",'columns'=>['label'],'insert_columns'=>['label']];
    $sameSchema=$schemaFixture;$sameSchema['create_sql']=str_replace('varchar(100) COLLATE','varchar(100) CHARACTER SET utf8mb4 COLLATE',$sameSchema['create_sql']);
    $assert(BackupDatabase::equivalentSchemas(['fixture'=>$schemaFixture],['fixture'=>$sameSchema]),'Equivalent explicit MySQL character set was refused.');
    foreach(["DEFAULT 'COLLATE utf8mb4_bin'","DEFAULT 'changed'"] as $different){$changed=$schemaFixture;$changed['create_sql']=str_replace("DEFAULT 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'",$different,$changed['create_sql']);$assert(!BackupDatabase::equivalentSchemas(['fixture'=>$schemaFixture],['fixture'=>$changed]),'Schema normalization ignored changed literal contents.');}
    $changed=$sameSchema;$changed['create_sql']=str_replace("COMMENT='preserved'","COMMENT='changed'",$changed['create_sql']);
    $assert(!BackupDatabase::equivalentSchemas(['fixture'=>$schemaFixture],['fixture'=>$changed]),'Schema normalization ignored a changed table comment.');
    $changed=$sameSchema;$changed['create_sql']=str_replace('varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin','varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',$changed['create_sql']);
    $assert(!BackupDatabase::equivalentSchemas(['fixture'=>$schemaFixture],['fixture'=>$changed]),'Schema normalization ignored a changed column collation.');
    $values=['installed'=>true,'installation_id'=>'operations-source-fixture','storage_mode'=>$mysql?'mysql':'file','file_store_path'=>'storage/data.json',
        'base_url'=>'http://127.0.0.1:8879','timezone'=>'UTC','security'=>['app_key'=>str_repeat('a',64),'signing_key'=>str_repeat('s',64),'media_key'=>str_repeat('m',64)],'mail'=>['mode'=>'disabled']];
    $targetFile=null;
    if($mysql){
        [$db,$pdo]=vvx_installer_fixture_database();$assert($db['host']==='127.0.0.1','Only loopback source database permitted.');
        vvx_installer_fixture_empty($pdo);$owns=true;$values['db']=$db;
        (new App\Core\MigrationRunner($pdo,$source.'/database/migrations'))->migrate(false,true);
        $targetFile=getenv('VELVET_OPERATIONS_TARGET_DB_FILE')?:'';
        $targetDb=json_decode((string)file_get_contents($targetFile),true,16,JSON_THROW_ON_ERROR);
        $assert(($targetDb['host']??'')==='127.0.0.1' && preg_match('/(?:^|_)test(?:_|$)/i',(string)($targetDb['name']??''))===1 && $targetDb['name']!==$db['name'],'Only a different explicitly named disposable target database is permitted.');
        $targetPdo=App\Core\Database::connect($targetDb);vvx_installer_fixture_empty($targetPdo);$targetOwns=true;
    }
    file_put_contents($root.'/config/app.php','<?php return '.var_export($values,true).';');chmod($root.'/config/app.php',0600);
    file_put_contents($root.'/storage/install.lock',json_encode(['installation_id'=>$values['installation_id']],JSON_THROW_ON_ERROR));
    $config=new Config($root.'/config/app.php');$store=new Store($config);
    $id=$store->createAdmin('Operations Owner','operations-owner@example.test','LocalFixturePassword123!');$owner=['id'=>(string)$id,'email'=>'operations-owner@example.test'];
    if($mysql){
        $store->setSetting('installation',['installation_id'=>$values['installation_id']]);
        $pdo->exec('CREATE TABLE operations_binary_fixture (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, raw_value LONGBLOB NULL, label VARCHAR(100) NULL, n DECIMAL(20,7), doubled INT GENERATED ALWAYS AS (CHAR_LENGTH(label) * 2) STORED) ENGINE=InnoDB');
        $q=$pdo->prepare('INSERT INTO operations_binary_fixture (raw_value,label,n) VALUES (?,?,?)');
        foreach([["\0\xff\n\r\0'\\",'UTF-8 ♥','12345678901.1234567'],[null,null,null],['','',0]] as $row)$q->execute($row);
    }
    // Capture populated recurring records through the real encrypted archive,
    // then read the isolated restored file/SQL data independently of Store.
    require_once $source.'/tests/Support/RecurringFixture.php';
    [$recurringStore,$recurringCommerce,$recurringProvider]=recurring_fixture_service($config);
    $recurringStore->savePaymentProvider(['key'=>'fixture-recurring','enabled'=>true,'supportsRecurring'=>true]);
    $recurringMember=$recurringStore->createMember(['displayName'=>'Recurring backup fixture','email'=>'recurring-backup@example.test','password'=>'Owned-Backup-Fixture-123!','status'=>'active','emailVerifiedAt'=>now_iso()]);
    $recurringPlan=$recurringStore->saveMembershipPlan(['name'=>'Recurring backup fixture','key'=>'recurring-backup','price'=>12.34,'currency'=>'USD','billingInterval'=>'month','intervalCount'=>1,'isActive'=>true]);
    $recurringCommerce->checkout($recurringMember['id'],[['type'=>'plan','id'=>$recurringPlan['id']]],'','fixture-recurring',[],'operations:recurring:purchase');
    $recurringAgreement=$recurringStore->recurringAgreementPage(null,10,$recurringMember['id'])['records'][0];
    $recurringProvider->mode='timeout';
    $recurringCommerce->requestRecurringCommand($recurringMember['id'],$recurringAgreement['subscriptionId'],'cancel',$recurringAgreement['revision'],'operations:recurring:cancel');
    $recurringAgreement=$recurringStore->recurringAgreement($recurringAgreement['id']);
    $recurringJournal=$recurringStore->recurringJournalForAgreement($recurringAgreement['id']);
    $assert(count($recurringJournal)===2 && count(array_filter($recurringJournal,static fn(array $r):bool=>$r['state']==='unknown'))===1,'Backup fixture lacks populated authoritative and unresolved recurring records.');
    file_put_contents($root.'/uploads/customer.bin',"Exact upload\0\xff\n");file_put_contents($root.'/storage/private/customer.bin','Private fixture payload');
    mkdir($root.'/storage/backups',0700,true);file_put_contents($root.'/storage/backups/recursive-old.zip','Do not recursively copy backups.');
    mkdir($root.'/storage/sessions',0700,true);file_put_contents($root.'/storage/sessions/session-fixture','Exclude active sessions.');
    $configHash=hash_file('sha256',$config->path());$before=OperationsFiles::inventory($root);
    $service=new BackupService($config,$root);$journal=new PrivateState($config,$root);
    $empty=$service->snapshot();$assert($empty['jobs']===[]&&!is_dir($root.'/storage/private/operations'),'Read-only backup snapshot initialized operations.');
    $diag=$service->diagnostics();$assert(count($diag)===9&&!is_dir($root.'/storage/private/operations')&&hash_file('sha256',$config->path())===$configHash,'Diagnostics initialized operations or changed config.');
    OperationsGate::bootstrap($root);$assert(!is_dir($root.'/storage/private/operations'),'Unconfigured bootstrap created a gate.');
    $reject(fn()=>$service->queue($empty['revision'],$owner,$authorize),'Backup queued before first setup.');
    $reject(fn()=>OperationsGate::setup($root,$config,$configHash,static function():void{throw new RuntimeException('Revoked',403);}),'Revoked owner enabled operations.');
    $assert(OperationsFiles::inventory($root)===$before,'Refused setup changed any original file.');
    $gate=OperationsGate::setup($root,$config,$configHash,$authorize);
    $assert($gate['configured']&&!$gate['ready']&&$gate['recovery'],'Initial setup silently allowed undrained runtime.');
    $assert((fileperms($root.'/storage/private/operations/request.lock')&0777)===0600,'Request mutex permissions not private.');
    $reject(fn()=>OperationsGate::activate($root,$config,$gate['revision'],false),'Activation ignored old-process drain confirmation.');
    $reject(fn()=>OperationsGate::activate($root,$config,str_repeat('0',64),true),'Stale activation revision was accepted.');
    $gate=OperationsGate::activate($root,$config,$gate['revision'],true);$assert($gate['ready']&&!$gate['recovery'],'Reviewed gate activation failed.');
    $keyPath=$work.'/backup.key';file_put_contents($keyPath,random_bytes(32));chmod($keyPath,0600);$key=file_get_contents($keyPath);
    $reject(fn()=>OperationsGate::configureKey($root,$config,$keyPath,str_repeat('0',64)),'Stale key configuration accepted.');
    OperationsGate::configureKey($root,$config,$keyPath,$config->revision());
    copy($keyPath,$root.'/unsafe.key');chmod($root.'/unsafe.key',0600);$reject(fn()=>OperationsFiles::key($root.'/unsafe.key',$root),'A key inside the archived website was accepted.');unlink($root.'/unsafe.key');
    chmod($keyPath,0640);$reject(fn()=>OperationsFiles::key($keyPath,$root),'A group-readable archive key was accepted.');chmod($keyPath,0600);
    $afterSetup=$service->snapshot();$job=$service->queue($afterSetup['revision'],$owner,$authorize);$assert($job['status']==='queued','Manual request did not persist queued state.');
    $reject(fn()=>$service->queue($afterSetup['revision'],$owner,$authorize),'Duplicate/stale manual backup was accepted.');
    $outside=$work.'/outside';file_put_contents($outside,'Do not read this symlink target.');symlink($outside,$root.'/uploads/link.bin');
    $result=$service->run();$failed=$service->snapshot()['jobs'][$job['id']];$assert($result['failed']===1&&$failed['status']==='failed'&&file_get_contents($outside)==='Do not read this symlink target.','Symlink did not fail closed without changing its target.');unlink($root.'/uploads/link.bin');
    $snapshot=$service->snapshot();$job=$service->queue($snapshot['revision'],$owner,$authorize);$result=$service->run();
    $assert($result['completed']===1&&$result['failed']===0,'Actual backup did not complete: '.json_encode($result));
    $saved=$service->snapshot()['jobs'][$job['id']];$path=$root.'/storage/private/operations/backups/'.$job['id'].'.vvxbak';
    $assert($saved['status']==='complete'&&$saved['archive_sha256']===hash_file('sha256',$path),'Completed archive hash was not durably bound.');
    $literal=file_get_contents($path);$assert(!str_contains($literal,'Private fixture payload')&&!str_contains($literal,'operations-owner@example.test'),'Archive exposed plaintext customer data.');
    $entries=[];$scan=BackupArchive::scan($path,$key,static function(string $event,array $entry,string $data) use(&$entries):void{if($event==='end')$entries[$entry['kind'].':'.$entry['path']]=$entry;});
    foreach(['file:config/app.php','file:storage/install.lock','file:uploads/customer.bin','file:storage/private/customer.bin'] as $name)$assert(isset($entries[$name]),'Required installation data omitted: '.$name);
    $assert(!isset($entries['file:storage/backups/recursive-old.zip'])&&!isset($entries['file:storage/sessions/session-fixture']),'Recursive backups or active sessions entered archive.');
    $assert($scan['header']['build']===VELVET_VIXXEN_BUILD_ID&&$scan['manifest']===$saved['manifest'],'Archive identity/manifest differs from saved record.');
    if($mysql)$assert(isset($entries['schema:operations_binary_fixture'],$entries['rows:operations_binary_fixture'])&&$scan['manifest']['database']['tables']['operations_binary_fixture']['rows']===3,'Binary table or row inventory omitted.');
    $assert($service->verify($job['id'])['verified'],'Full archive verification failed.');
    $download=$service->archive($job['id'],$authorize);$assert(hash('sha256',stream_get_contents($download['handle']))===$saved['archive_sha256'],'Owner ciphertext download differs.');fclose($download['handle']);
    $reject(fn()=>$service->archive($job['id'],static function():void{throw new RuntimeException('Revoked',403);}),'Revoked owner downloaded archive.');
    foreach(['wrong-key','truncated','tampered','trailing'] as $kind){
        $bad=$work.'/'.$kind.'.vvxbak';$bytes=$literal;$testKey=$key;
        if($kind==='wrong-key')$testKey=random_bytes(32);elseif($kind==='truncated')$bytes=substr($bytes,0,-5);elseif($kind==='tampered')$bytes[70]=chr(ord($bytes[70])^1);else$bytes.='trailing';
        file_put_contents($bad,$bytes);$reject(fn()=>BackupArchive::scan($bad,$testKey),'Corrupt/incomplete archive authenticated: '.$kind);
    }
    $restore=new BackupRestore($config,$root);$target=$work.'/restored';
    $reject(fn()=>$restore->review($path,$keyPath,$root,$targetFile),'Live source directory was accepted as restore target.');
    $reject(fn()=>$restore->review($path,$keyPath,$root.'/nested',$targetFile),'Nested live restore target was accepted.');
    mkdir($work.'/occupied',0700);file_put_contents($work.'/occupied/sentinel','Keep');$reject(fn()=>$restore->review($path,$keyPath,$work.'/occupied',$targetFile),'Nonempty target was accepted.');
    $plan=$restore->review($path,$keyPath,$target,$targetFile);$assert(!file_exists($target)&&$plan['quarantine_required'],'Restore review mutated its target.');
    $reject(fn()=>$restore->apply($path,$keyPath,$target,$targetFile,str_repeat('0',64),true),'Stale restore plan was applied.');
    $reject(fn()=>$restore->apply($path,$keyPath,$target,$targetFile,$plan['plan'],false),'Unconfirmed target was mutated.');
    $applied=$restore->apply($path,$keyPath,$target,$targetFile,$plan['plan'],true);
    $assert($applied['verified']&&$applied['quarantined']&&is_file($target.'/.vvx-restore-quarantine.json'),'Verified restore escaped quarantine.');
    foreach($entries as $entry)if($entry['kind']==='file')$assert(hash_file('sha256',$target.'/'.$entry['path'])===$entry['sha256'],'Restored bytes differ: '.$entry['path']);
    $assert(!is_file($target.'/storage/private/operations/request.lock')&&!is_dir($target.'/storage/backups'),'Restore included live mutex or recursive backup copies.');
    $reject(fn()=>$restore->apply($path,$keyPath,$target,$targetFile,$plan['plan'],true),'Repeated restore overwrote existing drill.');
    $reject(fn()=>OperationsGate::assertNotQuarantined($target),'Restored runtime was allowed to start workers.');
    if($mysql){
        $sourceRows=$pdo->query('SELECT id,HEX(raw_value),label,n,doubled FROM operations_binary_fixture ORDER BY id')->fetchAll(PDO::FETCH_NUM);
        $targetRows=$targetPdo->query('SELECT id,HEX(raw_value),label,n,doubled FROM operations_binary_fixture ORDER BY id')->fetchAll(PDO::FETCH_NUM);
        $assert($sourceRows===$targetRows,'Independent binary/NULL/decimal/generated-column readback differs.');
    }
    if($mysql){
        $restoredAgreement=json_decode($targetPdo->query('SELECT record_json FROM recurring_agreements')->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
        $restoredJournal=array_map(static fn(string $json):array=>json_decode($json,true,32,JSON_THROW_ON_ERROR),$targetPdo->query('SELECT record_json FROM recurring_journal ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }else{
        $restoredData=json_decode(file_get_contents($target.'/storage/data.json'),true,512,JSON_THROW_ON_ERROR);
        $restoredAgreement=$restoredData['recurringAgreements'][$recurringAgreement['id']];
        $restoredJournal=array_values($restoredData['recurringJournal']);usort($restoredJournal,static fn(array $a,array $b):int=>strcmp($a['id'],$b['id']));
    }
    $assert($restoredAgreement===$recurringAgreement && $restoredJournal===$recurringJournal,'Populated recurring agreement, immutable terms or original unresolved request changed in the isolated restore.');
    // Real persisted claim, complete encrypted file, then a fresh worker: the
    // finish commit is replayed once without recapturing later source changes.
    $journal->update(BackupJobs::NAMESPACE,static function(array &$state) use($job):void{$j=&$state['jobs'][$job['id']];$j['status']='processing';$j['claim']=bin2hex(random_bytes(16));$j['completed_at']=null;$j['verified_at']=null;$j['archive_sha256']=null;$j['archive_bytes']=null;$j['manifest']=null;});
    file_put_contents($root.'/uploads/later.bin','After completed archive.');$recovered=$service->run();
    $assert($recovered['completed']===1&&$recovered['recovered']===1&&hash_file('sha256',$path)===$saved['archive_sha256'],'Restart recopied or duplicated complete archive.');
    $assert($service->run()['completed']===0,'Repeated worker replayed a completed job.');

    $snapshot=$service->snapshot();$claimJob=$service->queue($snapshot['revision'],$owner,$authorize);
    $claimed=json_decode($join($spawn('claim')),true,16,JSON_THROW_ON_ERROR);
    $assert($claimed['id']===$claimJob['id']&&$claimed['status']==='processing'&&$service->snapshot()['jobs'][$claimJob['id']]['status']==='processing','The real claim-and-exit worker did not commit its intent.');
    $failedRecovery=json_decode($join($spawn('run')),true,16,JSON_THROW_ON_ERROR);
    $assert($failedRecovery['failed']===1&&$service->snapshot()['jobs'][$claimJob['id']]['reason']==='interrupted_snapshot','The absent interrupted archive was silently recaptured.');
    $snapshot=$service->snapshot();$concurrent=$service->queue($snapshot['revision'],$owner,$authorize);
    [$holder,$holderPipes]=$spawn('shared');$assert(fgets($holderPipes[1])==="ready\n",'Real shared request never acquired its existing gate.');
    $workers=[$spawn('run'),$spawn('run')];usleep(150000);
    $assert($service->snapshot()['jobs'][$concurrent['id']]['status']==='queued','A backup claimed work before the earlier shared request drained.');
    fwrite($holderPipes[0],"finish\n");fflush($holderPipes[0]);$join([$holder,$holderPipes]);
    $workerResults=array_map(static fn(array $child):array=>json_decode($join($child),true,16,JSON_THROW_ON_ERROR),$workers);
    $assert(array_sum(array_column($workerResults,'completed'))===1&&array_sum(array_column($workerResults,'failed'))===0,'Two actual workers duplicated or failed a queued backup.');
    $concurrentSaved=$service->snapshot()['jobs'][$concurrent['id']];$concurrentPath=$root.'/storage/private/operations/backups/'.$concurrent['id'].'.vvxbak';$copiedWrite='';
    BackupArchive::scan($concurrentPath,$key,static function(string $event,array $entry,string $data)use(&$copiedWrite):void{if($event==='data'&&$entry['path']==='uploads/drained-write.txt')$copiedWrite.=$data;});
    $assert($copiedWrite==='The earlier request finished before capture.','Snapshot missed the committed earlier request write.');
    $snapshot=$service->snapshot();$service->savePolicy(['enabled'=>false,'utc_time'=>'03:00','keep_count'=>1,'keep_days'=>1],$snapshot['revision'],$owner,$authorize);
    $journal->update(BackupJobs::NAMESPACE,static function(array &$state)use($job):void{$state['jobs'][$job['id']]['completed_at']=time()-3*86400;});
    $pruned=$service->run();$assert($pruned['pruned']===1&&!file_exists($path)&&is_file($concurrentPath)&&$service->snapshot()['jobs'][$job['id']]['status']==='deleted','Retention removed the newest copy or kept the exact old candidate.');
    // A deletion committed before a killed process is finalized without ever
    // removing a retained copy or touching either persistent mutex inode.
    $journal->update(BackupJobs::NAMESPACE,static function(array &$state)use($job):void{$state['jobs'][$job['id']]['status']='deleting';$state['jobs'][$job['id']]['deleted_at']=null;});
    $pruned=$service->run();$assert($pruned['pruned']===1&&is_file($concurrentPath)&&$service->snapshot()['jobs'][$job['id']]['status']==='deleted','Interrupted retention was not finalized exactly once.');
    $snapshot=$service->snapshot();$service->savePolicy(['enabled'=>true,'utc_time'=>'00:00','keep_count'=>1,'keep_days'=>1],$snapshot['revision'],$owner,$authorize);
    $p=$service->snapshot()['policy'];$assert($p['not_before']>time()&&$service->run()['completed']===0,'Saving a past daily time immediately replayed a backlog.');
    // Set only this owned schedule's committed due boundary into the past; the
    // production worker still reads the actual system clock with no override.
    $journal->update(BackupJobs::NAMESPACE,static function(array &$state):void{$state['policy']['not_before']=time()-86400;$state['policy']['last_date']=null;});
    $due=$service->run();$assert($due['completed']===1&&$service->snapshot()['policy']['last_date']===gmdate('Y-m-d'),'Daily scheduler did not commit the actual due day.');
    $assert($service->run()['completed']===0,'Daily scheduler duplicated the same UTC day.');
    $snapshot=$service->snapshot();$service->savePolicy(['enabled'=>false,'utc_time'=>'00:00','keep_count'=>1,'keep_days'=>1],$snapshot['revision'],$owner,$authorize);

    // Revoke the authorizing owner after queue. The worker holds it permanently.
    $snapshot=$service->snapshot();$held=$service->queue($snapshot['revision'],$owner,$authorize);
    if($mysql)$pdo->prepare('UPDATE admins SET role=? WHERE id=?')->execute(['editor',$id]);else{$data=json_decode(file_get_contents($root.'/storage/data.json'),true);$data['admins'][(string)$id]['role']='editor';file_put_contents($root.'/storage/data.json',json_encode($data));}
    $result=$service->run();$assert($result['held']===1&&$service->snapshot()['jobs'][$held['id']]['status']==='held','Revoked owner backup was captured.');
    if($mysql)$pdo->prepare('UPDATE admins SET role=? WHERE id=?')->execute(['owner',$id]);else{$data['admins'][(string)$id]['role']='owner';file_put_contents($root.'/storage/data.json',json_encode($data));}
    $assert($service->run()['completed']===0,'Restoring owner role revived terminal held backup.');
    $gate=OperationsGate::status($root,$config);$lockInode=fileinode($root.'/storage/private/operations/request.lock');
    $gate=OperationsGate::setRecovery($root,$config,true,$gate['revision'],$authorize);$assert($gate['recovery'],'Recovery mode did not persist.');
    $reject(fn()=>OperationsGate::setRecovery($root,$config,false,str_repeat('0',64),$authorize),'Stale recovery revision was accepted.');
    $gate=OperationsGate::setRecovery($root,$config,false,$gate['revision'],$authorize);$assert(!$gate['recovery']&&fileinode($root.'/storage/private/operations/request.lock')===$lockInode,'Recovery mode replaced held mutex inode.');
    // Strict durable corruption never clears or silently repairs operator evidence.
    $valid=$journal->read(BackupJobs::NAMESPACE);
    foreach(['schema','job-policy','job-id','unknown-status'] as $bad){$corrupt=$valid;if($bad==='schema')$corrupt['schema']=2;elseif($bad==='job-policy')$corrupt['policy']['keep_count']=0;elseif($bad==='job-id')$corrupt['jobs'][$job['id']]['id']=[];else$corrupt['jobs'][$job['id']]['status']='pretend_complete';
        $journal->update(BackupJobs::NAMESPACE,static function(array &$raw)use($corrupt):void{$raw=$corrupt;});$reject(fn()=>$service->snapshot(),'Malformed persisted state accepted: '.$bad);$assert($journal->read(BackupJobs::NAMESPACE)===$corrupt,'Malformed state was overwritten.');
        $journal->update(BackupJobs::NAMESPACE,static function(array &$raw)use($valid):void{$raw=$valid;});}
    echo 'Operations source acceptance passed ('.$checks.' checks; '.($mysql?'SQL source '.$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).'; target '.$targetPdo->getAttribute(PDO::ATTR_SERVER_VERSION):'file').'; encrypted archive and isolated complete restore).'.PHP_EOL;
}finally{
    foreach($children as $process)if(is_resource($process)){proc_terminate($process,9);proc_close($process);}
    if($targetPdo&&$targetOwns)vvx_installer_fixture_reset_database($targetPdo,true);
    if($pdo&&$owns)vvx_installer_fixture_reset_database($pdo,true);
    if(is_file($work.'/.owned-operations-fixture'))vvx_installer_fixture_remove($work);
}
