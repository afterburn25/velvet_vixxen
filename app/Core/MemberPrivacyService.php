<?php
declare(strict_types=1);

namespace App\Core;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Reviewed privacy requests. Each durable stage is idempotent across a lost process or journal commit. */
final class MemberPrivacyService
{
    public const STAGES = ['freeze','attribution','core','communications','files','newsletter','notifications','provider','protection','complete'];
    public const RETENTION_CHECKS = ['financial_reviewed','safety_reviewed','backups_reviewed','billing_reviewed','open_orders_reviewed','guest_requests_reviewed'];
    private readonly PrivateState $state;
    private readonly Store $store;
    private readonly MemberPrivacyDomains $domains;
    private readonly MemberPrivacyArchive $archives;
    private readonly string $root;
    private readonly ?Closure $afterStage;

    public function __construct(private readonly Config $config, ?Store $store = null, ?PrivateState $state = null, ?callable $afterStage = null)
    {
        $this->root = dirname($config->path(), 2);
        $this->store = $store ?? new Store($config);
        $this->state = $state ?? new PrivateState($config, $this->root);
        $this->domains = new MemberPrivacyDomains($config, $this->store, $this->state);
        $this->archives = new MemberPrivacyArchive($this->root);
        // Internal host diagnostics/tests only. HTTP and CLI accept no observer or caller-supplied clock.
        $this->afterStage = $afterStage === null ? null : Closure::fromCallable($afterStage);
    }

    public static function revision(array $value): string { return Store::privacyDataRevision($value); }

    private function normalized(array $data): array
    {
        if ($data === []) return ['schema'=>1,'requests'=>[]];
        if (($data['schema'] ?? null) !== 1 || !is_array($data['requests'] ?? null) || count($data['requests']) > 10000) throw new RuntimeException('The private request journal needs recovery.');
        foreach ($data['requests'] as $id => $row) {
            if (!is_array($row) || !is_string($id) || preg_match('/\A[a-f0-9]{32}\z/D',$id)!==1 || ($row['id']??'')!==$id
                || !is_string($row['member_id']??null) || preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D',$row['member_id'])!==1
                || !in_array($row['kind']??'', ['export','erase'],true) || !in_array($row['status']??'', ['requested','approved','processing','held','complete','canceled','declined','expired','revoked'],true)
                || !is_string($row['identity_hash']??null) || preg_match('/\A[a-f0-9]{64}\z/D',$row['identity_hash'])!==1
                || !is_string($row['email_hash']??null) || preg_match('/\A[a-f0-9]{64}\z/D',$row['email_hash'])!==1
                || !is_int($row['created_at']??null) || !is_int($row['updated_at']??null) || !is_int($row['stage']??null) || $row['stage']<0 || $row['stage']>10
                || !is_array($row['completed_stages']??null) || !is_array($row['cleanup']??null) || !is_array($row['archive_cleanup']??null)
                || !is_array($row['responses']??null) || !array_key_exists('approval',$row) || !array_key_exists('archive',$row)) throw new RuntimeException('A private request record needs recovery.');
            if (in_array($row['status'], ['approved','processing'], true) && !is_array($row['approval'])) throw new RuntimeException('A private approval record needs recovery.');
            if ($row['approval']!==null) {
                $approval=$row['approval'];$owner=is_array($approval)?($approval['owner']??null):null;
                if (!is_array($approval) || !is_string($approval['token']??null) || preg_match('/\A[a-f0-9]{32}\z/D',$approval['token'])!==1
                    || !is_array($owner) || !is_string($owner['id']??null) || preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D',$owner['id'])!==1
                    || !is_string($owner['email']??null) || !EmailAddress::isValid($owner['email'])
                    || !is_string($owner['stamp']??null) || preg_match('/\A[a-f0-9]{64}\z/D',$owner['stamp'])!==1
                    || !is_int($approval['approved_at']??null) || $approval['approved_at']<1) throw new RuntimeException('A private approval record needs recovery.');
                foreach (['request_revision','inventory_revision','core_revision'] as $field) if (!is_string($approval[$field]??null)
                    || preg_match('/\A[a-f0-9]{64}\z/D',$approval[$field])!==1) throw new RuntimeException('A private approval record needs recovery.');
                if (!array_key_exists('retention',$approval) || ($row['kind']==='erase'&&!is_array($approval['retention']))) throw new RuntimeException('A private retention review needs recovery.');
            }
        }
        return $data;
    }

