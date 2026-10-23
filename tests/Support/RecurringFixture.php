<?php
declare(strict_types=1);

/** Independent durable synthetic ledger, never a configured production processor. */
final class RecurringFixtureProvider implements App\Payments\RecurringProviderInterface
{
    public string $mode='success';
    public ?Closure $alter=null;
    public ?Closure $afterAccepted=null;
    public ?Closure $context=null;
    private App\Payments\SandboxPaymentProvider $sandbox;
    public function __construct(private readonly string $path,private readonly App\Core\Store $store){$this->sandbox=new App\Payments\SandboxPaymentProvider();}
    private function boundary():void{$this->store->paymentRequireCallbackBoundary();}
    public function key():string{return 'fixture-recurring';}
    public function label():string{return 'Owned recurring acceptance fixture';}
    public function supportsRecurring():bool{return true;}
    public function accountContext(array $configuration):array{$this->boundary();if($this->context)($this->context)();return ['account'=>$configuration['settings']['account']??hash('sha256','recurring-fixture-account'),'environment'=>$configuration['settings']['environment']??'test'];}
    public function charge(array $order,array $input):array{throw new RuntimeException('Unbound charge is forbidden.');}
    public function refund(array $transaction,float $amount,string $reason=''):array{throw new RuntimeException('Unbound refund is forbidden.');}
    public function chargeBound(array $binding,array $order,array $input):array{$this->boundary();return $this->sandbox->chargeBound($binding,$order,$input);}
    public function refundBound(array $binding,array $transaction,string $reason=''):array{$this->boundary();return $this->sandbox->refundBound($binding,$transaction,$reason);}
    public function lookupOperation(array $binding):array{$this->boundary();throw new RuntimeException('Recurring recovery must use its original recurring binding.');}
    public function parseWebhook(string $body,string $signature,string $secret):array{$this->boundary();return $this->sandbox->parseWebhook($body,$signature,$secret);}
    public function parseRecurringWebhook(string $body,string $signature,string $secret):?array{$this->boundary();return $this->sandbox->parseRecurringWebhook($body,$signature,$secret);}
    public function executeRecurringCommand(array $binding):array{return $this->attempt($binding);}
    public function collectRecurringCycle(array $binding):array{return $this->attempt($binding);}
    public function lookupRecurringCommand(array $binding):array{return $this->lookup($binding);}
    public function lookupRecurringCycle(array $binding):array{return $this->lookup($binding);}
    public function ledger():array{return is_file($this->path)?json_decode(file_get_contents($this->path),true,64,JSON_THROW_ON_ERROR):['attempts'=>[],'lookups'=>[],'results'=>[]];}
    public function seed(array $result):void{$ledger=$this->ledger();$ledger['results'][$result['binding']['recordId']]=$result;$this->save($ledger);}
    private function save(array $ledger):void{
        $temp=$this->path.'.tmp';$f=fopen($temp,'wb');chmod($temp,0600);fwrite($f,json_encode($ledger,JSON_THROW_ON_ERROR));fflush($f);fsync($f);fclose($f);
        if(!rename($temp,$this->path))throw new RuntimeException('Owned provider ledger commit failed.');
    }
    private function attempt(array $binding):array{
        $this->boundary();App\Core\RecurringContract::binding($binding);$ledger=$this->ledger();$ledger['attempts'][]=$binding['recordId'];
        $outcome=match($this->mode){'pending'=>'pending','decline'=>'declined',default=>'succeeded'};
        $result=['schema'=>1,'binding'=>$binding,'outcome'=>$outcome,'remoteId'=>$binding['remoteId']??($outcome==='succeeded'?'fixture_agreement_'.substr($binding['agreementId'],4):null),
            'providerRevision'=>$binding['providerRevision']+($outcome==='succeeded'?1:0),'transactionId'=>$binding['action']==='cycle'&&$outcome==='succeeded'?'fixture_cycle_'.substr($binding['recordId'],5):null,
            'observedAt'=>time(),'cancelAtPeriodEnd'=>$binding['cancelAtPeriodEnd']];
        $ledger['results'][$binding['recordId']]=$result;$this->save($ledger);
        if($this->afterAccepted)($this->afterAccepted)($binding,$result);
        if($this->mode==='kill'){posix_kill(getmypid(),SIGKILL);exit(98);}
        if($this->mode==='timeout')throw new RuntimeException('Owned provider accepted before response loss.');
        return $this->alter?($this->alter)($result):$result;
    }
    private function lookup(array $binding):array{
        $this->boundary();$ledger=$this->ledger();$ledger['lookups'][]=$binding['recordId'];$this->save($ledger);
        return $ledger['results'][$binding['recordId']]??['schema'=>1,'binding'=>$binding,'outcome'=>'unknown','remoteId'=>$binding['remoteId'],'providerRevision'=>$binding['providerRevision'],
            'transactionId'=>null,'observedAt'=>time(),'cancelAtPeriodEnd'=>$binding['cancelAtPeriodEnd']];
    }
}

function recurring_fixture_service(App\Core\Config $config):array
{
    $store=new App\Core\Store($config);$registry=new App\Payments\PaymentProviderRegistry();
    $provider=new RecurringFixtureProvider(dirname($config->path(),2).'/recurring-provider-ledger.json',$store);$registry->register($provider);
    return [$store,new App\Core\CommerceService($store,$config,new App\Core\MailService($config,$store),$registry),$provider,$registry];
}

/** Simulate elapsed clock time only in an exclusively owned disposable fixture. */
function recurring_fixture_elapsed(App\Core\Store $store,array $agreement,int $end,?PDO $pdo,int $periodDays=28):array
{
    if(!is_file(ROOT_PATH.'/.payment-foundation-fixture'))throw new RuntimeException('Owned fixture marker required.');
    $agreement['periodEnd']=$end;$agreement['periodStart']=$end-86400*App\Core\RecurringContract::integer($periodDays,1,120);
    if($pdo){
        $pdo->beginTransaction();
        try{
            $pdo->prepare('UPDATE recurring_agreements SET record_json=? WHERE id=?')->execute([json_encode($agreement,JSON_THROW_ON_ERROR),$agreement['id']]);
            $pdo->prepare('UPDATE subscriptions SET current_period_start=?,current_period_end=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',$agreement['periodStart']),gmdate('Y-m-d H:i:s',$end),$agreement['subscriptionId']]);
            $item=$store->orderItems($agreement['orderId'])[0];$item['metadata']['currentPeriodEnd']=gmdate('c',$end);
            $pdo->prepare('UPDATE order_items SET metadata_json=? WHERE id=?')->execute([json_encode($item['metadata'],JSON_THROW_ON_ERROR),$item['id']]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }else $store->mutateFileStore(static function(array &$all)use($agreement,$end):void{
        $all['recurringAgreements'][$agreement['id']]=$agreement;
        $all['subscriptions'][$agreement['subscriptionId']]['currentPeriodStart']=gmdate('c',$agreement['periodStart']);
        $all['subscriptions'][$agreement['subscriptionId']]['currentPeriodEnd']=gmdate('c',$end);
        foreach($all['orderItems'][$agreement['orderId']] as &$item)if((string)$item['id']===$agreement['itemId'])$item['metadata']['currentPeriodEnd']=gmdate('c',$end);unset($item);
    });
    return $agreement;
}

function recurring_fixture_predecessor(string $id):string
{
    $hex=substr($id,4);
    for($i=63;$i>=0;--$i){$value=hexdec($hex[$i]);if($value>0){$hex[$i]=dechex($value-1);break;}$hex[$i]='f';}
    return 'agr_'.$hex;
}
