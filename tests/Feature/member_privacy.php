<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Store;
use App\Core\PrivateState;
use App\Core\MemberPrivacyService as Privacy;
use App\Core\MemberPrivacyArchive as Archive;
use App\Core\SecureValue;
use Velvet\Core\MarketingStore;
use Velvet\Core\MarketingStorage;

$source=dirname(__DIR__,2);$root=sys_get_temp_dir().'/vvx-member-privacy-'.bin2hex(random_bytes(8));
mkdir($root.'/config',0700,true);mkdir($root.'/storage/private/communications',0700,true);
define('ROOT_PATH',$root);
spl_autoload_register(static function(string $class)use($source):void{if(str_starts_with($class,'App\\'))require_once $source.'/app/'.str_replace('\\','/',substr($class,4)).'.php';});
require $source.'/app/Support/functions.php';require $source.'/app/Core/MarketingStore.php';require $source.'/tests/Installer/database_fixture.php';
$mysql=in_array('--mysql',$argv,true);$pdo=null;$owns=false;$checks=0;
$assert=static function(bool $ok,string $why)use(&$checks):void{++$checks;if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $call,string $why,?int $code=null)use($assert):void{try{$call();}catch(InvalidArgumentException|RuntimeException $error){$assert($code===null||$error->getCode()===$code,$why.': '.$error->getMessage());return;}$assert(false,$why);};
try{
    $config=new Config($root.'/config/app.php');$values=['storage_mode'=>$mysql?'mysql':'file','file_store_path'=>'storage/data.json','installed'=>true,
        'installation_id'=>'privacy-test-'.bin2hex(random_bytes(12)),'base_url'=>'http://127.0.0.1/fixture','mail'=>['mode'=>'disabled'],
        'security'=>['app_key'=>bin2hex(random_bytes(32)),'media_key'=>bin2hex(random_bytes(32))]];
    if($mysql){[$db,$pdo]=vvx_installer_fixture_database();vvx_installer_fixture_empty($pdo);$owns=true;$values['db']=$db;}
    $config->write($values);file_put_contents($root.'/storage/install.lock',json_encode(['installation_id'=>$values['installation_id']],JSON_THROW_ON_ERROR));
    if($mysql){(new App\Core\MigrationRunner($pdo,$source.'/database/migrations'))->migrate(false,true);mkdir($root.'/database/migrations',0700,true);foreach(glob($source.'/database/migrations/*.php')as$file)copy($file,$root.'/database/migrations/'.basename($file));}
    else file_put_contents($root.'/storage/data.json','{}');
    $store=new Store($config);if($mysql)$store->setSetting('installation',['installation_id'=>$values['installation_id'],'database_hash'=>hash('sha256',$db['name'].'|'.$values['installation_id'])]);
    $ownerId=(string)$store->createAdmin('Privacy owner','privacy-owner@example.test','Privacy-Owner-781!');$owner=$store->findAdminByEmail('privacy-owner@example.test');
    $member=$store->createMember(['displayName'=>'Privacy member','email'=>'privacy-member@example.test','password'=>'Privacy-Member-781!','status'=>'active','emailVerifiedAt'=>gmdate('c',time()-10),'preferences'=>['emailUpdates'=>true,'newContentAlerts'=>true,'secret'=>'PRIVATE-PREFERENCE']]);
    $other=$store->createMember(['displayName'=>'Other member','email'=>'other-member@example.test','password'=>'Other-Member-781!','status'=>'active','emailVerifiedAt'=>gmdate('c',time()-10)]);
    $token='privacy-device-'.bin2hex(random_bytes(16));$otherToken='other-device-'.bin2hex(random_bytes(16));$store->createMemberSession($member['id'],$token);$store->createMemberSession($other['id'],$otherToken);
    $private=new PrivateState($config,$root);$service=new Privacy($config,$store,$private);$recent=static function():void{};
    $before=$private->read('member_privacy');$assert($service->memberRequests($member['id'],$token)===[]&&$service->ownerRequests($owner)===[]&&$private->read('member_privacy')===$before,'Read-only privacy overview initialized state.');
    $reject(fn()=>$service->request('export',$member['id'],$token,'wrong-password',true),'Wrong password created a request.',403);
    $reject(fn()=>$service->request('export',$member['id'],$token,'Privacy-Member-781!',false),'Missing confirmation created a request.');
    $reject(fn()=>$service->request('export',$other['id'],$token,'Other-Member-781!',true),'Cross-member device created a request.',403);
    $assert($private->read('member_privacy')===[],'Rejected requests wrote the journal.');
    $cancel=$service->request('export',$member['id'],$token,'Privacy-Member-781!',true);
    $reject(fn()=>$service->cancel($cancel['id'],$member['id'],$token,'wrong',true,$cancel['revision']),'Cancellation accepted a wrong password.',403);
    $service->cancel($cancel['id'],$member['id'],$token,'Privacy-Member-781!',true,$cancel['revision']);
    $request=$service->request('export',$member['id'],$token,'Privacy-Member-781!',true);
    $reject(fn()=>$service->request('export',$member['id'],$token,'Privacy-Member-781!',true),'Duplicate open request was accepted.',409);
    $thread=$store->createCommunicationThread(['memberId'=>$member['id'],'subject'=>'Member conversation','internalNotes'=>'PRIVATE-STAFF-NOTE']);
    foreach(['member-visible.txt'=>'Member visible bytes.','staff-secret.txt'=>'PRIVATE-STAFF-FILE','request-visible.txt'=>'Member request bytes.','request-staff.txt'=>'PRIVATE-REQUEST-FILE','other-shared.txt'=>'Other member shared bytes.']as$name=>$bytes)file_put_contents($root.'/storage/private/communications/'.$name,$bytes);
    $attachment=static fn(string $name):array=>['filePath'=>'private:communications/'.$name,'originalName'=>$name,'mimeType'=>'text/plain','fileSize'=>filesize($root.'/storage/private/communications/'.$name)];
    $visible=$store->sendCommunicationMessage(['threadId'=>$thread['id'],'body'=>'My message','attachments'=>[$attachment('member-visible.txt')]]);
    $store->sendCommunicationMessage(['threadId'=>$thread['id'],'senderType'=>'system','visibility'=>'internal','body'=>'PRIVATE-STAFF-BODY','attachments'=>[$attachment('staff-secret.txt')]]);
    $booking=$store->createBookingRequest(['memberId'=>$member['id'],'contactName'=>'Privacy member','contactEmail'=>'privacy-member@example.test','details'=>'Owned booking']);
    $store->addRequestAttachment('booking_request',$booking['id'],$attachment('request-visible.txt'),'member');$store->addRequestAttachment('booking_request',$booking['id'],$attachment('request-staff.txt'),'admin');
    $guest=$store->createBookingRequest(['contactName'=>'Guest same email','contactEmail'=>'privacy-member@example.test','details'=>'UNRELATED-GUEST']);
    $otherThread=$store->createCommunicationThread(['memberId'=>$other['id'],'subject'=>'Other conversation']);$otherMessage=$store->sendCommunicationMessage(['threadId'=>$otherThread['id'],'body'=>'UNRELATED-MESSAGE','attachments'=>[$attachment('other-shared.txt')]]);
    $store->sendCommunicationMessage(['threadId'=>$thread['id'],'body'=>'Shared file reference','attachments'=>[$attachment('other-shared.txt')]]);
    $order=$store->createOrder(['memberId'=>$member['id'],'status'=>'paid','grandTotal'=>41.19,'metadata'=>['shippingAddress'=>'PRIVATE-SHIPPING']], [['itemType'=>'video','itemId'=>'1','title'=>'Bought item','unitPrice'=>41.19,'lineTotal'=>41.19,'metadata'=>['private'=>'PRIVATE-FULFILLMENT']]]);
    $store->savePaymentTransaction(['orderId'=>$order['id'],'amount'=>41.19,'status'=>'paid','last4'=>'1111','payload'=>['private'=>'PRIVATE-PROVIDER-PAYLOAD']]);
    $store->queueMemberMail($member['id'],$member['email'],'PRIVATE-SUBJECT','account_notice','PRIVATE-MAIL-BODY',['private'=>'PRIVATE-MAIL-META']);
    $store->recordMemberEvent($member['id'],'fixture.event',['private'=>'PRIVATE-EVENT-CONTEXT']);
    $marketing=new MarketingStore($root);$marketing->subscribe($member['email'],'Privacy member','fixture',true);$marketing->subscribe($other['email'],'Other member','fixture',true);
    $marketing->update(static function(array &$data)use($member):void{$data['campaign_queue'][]=['id'=>'privacy-delivery','campaign_id'=>'fixture-campaign','contact_id'=>$data['contacts'][0]['id'],'email'=>$member['email'],'status'=>'sent','created_at'=>gmdate('c'),'message_id'=>'PRIVATE-CORRELATION','claim_token'=>'PRIVATE-CLAIM'];});
    if($mysql){$storage=new MarketingStorage($root);$storage->migrate($storage->status()['plan']);$assert($storage->status()['mode']==='mysql','Marketing storage did not use real authoritative SQL.');}
    $notice=new App\Core\NotificationService($config,$store,null,$private);$notice->enqueue('renewal','order:'.$order['id'],$member['id'],['kind'=>'order','object_id'=>$order['id']]);
    $provider=new App\Core\MailProviderService($config,$store,$private);$vault=new SecureValue($config);
    $normalize=new ReflectionMethod($provider,'normalizeEvent');$event=$normalize->invoke($provider,['sg_event_id'=>'privacy-source-event','event'=>'delivered','timestamp'=>time(),'email'=>$member['email'],'smtp-id'=>'<vvx.'.str_repeat('e',64).'@mail.velvet-vixxen.invalid>']);
    $private->update('mail_provider',static function(array &$data)use($event):void{$data=['schema'=>1,'settings'=>['enabled'=>false,'public_key'=>'','revision'=>0],'events'=>[$event['id']=>$event]];});
    $protection=new App\Core\ProtectionRecords($config,$private);$report=$protection->report(['category'=>'privacy','label'=>'My report','name'=>'Privacy member','email'=>$member['email'],'description'=>'My report text','url'=>'','accurate'=>true],'privacy-fixture',$member);
    $private->update('protection',static function(array &$data)use($report,$vault):void{$row=&$data['cases'][$report['id']];$row['hold']=true;$row['targets']=[['kind'=>'album','id'=>'privacy-fixture-album']];$row['status']='taken_down';$row['events'][]=['id'=>bin2hex(random_bytes(16)),'at'=>gmdate('c'),'type'=>'takedown','actor_id'=>'owner','message_encrypted'=>$vault->encrypt('PRIVATE-SAFETY-EVIDENCE'),'public_message_encrypted'=>$vault->encrypt('Your report was accepted.')];});
    $review=$service->review($request['id'],$owner,$recent);
    $reject(fn()=>$service->approve($request['id'],$review['request']['revision'],str_repeat('0',64),[],$owner,$recent),'Stale inventory approval succeeded.',409);
    $reject(fn()=>$service->approve($request['id'],$review['request']['revision'],$review['inventory_revision'],[],['id'=>$other['id'],'email'=>$other['email']],$recent),'A member approved a request.',403);
    $reject(fn()=>$service->approve($request['id'],$review['request']['revision'],$review['inventory_revision'],[],$owner,static function():void{throw new RuntimeException('Recent authentication required.',428);}), 'Missing recent owner authentication succeeded.',428);
    $service->approve($request['id'],$review['request']['revision'],$review['inventory_revision'],[],$owner,$recent);
    $approvedJournal=$private->read('member_privacy');
    $private->update('member_privacy',static function(array &$data)use($request):void{unset($data['requests'][$request['id']]['approval']['owner']['stamp']);});
    $reject(fn()=>$service->run(1),'A durable owner approval missing its security stamp was processed.');
    $private->update('member_privacy',static function(array &$data)use($approvedJournal,$request):void{$data=$approvedJournal;$data['requests'][$request['id']]['approval']=null;});
    $reject(fn()=>$service->run(1),'An approved durable request without approval authority was processed.');
    $private->update('member_privacy',static function(array &$data)use($approvedJournal):void{$data=$approvedJournal;});
    $reject(fn()=>$service->cancel($request['id'],$member['id'],$token,'Privacy-Member-781!',true,$review['request']['revision']),'Approved work was canceled.',409);
    $run=$service->run(1);
    $assert($run['completed']===1&&$run['held']===0,'Approved export did not complete.');
    $bytes=$service->download($request['id'],$member['id'],$token);$zipPath=$root.'/export-check.zip';file_put_contents($zipPath,$bytes);$zip=new ZipArchive();$assert($zip->open($zipPath,ZipArchive::CHECKCONS)===true,'Downloaded export was not a valid ZIP.');$json=$zip->getFromName('member-data.json');$export=json_decode($json,true,512,JSON_THROW_ON_ERROR);
    $assert($zip->numFiles===4&&count($export['messages'])===3&&count($export['bookingRequests'])===1&&count($export['attachments'])===3,'Archive scope or visible attachment count was wrong.');
    $assert(!str_contains($json,'PRIVATE-')&&!str_contains($json,$token)&&!str_contains($json,'passwordHash')&&!str_contains($json,'receipt_hash')&&!str_contains($json,'recipient_ciphertext')&&!str_contains($json,'UNRELATED'),'An export included internal fields, credentials, guest data or another member.');
    $assert($export['orders'][0]['grandTotalMinor']===4119&&$export['reports'][0]['description']==='My report text'&&count($export['mailProviderFeedback'])===1&&count($export['optionalNotifications'])===1,'Export omitted allowed member facts.');$zip->close();
    $reject(fn()=>$service->download($request['id'],$other['id'],$otherToken),'Another member downloaded the archive.',404);
    $saved=$private->read('member_privacy')['requests'][$request['id']]['archive'];$assert($saved['expires_at']-$saved['created_at']===604800&&hash('sha256',$bytes)===$saved['sha256'],'Archive expiry or saved byte hash was incorrect.');
    $path=$root.'/storage/private/member-exports/'.$request['id'].'.zip';file_put_contents($path,substr($bytes,0,-1).'!');$reject(fn()=>$service->download($request['id'],$member['id'],$token),'Changed archive bytes were accepted.');file_put_contents($path,$bytes);
    $erase=$service->request('erase',$member['id'],$token,'Privacy-Member-781!',true);$review=$service->review($erase['id'],$owner,$recent);$retention=['reason'=>'Retain transaction facts and active safety decisions for documented operator review.','review_on'=>gmdate('Y-m-d',time()+86400*30)]+array_fill_keys(Privacy::RETENTION_CHECKS,true);
    $reject(fn()=>$service->approve($erase['id'],$review['request']['revision'],$review['inventory_revision'],[],$owner,$recent),'Erasure bypassed retention review.');
    $service->approve($erase['id'],$review['request']['revision'],$review['inventory_revision'],$retention,$owner,$recent);
    $otherBefore=$store->privacyCoreData($other['id']);$otherContact=array_values(array_filter($marketing->all()['contacts'],static fn(array $r):bool=>$r['email']===$other['email']));
    foreach(Privacy::STAGES as $index=>$stage){$run=$service->run(1);$assert($run['processed']===1&&$run['held']===0,'Erasure stage failed: '.$stage);$row=$private->read('member_privacy')['requests'][$erase['id']];$assert($row['stage']===$index+1&&isset($row['completed_stages'][$stage]),'The durable stage checkpoint was not saved: '.$stage);if($stage==='freeze')$reject(fn()=>$service->download($request['id'],$member['id'],$token),'Frozen identity downloaded an old export.',403);}
    $after=$private->read('member_privacy')['requests'][$erase['id']];$assert($after['status']==='complete'&&$after['cleanup']===[]&&$after['archive_cleanup']===[],'Completed removal kept its temporary cleanup manifest.');
    $assert(!is_file($path)&&!is_file($root.'/storage/private/communications/member-visible.txt')&&!is_file($root.'/storage/private/communications/staff-secret.txt')&&is_file($root.'/storage/private/communications/other-shared.txt'),'Private file cleanup lost a shared file or retained an owned file.');
    $assert($store->privacyCoreData($other['id'])===$otherBefore&&$store->bookingRequest($guest['id'])['details']==='UNRELATED-GUEST','Erasure changed another member or unlinked guest.');
    $assert($notice->privacyRows($member['id'])===[]&&$provider->privacyRows(hash('sha256',$member['email']))[0]['recipient_ciphertext']===null,'Notifications or encrypted provider recipient survived erasure.');
    $assert(array_values(array_filter($marketing->all()['contacts'],static fn(array $r):bool=>$r['email']===$other['email']))===$otherContact,'Another newsletter contact changed.');
    $reject(fn()=>$marketing->subscribe($member['email'],'RECREATE','late',true),'Erased email was resubscribed.',409);
    $reject(fn()=>$notice->enqueue('renewal','order:'.$order['id'],$member['id'],['kind'=>'order','object_id'=>$order['id']]),'Late notification recreated a job.');
    $marketing->update(static function(array &$d)use($member):void{$d['contacts'][]=['id'=>'late-contact','email'=>$member['email'],'name'=>'RECREATE'];$d['suppressions'][]=['email'=>$member['email'],'reason'=>'RECREATE'];unset($d['settings']['member_privacy_erased_emails']);});
    $assert(!str_contains(json_encode($marketing->all()),'RECREATE')&&$marketing->emailSuppressed($member['email']),'A delayed direct marketing/provider write defeated the independent tombstone.');
    $retained=$protection->snapshot()['cases'][$report['id']];$assert($retained['hold']===true&&$retained['status']==='taken_down'&&$retained['reporter_member_id']===null&&$retained['name_encrypted']===''&&$vault->decrypt($retained['events'][1]['message_encrypted'])==='PRIVATE-SAFETY-EVIDENCE','Safety hold/evidence was dropped or reporter identity survived.');
    $reject(fn()=>$protection->publicReview($report['id'],$report['access_key']),'Old report receipt still accessed an anonymized report.',403);
    $assert($service->run(10)['processed']===0,'Completed stages ran again.');
    echo json_encode(['ok'=>true,'assertions'=>$checks,'mode'=>$mysql?'mysql':'file','mysql_version'=>$pdo?->query('SELECT VERSION()')->fetchColumn(),
        'private_archive_verified'=>true,'ten_stages_durable'=>true,'other_members_unchanged'=>true,'guest_records_unchanged'=>true,'safety_holds_retained'=>true,'tombstones_enforced'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}finally{if($owns&&$pdo)vvx_installer_fixture_reset_database($pdo,true);vvx_installer_fixture_remove($root);}