    private function mutate(callable $operation): mixed
    {
        return $this->state->update('member_privacy',function(array &$data)use($operation):mixed{
            $data=$this->normalized($data);$result=$operation($data);$this->normalized($data);return $result;
        });
    }

    /** Live member + live durable device + current verified identity, without trusting a cached user array. */
    public function currentMember(string $memberId,string $token):array
    {
        if ($memberId==='' || $token==='') throw new RuntimeException('Sign in again to manage account privacy.',403);
        $fresh=new Store(new Config($this->config->path()));
        $session=$fresh->memberSession($token);$member=$fresh->member($memberId);
        if (!$session || !$member || (string)($session['memberId']??'')!==$memberId || ($member['status']??'')!=='active'
            || empty($member['emailVerifiedAt']) || !empty($member['deletedAt']) || isset($member['preferences']['_privacy_erasure'])) throw new RuntimeException('Sign in again to manage account privacy.',403);
        if ($fresh->mode()==='mysql') $verified=$fresh->databaseMemberVerificationIsCurrent($memberId);
        else { $when=strtotime((string)$member['emailVerifiedAt']);$verified=$when!==false&&$when>0&&$when<=time(); }
        if (!$verified || $fresh->memberSession($token)!==$session) throw new RuntimeException('Sign in again to manage account privacy.',403);
        return $member;
    }

    private function identity(array $member):string
    {
        $material=[(string)$member['id'],EmailAddress::normalize((string)$member['email']),(string)($member['passwordHash']??''),(string)($member['emailVerifiedAt']??'')];
        return hash_hmac('sha256',json_encode($material,JSON_THROW_ON_ERROR),(string)$this->config->get('security.app_key'));
    }

    private function password(string $memberId,string $token,string $password):array
    {
        $member=$this->currentMember($memberId,$token);
        if ($password==='' || strlen($password)>255 || !password_verify($password,(string)$member['passwordHash'])) throw new RuntimeException('Your current password was not accepted.',403);
        $fresh=$this->currentMember($memberId,$token);
        if (!hash_equals($this->identity($member),$this->identity($fresh))) throw new RuntimeException('Your identity changed. Sign in again.',403);
        return $fresh;
    }

    public function request(string $kind,string $memberId,string $token,string $password,bool $confirmed):array
    {
        if (!in_array($kind,['export','erase'],true) || !$confirmed) throw new InvalidArgumentException('Choose a privacy request and confirm that you understand it.');
        $member=$this->password($memberId,$token,$password);$identity=$this->identity($member);
        return $this->mutate(function(array &$data)use($kind,$memberId,$token,$password,$identity):array{
            $member=$this->password($memberId,$token,$password);
            if(!hash_equals($identity,$this->identity($member)))throw new RuntimeException('Your identity changed. Sign in again.',403);
            $recent=0;
            foreach($data['requests'] as $row)if($row['member_id']===$memberId){
                if($row['created_at']>time()-86400)++$recent;
                if($row['kind']===$kind&&in_array($row['status'],['requested','approved','processing','held'],true))throw new RuntimeException('A request of this type is already open.',409);
            }
            if($recent>=5)throw new RuntimeException('Five privacy requests have already been received in the last 24 hours. Please wait before creating another.',429);
            if(count($data['requests'])>=10000)throw new RuntimeException('The privacy request journal is full. Contact the owner for an assisted request.',503);
            $id=bin2hex(random_bytes(16));$row=['id'=>$id,'member_id'=>$memberId,'kind'=>$kind,'status'=>'requested','identity_hash'=>$identity,
                'email_hash'=>hash('sha256',EmailAddress::normalize((string)$member['email'])),'created_at'=>time(),'updated_at'=>time(),
                'stage'=>0,'completed_stages'=>[],'approval'=>null,'archive'=>null,'cleanup'=>[],'archive_cleanup'=>[],'responses'=>[],'last_error'=>null];
            $data['requests'][$id]=$row;return $this->publicRequest($row);
        });
    }

