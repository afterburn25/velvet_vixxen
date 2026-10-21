<?php
declare(strict_types=1);

// Private fixture, never shipped. Only its marked loopback runtime and its
// explicitly owned source/target test schemas may be observed or interrupted.
$root=realpath((string)($argv[1]??''));$action=(string)($argv[2]??'read');
$input=json_decode(fgets(STDIN)?:'{}',true,32,JSON_THROW_ON_ERROR);
if(PHP_SAPI!=='cli'||$root===false||!is_array($input)||!str_starts_with($root,sys_get_temp_dir().'/vvx-operations-')
    ||!is_file($root.'/.vvx-test-runtime')||!is_file($root.'/storage/temp/editorial-access-ownership.json'))throw new RuntimeException('Only a marked owned disposable operations runtime is allowed.');
$ownership=json_decode(file_get_contents($root.'/storage/temp/editorial-access-ownership.json'),true,32,JSON_THROW_ON_ERROR);
$config=require $root.'/config/app.php';$mode=$ownership['mode']??'';
if(($ownership['runtime']??null)!==$root||!in_array($mode,['file','mysql'],true)||($config['storage_mode']??null)!==$mode
    ||($config['base_url']??null)!==($ownership['base']??null)||parse_url($config['base_url'],PHP_URL_HOST)!=='127.0.0.1'
    ||parse_url($config['base_url'],PHP_URL_SCHEME)!=='http'||($config['mail']['mode']??'')!=='smtp'||($config['mail']['host']??'')!=='127.0.0.1'
    ||(int)($config['mail']['port']??0)!==($ownership['smtp_port']??null))throw new RuntimeException('The exact local operations fixture identity changed.');