    public function cancel(string $id,string $memberId,string $token,string $password,bool $confirmed,string $revision):void
    {
        MemberPrivacyArchive::id($id);if(!$confirmed)throw new InvalidArgumentException('Confirm cancellation of this request.');
        $this->password($memberId,$token,$password);
        $this->mutate(function(array &$data)use($id,$memberId,$token,$password,$revision):void{
            $this->password($memberId,$token,$password);$row=$data['requests'][$id]??null;
            if(!$row||$row['member_id']!==$memberId)throw new RuntimeException('Privacy request not found.',404);
            if($row['status']!=='requested'||!hash_equals(self::revision($row),$revision))throw new RuntimeException('This request changed or has already been reviewed.',409);
            $data['requests'][$id]['status']='canceled';$data['requests'][$id]['updated_at']=time();
        });
    }

    public function memberRequests(string $memberId,string $token):array
    {
        $member=$this->currentMember($memberId,$token);$identity=$this->identity($member);$rows=[];
        foreach($this->normalized($this->state->read('member_privacy'))['requests'] as $row)if($row['member_id']===$memberId){
            $public=$this->publicRequest($row);$public['downloadable']=$row['kind']==='export'&&$row['status']==='complete'&&is_array($row['archive'])
                &&($row['archive']['expires_at']??0)>time()&&hash_equals($row['identity_hash'],$identity);
            $rows[]=$public;
        }
        usort($rows,static fn(array $a,array $b):int=>$b['created_at']<=>$a['created_at']);$this->currentMember($memberId,$token);return $rows;
    }

    private function publicRequest(array $row):array
    {
        return MemberPrivacyProjection::fields($row,['id','kind','status','created_at','updated_at','stage','completed_stages','responses','last_error'])
            +['revision'=>self::revision($row),'expires_at'=>$row['archive']['expires_at']??null];
    }

    private function owner(array $identity):array
    {
        if(!is_scalar($identity['id']??null)||!is_string($identity['email']??null))throw new RuntimeException('Current owner authentication is required.',403);
        $owner=(new Store(new Config($this->config->path())))->findAdminByEmail($identity['email']);
        if(!$owner||empty($owner['is_active'])||($owner['role']??'')!=='owner'||(string)$owner['id']!==(string)$identity['id'])throw new RuntimeException('Current owner authentication is required.',403);
        $stamp=hash('sha256',json_encode([(string)$owner['id'],strtolower($owner['email']),$owner['password_hash'],$owner['role'],(bool)$owner['is_active']],JSON_THROW_ON_ERROR));
        if(isset($identity['stamp'])&&!hash_equals($identity['stamp'],$stamp))throw new RuntimeException('The approving owner changed. A current owner must review and approve resumption.',403);
        return ['id'=>(string)$owner['id'],'email'=>(string)$owner['email'],'stamp'=>$stamp];
    }

    public function ownerRequests(array $owner):array
    {
        $this->owner($owner);$rows=[];
        foreach($this->normalized($this->state->read('member_privacy'))['requests'] as $row){
            $member=$this->store->member($row['member_id']);
            $rows[]=$this->publicRequest($row)+['member_id'=>$row['member_id'],'member_name'=>$member['displayName']??'Unavailable member','retention'=>$row['approval']['retention']??null];
        }
        usort($rows,static fn(array $a,array $b):int=>$b['created_at']<=>$a['created_at']);$this->owner($owner);return $rows;
    }

    private function inventory(string $memberId,bool $files=true,?string $emailHash=null):array
    {
        $core=$this->store->privacyCoreData($memberId);$domains=$this->domains->snapshot($core['member'],$emailHash);$cleanup=[];
        if($files){
            foreach(array_merge($core['messageAttachments'],$core['requestAttachments']) as $attachment){
                $path=(string)($attachment['filePath']??'');
                if(isset($cleanup[$path]))continue;
                $handle=PrivateCommunicationFile::open($this->root,$attachment);
                try{$hash=hash_init('sha256');$size=hash_update_stream($hash,$handle);$sha=hash_final($hash);}finally{fclose($handle);}
                if($size!==(int)$attachment['fileSize'])throw new RuntimeException('An attachment changed. Review its current version.',409);
                $cleanup[$path]=['filePath'=>$path,'fileSize'=>$size,'sha256'=>$sha];
            }
        }
        $counts=[];foreach($core as $key=>$rows)if($key!=='member')$counts[$key]=count($rows);
        foreach($domains as $key=>$rows)$counts[$key]=count($rows);
        return ['core'=>$core,'domains'=>$domains,'cleanup'=>array_values($cleanup),'counts'=>$counts,
            'core_revision'=>self::revision($core),'revision'=>self::revision([$core,$domains,array_values($cleanup)])];
    }

    public function review(string $id,array $owner,callable $recent):array
    {
        MemberPrivacyArchive::id($id);$this->owner($owner);$recent();
        $row=$this->normalized($this->state->read('member_privacy'))['requests'][$id]??null;
        if(!$row)throw new RuntimeException('Privacy request not found.',404);
        $inventory=$this->inventory($row['member_id'],$row['stage']===0,$row['email_hash']);
        $this->owner($owner);$recent();
        return ['request'=>$this->publicRequest($row),'member'=>MemberPrivacyProjection::fields($inventory['core']['member'],['id','displayName','email','status']),
            'counts'=>$inventory['counts'],'inventory_revision'=>$inventory['revision'],'retention'=>$row['approval']['retention']??null,
            'has_open_orders'=>count(array_filter($inventory['core']['orders'],static fn(array $r):bool=>in_array($r['status']??'',['pending','processing'],true)))>0
                || count(array_filter($inventory['core']['paymentJournal']??[],static fn(array $r):bool=>($r['kind']==='operation'&&!in_array($r['state'],['applied','declined','ignored'],true))||($r['kind']==='effect'&&!in_array($r['state'],['done','skipped'],true))||($r['kind']==='event'&&in_array($r['state'],['received','held'],true))))>0,
            'has_recurring_billing'=>count(array_filter($inventory['core']['subscriptions'],static fn(array $r):bool=>!empty($r['providerSubscriptionId'])&&in_array($r['status']??'',['active','trialing','past_due'],true)))>0
                || count(array_filter($inventory['core']['recurringAgreements']??[],static fn(array $r):bool=>$r['remoteId']!==null && !$r['cancelAtPeriodEnd']))>0
                || count(array_filter($inventory['core']['recurringJournal']??[],static fn(array $r):bool=>!in_array($r['state'],RecurringContract::TERMINAL,true)))>0,
            'has_safety_holds'=>count(array_filter($inventory['domains']['cases'],static fn(array $r):bool=>!empty($r['hold'])))>0];
    }