$pdo=null;$sourceVersion=null;
require_once dirname(__DIR__).'/Installer/database_fixture.php';
if($mode==='mysql'){
    [$db,$pdo]=vvx_installer_fixture_database();
    if($db['host']!=='127.0.0.1'||$db['name']!==($ownership['database']??'')||$db['name']!==($config['db']['name']??'')
        ||$db['user']!==($config['db']['user']??'')||$db['port']!==(int)($config['db']['port']??0))throw new RuntimeException('The owned source test database changed.');
    $sourceVersion=$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
}
$marker=$root.'/storage/temp/operations-fixture.json';
if($action==='seed'){
    if(is_file($marker))throw new RuntimeException('The fixture is already seeded.');
    file_put_contents($root.'/uploads/operations-customer.bin',"Independent upload\0\xff\n");
    file_put_contents($root.'/storage/private/operations-customer.bin','Private independent customer bytes.');
    file_put_contents($marker,json_encode(['runtime'=>$root,'mode'=>$mode,'seeded'=>true],JSON_THROW_ON_ERROR));chmod($marker,0600);
    echo json_encode(['seeded'=>true],JSON_THROW_ON_ERROR);exit;
}
if(!is_file($marker))throw new RuntimeException('The owned operations fixture must be seeded first.');
$load=static function()use($root):void{define('ROOT_PATH',$root);require $root.'/includes/autoload.php';require $root.'/includes/version.php';require $root.'/app/Support/functions.php';};
if(in_array($action,['claim','shared','finish-lock'],true)){
    $load();$current=new App\Core\Config($root.'/config/app.php');$service=new App\Core\BackupService($current,$root);
    if($action==='shared'){
        App\Core\OperationsGate::bootstrap($root);echo "ready\n";fflush(STDOUT);fgets(STDIN);
        file_put_contents($root.'/uploads/operations-drained.txt','Earlier gated request completed.');exit;
    }
    if($action==='claim'){
        $gate=App\Core\OperationsGate::exclusive($root,$current);
        $key=App\Core\OperationsFiles::key((string)$current->get('operations.backup_key_file'),$root);
        $claimed=(new ReflectionMethod($service,'claim'))->invoke($service,hash('sha256',$key));
        if(!is_array($claimed)||$claimed['recovering'])throw new RuntimeException('A fresh real claim is required.');
        echo json_encode(['id'=>$claimed['id'],'status'=>$claimed['status']],JSON_THROW_ON_ERROR);exit;
    }
    $id=$input['id']??null;if(!App\Core\BackupJobs::id($id))throw new RuntimeException('Select the exact fixture job.');
    $partial=$root.'/storage/private/operations/backups/'.$id.'.partial';$deadline=microtime(true)+12;
    while(!is_file($partial)&&microtime(true)<$deadline)usleep(1000);
    if(!is_file($partial))throw new RuntimeException('The real worker never began its encrypted file.');
    if($pdo!==null){$pdo->beginTransaction();$q=$pdo->prepare('SELECT payload_json FROM private_state WHERE namespace=? FOR UPDATE');$q->execute(['site_backups']);$raw=$q->fetchColumn();}
    else{$handle=fopen($root.'/storage/private/operations/site_backups.lock','r+b');flock($handle,LOCK_EX);$raw=file_get_contents($root.'/storage/private/operations/site_backups.json');}
    $saved=json_decode($raw,true,64,JSON_THROW_ON_ERROR);$job=$saved['data']['jobs'][$id]??null;
    if(!is_array($job)||$job['status']!=='processing')throw new RuntimeException('The owned finish lock did not precede the real commit.');
    echo "locked\n";fflush(STDOUT);fgets(STDIN);
    if($pdo!==null)$pdo->rollBack();else{flock($handle,LOCK_UN);fclose($handle);}exit;
}
if($action==='target-prepare'||$action==='target-cleanup'){
    $file=getenv('VELVET_OPERATIONS_TARGET_DB_FILE')?:'';$target=json_decode((string)file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
    if(($target['host']??'')!=='127.0.0.1'||!preg_match('/(?:^|_)test(?:_|$)/i',(string)($target['name']??''))||($target['name']??'')===($config['db']['name']??''))throw new RuntimeException('Only a separate disposable loopback restore target is permitted.');
    $load();$connection=App\Core\Database::connect($target);$targetMarker=$root.'/storage/temp/operations-target-ownership.json';
    if($action==='target-prepare'){
        if(is_file($targetMarker))throw new RuntimeException('Target ownership already recorded.');vvx_installer_fixture_empty($connection);
        file_put_contents($targetMarker,json_encode(['runtime'=>$root,'database'=>$target['name'],'config_sha256'=>hash_file('sha256',$file)],JSON_THROW_ON_ERROR));chmod($targetMarker,0600);
    }else{
        $owned=is_file($targetMarker)?json_decode(file_get_contents($targetMarker),true,16,JSON_THROW_ON_ERROR):null;
        if(!is_array($owned)||$owned!==['runtime'=>$root,'database'=>$target['name'],'config_sha256'=>hash_file('sha256',$file)])throw new RuntimeException('Target cleanup ownership mismatch.');
        vvx_installer_fixture_reset_database($connection,true);unlink($targetMarker);
    }
    echo json_encode(['target_server'=>$connection->getAttribute(PDO::ATTR_SERVER_VERSION),'empty'=>$connection->query('SHOW TABLES')->fetchAll()===[]],JSON_THROW_ON_ERROR);exit;
}
if($action==='expire-session'){
    $session=$input['session']??null;
    if(!is_string($session)||!preg_match('/\A[A-Za-z0-9,-]{20,128}\z/D',$session))throw new RuntimeException('An exact owned session is required.');
    $file=$root.'/storage/sessions/sess_'.$session;
    if(!is_file($file)||is_link($file))throw new RuntimeException('The owned session file is absent.');
    session_save_path($root.'/storage/sessions');session_name('velvet_vixxen_admin');session_id($session);session_start();
    if(($_SESSION['admin']['email']??null)!=='owner@example.test'||!is_string($_SESSION['admin']['token']??null))throw new RuntimeException('The session does not belong to the synthetic owner.');
    $_SESSION['admin']['authenticated_at']=time()-1000;session_write_close();echo json_encode(['expired'=>true],JSON_THROW_ON_ERROR);exit;
}
if($action==='age-retention'||$action==='due-schedule'){
    $load();$journal=new App\Core\PrivateState(new App\Core\Config($root.'/config/app.php'),$root);
    $journal->update('site_backups',static function(array &$state)use($action,$input):void{
        $state=App\Core\BackupJobs::state($state);
        if($action==='age-retention'){
            $id=$input['id']??null;if(!App\Core\BackupJobs::id($id)||($state['jobs'][$id]['status']??'')!=='complete')throw new RuntimeException('Only the named complete fixture backup can be aged.');
            $state['jobs'][$id]['completed_at']=time()-10*86400;
        }else{
            if(!$state['policy']['enabled']||$state['policy']['utc_time']!=='00:00')throw new RuntimeException('A real saved midnight policy is required.');
            $state['policy']['not_before']=time()-86400;$state['policy']['last_date']=null;
        }
    });echo json_encode(['changed'=>true],JSON_THROW_ON_ERROR);exit;
}
if($action!=='read')throw new RuntimeException('Unsupported owned operations fixture action.');
$raw=$pdo!==null?(static function(PDO $pdo):mixed{$q=$pdo->prepare('SELECT payload_json FROM private_state WHERE namespace=?');$q->execute(['site_backups']);return$q->fetchColumn();})($pdo):@file_get_contents($root.'/storage/private/operations/site_backups.json');
$journal=is_string($raw)?json_decode($raw,true,64,JSON_THROW_ON_ERROR):null;
if($journal!==null&&(($journal['schema']??null)!==1||($journal['namespace']??null)!=='site_backups'||!is_array($journal['data']??null)))throw new RuntimeException('Independent journal envelope check failed.');
$gatePath=$root.'/storage/private/operations/gate.json';$gate=is_file($gatePath)?json_decode(file_get_contents($gatePath),true,16,JSON_THROW_ON_ERROR):null;
$archives=[];$directory=$root.'/storage/private/operations/backups';
foreach(is_dir($directory)?scandir($directory):[] as $name){if($name==='.'||$name==='..')continue;if(!preg_match('/\A[a-f0-9]{32}\.(?:vvxbak|partial)\z/D',$name)||is_link($directory.'/'.$name))throw new RuntimeException('Unexpected archive fixture path.');$archives[$name]=['sha256'=>hash_file('sha256',$directory.'/'.$name),'bytes'=>filesize($directory.'/'.$name)];}
$protected=[];foreach(['storage/install.lock','uploads/editorial-existing.bin','storage/private/editorial-existing.bin','storage/backups/editorial-existing.txt','uploads/operations-customer.bin','storage/private/operations-customer.bin']as$path)$protected[$path]=hash_file('sha256',$root.'/'.$path);
$tree=[];$private=$root.'/storage/private/operations';if(is_dir($private))foreach(scandir($private)as$name){if($name==='.'||$name==='..'||is_dir($private.'/'.$name))continue;$tree[$name]=['sha256'=>hash_file('sha256',$private.'/'.$name),'mode'=>fileperms($private.'/'.$name)&0777,'inode'=>fileinode($private.'/'.$name)];}
echo json_encode(['mode'=>$mode,'source_server'=>$sourceVersion,'gate'=>$gate['state']??null,'gate_revision'=>$gate===null?'unconfigured':hash_file('sha256',$gatePath),
    'config_revision'=>hash_file('sha256',$root.'/config/app.php'),'operations_enabled'=>$config['operations']['enabled']??false,
    'journal'=>$journal['data']??null,'archives'=>$archives,'protected'=>$protected,'private_files'=>$tree],JSON_THROW_ON_ERROR);