    private function retention(array $input):array
    {
        $reason=$input['reason']??null;$until=$input['review_on']??null;
        if(!is_string($reason)||strlen(trim($reason))<10||strlen($reason)>1500||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$reason))throw new InvalidArgumentException('Record a retention reason of 10–1,500 characters, without account secrets.');
        $date=is_string($until)?\DateTimeImmutable::createFromFormat('!Y-m-d',$until,new \DateTimeZone(date_default_timezone_get())):false;
        if(!$date||$date->format('Y-m-d')!==$until||$until<=date('Y-m-d')||$until>date('Y-m-d',strtotime('+10 years')))throw new InvalidArgumentException('Choose a future retention review date within ten years in the site timezone.');
        foreach(self::RETENTION_CHECKS as $key)if(($input[$key]??null)!==true)throw new InvalidArgumentException('Complete every financial, billing, guest, safety and backup review confirmation.');
        return ['reason'=>trim($reason),'review_on'=>$until]+array_fill_keys(self::RETENTION_CHECKS,true);
    }

    public function approve(string $id,string $requestRevision,string $inventoryRevision,array $input,array $owner,callable $recent):void
    {
        MemberPrivacyArchive::id($id);$authority=$this->owner($owner);$recent();
        $row=$this->normalized($this->state->read('member_privacy'))['requests'][$id]??null;
        if(!$row||!in_array($row['status'],['requested','held','approved'],true)||!hash_equals(self::revision($row),$requestRevision))throw new RuntimeException('This request changed. Open its current review.',409);
        $inventory=$this->inventory($row['member_id'],$row['stage']===0,$row['email_hash']);
        if(!hash_equals($inventory['revision'],$inventoryRevision))throw new RuntimeException('The account inventory changed. Open its current review.',409);
        if($row['stage']===0&&!hash_equals($row['identity_hash'],$this->identity($inventory['core']['member'])))throw new RuntimeException('The member identity changed. Ask the member to cancel and create a fresh request.',409);
        $retention=$row['kind']==='erase'?$this->retention($input):null;
        $this->mutate(function(array &$data)use($id,$requestRevision,$inventory,$retention,$authority,$recent):void{
            $this->owner($authority);$recent();$row=$data['requests'][$id]??null;
            if(!$row||!hash_equals(self::revision($row),$requestRevision))throw new RuntimeException('This request changed. Review it again.',409);
            // Re-read at the approval boundary; no submitted counts or identity are trusted.
            $fresh=$this->inventory($row['member_id'],$row['stage']===0,$row['email_hash']);
            if(!hash_equals($inventory['revision'],$fresh['revision']))throw new RuntimeException('The account inventory changed during approval.',409);
            $row['approval']=['token'=>bin2hex(random_bytes(16)),'owner'=>$authority,'approved_at'=>time(),'request_revision'=>$requestRevision,
                'inventory_revision'=>$inventory['revision'],'core_revision'=>$inventory['core_revision'],'retention'=>$retention];
            $row['status']='approved';$row['updated_at']=time();$row['last_error']=null;
            if($row['stage']===0)$row['cleanup']=$inventory['cleanup'];
            $data['requests'][$id]=$row;
        });
    }

    public function respond(string $id,string $revision,string $message,bool $decline,array $owner,callable $recent):void
    {
        MemberPrivacyArchive::id($id);$this->owner($owner);$recent();$message=trim($message);
        if($message===''||strlen($message)>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$message))throw new InvalidArgumentException('Enter a response of 2,000 characters or fewer.');
        $this->mutate(function(array &$data)use($id,$revision,$message,$decline,$owner,$recent):void{
            $this->owner($owner);$recent();$row=$data['requests'][$id]??null;
            if(!$row||!hash_equals(self::revision($row),$revision)||$row['stage']!==0||!in_array($row['status'],['requested','held','approved'],true))throw new RuntimeException('This request has changed or processing has started.',409);
            if(count($row['responses'])>=20)throw new RuntimeException('This request has reached its response limit.');
            $row['responses'][]=['at'=>time(),'text'=>$message];$row['updated_at']=time();
            if($decline){$row['status']='declined';$row['approval']=null;$row['cleanup']=[];}
            $data['requests'][$id]=$row;
        });
    }

    private function assertApproval(array $job):void
    {
        $this->owner($job['approval']['owner']);
        $current=$this->normalized($this->state->read('member_privacy'))['requests'][$job['id']]??null;
        if(!$current||!in_array($current['status'],['approved','processing'],true)||$current['stage']!==$job['stage']
            ||!is_array($current['approval'])||!hash_equals($job['approval']['token'],$current['approval']['token']))throw new RuntimeException('The reviewed privacy approval changed.',409);
    }

    /** Each unit is one durable stage; a worker interruption leaves a replayable checkpoint. */
    public function run(int $limit=10):array
    {
        if($limit<1||$limit>25)throw new InvalidArgumentException('Choose a privacy batch of 1–25 stages.');
        return $this->withWorkerLock(function()use($limit):array{
            $stats=['processed'=>0,'completed'=>0,'held'=>0,'expired'=>0,'busy'=>false];
            $stats['expired']=$this->expireExports();
            for($i=0;$i<$limit;++$i){
                $job=null;foreach($this->normalized($this->state->read('member_privacy'))['requests'] as $row)if(in_array($row['status'],['approved','processing'],true)){$job=$row;break;}
                if($job===null)break;
                try{
                    $this->assertApproval($job);
                    $this->mutate(function(array &$data)use($job):void{
                        $this->owner($job['approval']['owner']);$current=$data['requests'][$job['id']];
                        if(!hash_equals(self::revision($current),self::revision($job)))throw new RuntimeException('The privacy request changed.',409);
                        $data['requests'][$job['id']]['status']='processing';$data['requests'][$job['id']]['updated_at']=time();
                    });
                    $job=$this->normalized($this->state->read('member_privacy'))['requests'][$job['id']];
                    if($job['kind']==='export'){$this->buildExport($job);++$stats['completed'];}
                    else{ $stage=self::STAGES[$job['stage']];$this->eraseStage($job,$stage);if($stage==='complete')++$stats['completed']; }
                    ++$stats['processed'];
                }catch(Throwable $error){
                    $this->mutate(static function(array &$data)use($job,$error):void{
                        $current=$data['requests'][$job['id']]??null;
                        if(!$current||$current['approval']['token']!==$job['approval']['token']||!in_array($current['status'],['approved','processing'],true))return;
                        $data['requests'][$job['id']]['status']='held';$data['requests'][$job['id']]['updated_at']=time();
                        $data['requests'][$job['id']]['last_error']=in_array($error->getCode(),[403,409],true)?'Processing paused because the reviewed identity or inventory changed. A current owner must review resumption.':'Processing paused. The owner must review private storage and the complete export limits before resuming.';
                    });
                    ++$stats['held'];
                }
            }
            return $stats;
        });
    }

    private function buildExport(array $job):void
    {
        $inventory=$this->inventory($job['member_id']);
        if(!hash_equals($job['identity_hash'],$this->identity($inventory['core']['member']))||!hash_equals($job['approval']['inventory_revision'],$inventory['revision']))throw new RuntimeException('The approved account inventory changed.',409);
        $export=['format'=>'velvet-vixxen-account-data','version'=>1,'generatedAt'=>gmdate('c')]+MemberPrivacyProjection::core($inventory['core'])+MemberPrivacyProjection::domains($inventory['domains'],new SecureValue($this->config));
        $attachments=[];$visible=array_fill_keys(array_map('strval',array_column($export['messages'],'id')),true);
        foreach($inventory['core']['messageAttachments'] as $row)if(isset($visible[(string)$row['messageId']]))$attachments[]=$row;
        foreach($inventory['core']['requestAttachments'] as $row)if(($row['uploadedByType']??'')==='member')$attachments[]=$row;
        $authorize=function()use($job):void{
            $this->assertApproval($job);$member=(new Store(new Config($this->config->path())))->member($job['member_id']);
            if(!$member||($member['status']??'')!=='active'||!hash_equals($job['identity_hash'],$this->identity($member)))throw new RuntimeException('The member identity changed.',409);
        };
        $archive=$this->archives->create($job['id'],$export,$attachments,$authorize);
        if(!hash_equals($job['approval']['inventory_revision'],$this->inventory($job['member_id'])['revision']))throw new RuntimeException('The inventory changed while the archive was built.',409);
        if($this->afterStage!==null)($this->afterStage)('export',$job['id']);
        $this->mutate(function(array &$data)use($job,$archive):void{
            $this->owner($job['approval']['owner']);$row=$data['requests'][$job['id']];
            if($row['status']!=='processing'||$row['approval']['token']!==$job['approval']['token'])throw new RuntimeException('The approval changed.',409);
            $row['archive']=$archive;$row['status']='complete';$row['cleanup']=[];$row['updated_at']=time();$data['requests'][$job['id']]=$row;
        });
    }

    private function eraseStage(array $job,string $stage):void
    {
        $authorize=fn():mixed=>$this->assertApproval($job);$authorize();
        if($stage==='freeze'){
            $member=$this->store->member($job['member_id']);$already=($member['preferences']['_privacy_erasure']['request']??'')===$job['id'];
            if(!$already){
                $inventory=$this->inventory($job['member_id']);
                if(!hash_equals($job['approval']['inventory_revision'],$inventory['revision'])||!hash_equals($job['identity_hash'],$this->identity($inventory['core']['member'])))throw new RuntimeException('The approved inventory changed.',409);
            }
            // Archive paths and hashes are checkpointed before any reference disappears.
            $archiveCleanup=[];
            foreach($this->normalized($this->state->read('member_privacy'))['requests'] as $request)if($request['member_id']===$job['member_id']&&$request['kind']==='export'){
                $metadata=$this->archives->checkpoint($request['id']);if($metadata!==null)$archiveCleanup[$request['id']]=$metadata;
            }
            $this->mutate(function(array &$data)use($job,$archiveCleanup):void{
                $this->owner($job['approval']['owner']);
                if($data['requests'][$job['id']]['approval']['token']!==$job['approval']['token'])throw new RuntimeException('The approval changed.',409);
                $data['requests'][$job['id']]['archive_cleanup']=array_replace($data['requests'][$job['id']]['archive_cleanup'],$archiveCleanup);
                foreach($data['requests'] as &$row)if($row['member_id']===$job['member_id']&&$row['kind']==='export'){$row['status']='revoked';$row['updated_at']=time();}
                unset($row);
            });
            $this->store->privacyFreeze($job['member_id'],$job['id'],$job['approval']['core_revision'],$authorize);
        }elseif($stage==='core')$this->store->privacyEraseCore($job['member_id'],$job['id'],$authorize);
        elseif($stage==='communications')$this->store->privacyEraseCommunications($job['member_id'],$authorize);
        elseif($stage==='files'){
            foreach($job['cleanup'] as $file){
                $authorize();
                $this->store->privacyCleanUnreferencedFile($file['filePath'],function()use($file,$authorize):void{
                    $raw=$this->root.'/storage/private/'.substr($file['filePath'],8);
                    if(!file_exists($raw)&&!is_link($raw))return;
                    $path=PrivateCommunicationFile::path($this->root,$file['filePath']);
                    $handle=PrivateCommunicationFile::open($this->root,$file);
                    try{$hash=hash_init('sha256');hash_update_stream($hash,$handle);$sha=hash_final($hash);}finally{fclose($handle);}
                    if(!hash_equals($file['sha256'],$sha))throw new RuntimeException('A cleanup file changed. Review the saved checkpoint.',409);
                    $authorize();if(!@unlink($path))throw new RuntimeException('A private communication file could not be removed.');
                });
            }
            $current=$this->normalized($this->state->read('member_privacy'))['requests'][$job['id']];
            foreach($current['archive_cleanup'] as $id=>$metadata){$authorize();$this->archives->remove($id,$metadata);}
            foreach($this->normalized($this->state->read('member_privacy'))['requests'] as $row)if($row['member_id']===$job['member_id']&&$row['kind']==='export')$this->archives->removeBuilds($row['id']);
        }elseif($stage!=='complete')$this->domains->erase($stage,$job['member_id'],$job['email_hash'],$authorize);
        if($this->afterStage!==null)($this->afterStage)($stage,$job['id']);
        $authorize();
        $this->mutate(function(array &$data)use($job,$stage):void{
            $this->owner($job['approval']['owner']);$row=$data['requests'][$job['id']];
            if($row['status']!=='processing'||$row['stage']!==$job['stage']||$row['approval']['token']!==$job['approval']['token'])throw new RuntimeException('The processing approval changed.',409);
            $row['completed_stages'][$stage]=time();++$row['stage'];$row['updated_at']=time();
            if($stage==='complete'){
                $row['status']='complete';$row['cleanup']=[];$row['archive_cleanup']=[];$row['responses']=[];
                foreach($data['requests'] as &$previous)if($previous['member_id']===$job['member_id']&&$previous['id']!==$row['id']){$previous['responses']=[];$previous['cleanup']=[];$previous['archive_cleanup']=[];$previous['archive']=null;}
                unset($previous);
            }
            $data['requests'][$job['id']]=$row;
        });
    }

    public function download(string $id,string $memberId,string $token):string
    {
        MemberPrivacyArchive::id($id);
        $authorize=function()use($id,$memberId,$token):array{
            $member=$this->currentMember($memberId,$token);$row=$this->normalized($this->state->read('member_privacy'))['requests'][$id]??null;
            if(!$row||$row['member_id']!==$memberId||$row['kind']!=='export'||$row['status']!=='complete'||!is_array($row['archive'])||!hash_equals($row['identity_hash'],$this->identity($member)))throw new RuntimeException('This export is unavailable.',404);
            return $row;
        };
        $row=$authorize();return $this->archives->read($id,$row['archive'],$authorize);
    }

    private function expireExports():int
    {
        $expired=0;
        foreach($this->normalized($this->state->read('member_privacy'))['requests'] as $id=>$row){
            if($row['kind']!=='export'||$row['status']!=='complete'||!is_array($row['archive'])||($row['archive']['expires_at']??PHP_INT_MAX)>time())continue;
            $this->archives->remove($id,$row['archive']);$this->archives->removeBuilds($id);
            $this->mutate(static function(array &$data)use($id,$row):void{if(($data['requests'][$id]['archive']??null)===$row['archive']){$data['requests'][$id]['status']='expired';$data['requests'][$id]['archive']=null;$data['requests'][$id]['updated_at']=time();}});++$expired;
        }
        return $expired;
    }

    private function withWorkerLock(callable $operation):array
    {
        $directory=$this->archives->directory(true);$path=$directory.'/worker.lock';
        if(is_link($path)||(file_exists($path)&&!is_file($path)))throw new RuntimeException('The privacy worker lock needs review.');
        $lock=@fopen($path,'c+b');if($lock===false)throw new RuntimeException('The privacy worker lock is unavailable.');@chmod($path,0600);
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return ['processed'=>0,'completed'=>0,'held'=>0,'expired'=>0,'busy'=>true];}
        try{return $operation();}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
