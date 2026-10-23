<?php
declare(strict_types=1);

namespace App\Core;

use App\Payments\PaymentContract;
use App\Payments\RecurringProviderInterface;
use RuntimeException;
use Throwable;

/** Durable lifecycle transport and reducers; invoked only by explicit actions or bounded workers. */
trait RecurringCommerceTrait
{
    private function prepareRecurringMembership(array $order,array $item,array $subscription,array $terms): void
    {
        $provider=$this->providers->get($order['providerKey']);
        if (!$provider instanceof RecurringProviderInterface || $terms['terms']['interval']==='one_time') return;
        $payment=$this->paymentEffectContext['data']['binding']??null;
        if (!is_array($payment) || $payment['kind']!=='charge' || $payment['orderId']!==(string)$order['id']) throw new RuntimeException('Original recurring payment scope is unavailable.');
        $scope=array_intersect_key($payment,array_flip(['installation','provider','account','environment']));
        $id='agr_'.hash('sha256',$payment['operationId'].':'.$item['id']);$now=time();
        $agreement=$this->store->saveRecurringAgreement(['schema'=>1,'id'=>$id,'memberId'=>(string)$order['memberId'],
            'subscriptionId'=>(string)$subscription['id'],'orderId'=>(string)$order['id'],'itemId'=>(string)$item['id'],
            'scope'=>$scope,'scopeHash'=>PurchasedTerms::hash($scope),'remoteId'=>null,'terms'=>$terms,'termsHash'=>$terms['hash'],
            'periodStart'=>strtotime($subscription['currentPeriodStart']),'periodEnd'=>strtotime($subscription['currentPeriodEnd']),
            'anchorDay'=>(int)gmdate('j',strtotime($terms['terms']['trialDays']>0?$subscription['currentPeriodEnd']:$subscription['currentPeriodStart'])),'state'=>'pending','cancelAtPeriodEnd'=>false,
            'providerRevision'=>0,'revision'=>1,'erased'=>false,'issue'=>null,'createdAt'=>$now,'updatedAt'=>$now]);
        $this->prepareRecurringCommand($agreement,'activate','activate:'.$id);
    }

    private function recurringCommit(array $agreement,callable $callback,?callable $authorize=null): mixed
    {
        return $this->store->paymentAtomic(static function()use($callback,$authorize):mixed{PaymentOperationService::authorize($authorize);return $callback();},$agreement['memberId']);
    }

    private function recurringChange(array $record,string $state,array $changes=[]): array
    {
        if ($this->store->recurringJournal($record['id'])!==$record) throw new RuntimeException('Recurring request changed while waiting.');
        return $this->store->saveRecurringJournal(array_replace($record,['state'=>$state,'updatedAt'=>max(time(),$record['updatedAt'])],$changes));
    }

    private function recurringUpdate(array $agreement,array $changes): array
    {
        if ($this->store->recurringAgreement($agreement['id'])!==$agreement) throw new RuntimeException('Recurring agreement changed while waiting.');
        return $this->store->saveRecurringAgreement(array_replace($agreement,$changes,['revision'=>$agreement['revision']+1,'updatedAt'=>max(time(),$agreement['updatedAt'])]));
    }

    private function recurringBinding(array $agreement,string $action,string $requestId,string $recordId,?array $payment=null,?int $end=null): array
    {
        return RecurringContract::binding(['schema'=>1,'recordId'=>$recordId,'agreementId'=>$agreement['id'],'scope'=>$agreement['scope'],
            'remoteId'=>$agreement['remoteId'],'termsHash'=>$agreement['termsHash'],'action'=>$action,'requestId'=>$requestId,
            'agreementRevision'=>$agreement['revision'],'providerRevision'=>$agreement['providerRevision'],
            'periodStart'=>$action==='cycle'?$agreement['periodEnd']:$agreement['periodStart'],'periodEnd'=>$end??$agreement['periodEnd'],
            'cancelAtPeriodEnd'=>$action==='cancel','payment'=>$payment]);
    }

    private function recurringNewRecord(array $binding,string $kind,string $dedup): array
    {
        $now=time();return $this->store->saveRecurringJournal(['schema'=>1,'id'=>$binding['recordId'],'agreementId'=>$binding['agreementId'],
            'kind'=>$kind,'dedupHash'=>$dedup,'orderId'=>$binding['payment']['orderId']??null,'state'=>'prepared','binding'=>$binding,
            'result'=>null,'issue'=>null,'createdAt'=>$now,'updatedAt'=>$now]);
    }

    private function prepareRecurringCommand(array $agreement,string $action,string $requestId): array
    {
        PaymentContract::reference($requestId);
        $hash=hash('sha256',$agreement['id'].':'.$requestId);$id='rcmd_'.$hash;
        $old=$this->store->recurringJournal($id);
        if ($old) {
            if ($old['agreementId']!==$agreement['id'] || $old['binding']['action']!==$action) throw new RuntimeException('This request identity is already bound to another action.');
            return $old;
        }
        return $this->recurringNewRecord($this->recurringBinding($agreement,$action,$requestId,$id),'command',$hash);
    }

    private function recurringProvider(array $agreement): array
    {
        $this->store->paymentRequireCallbackBoundary();
        $snapshot=$this->payments()->captureScope($agreement['scope']['provider']);
        $provider=$this->providers->get($agreement['scope']['provider']);
        if (!$provider instanceof RecurringProviderInterface || $snapshot['scope']!==$agreement['scope'] || $snapshot['scope']['environment']==='live') throw new RuntimeException('The approved recurring provider scope is unavailable.');
        return [$provider,$snapshot];
    }

    private function recurringEligible(array $agreement,string $action): void
    {
        if ($action==='cancel') return; // Necessary original cancellation may outlive erasure.
        $this->store->privacyRequireOpenMember($agreement['memberId']);
        $member=$this->store->member($agreement['memberId']);
        if ($agreement['erased'] || ($member['status']??null)!=='active' || empty($member['emailVerifiedAt'])) throw new RuntimeException('The member is not eligible for new recurring actions.');
        if ($action==='cycle') {
            $sub=$this->recurringSubscription($agreement);
            if ($agreement['state']!=='active' || $agreement['cancelAtPeriodEnd'] || $agreement['periodEnd']>time()
                || !in_array($sub['status'],['active','trialing','past_due'],true)
                || strtotime($sub['currentPeriodStart'])!==$agreement['periodStart'] || strtotime($sub['currentPeriodEnd'])!==$agreement['periodEnd']) {
                throw new RuntimeException('Current membership facts do not authorize a new recurring charge.');
            }
        }
    }

    /** Member/owner callers supply current authority; revisions and request IDs are server-form facts. */
    public function requestRecurringCommand(string $memberId,string $subscriptionId,string $action,int $revision,string $requestId,?callable $authorize=null): array
    {
        if (!in_array($action,['cancel','resume'],true)) throw new RuntimeException('Choose cancellation or resumption.');
        return $this->store->withCommerceLock(function()use($memberId,$subscriptionId,$action,$revision,$requestId,$authorize):array{
            PaymentOperationService::authorize($authorize);
            $agreement=$this->store->recurringAgreementForSubscription($subscriptionId);
            if (!$agreement || $agreement['memberId']!==$memberId) throw new RuntimeException('Recurring membership not found.',404);
            $id='rcmd_'.hash('sha256',$agreement['id'].':'.$requestId);$prior=$this->store->recurringJournal($id);
            if ($prior) {
                if ($prior['binding']['action']!==$action || $prior['binding']['agreementRevision']!==$revision) throw new RuntimeException('Conflicting recurring form replay.');
                return $this->recurringProjection($agreement);
            }
            if ($agreement['revision']!==$revision || $agreement['remoteId']===null || $agreement['state']==='pending') throw new RuntimeException('The membership changed. Reload billing before another request.',409);
            $this->recurringEligible($agreement,$action);
            if ($action==='resume' && (!$agreement['cancelAtPeriodEnd'] || $agreement['periodEnd']<=time() || $agreement['state']!=='active')) throw new RuntimeException('Only an unexpired confirmed cancellation can be resumed.');
            $record=$this->recurringCommit($agreement,fn():array=>$this->prepareRecurringCommand($agreement,$action,$requestId),$authorize);
            $this->executeRecurringRecord($record,$authorize);
            return $this->recurringProjection($this->store->recurringAgreement($agreement['id']));
        });
    }

    /** Transport can be called once only, after its attempting marker is durable. */
    private function executeRecurringRecord(array $record,?callable $authorize=null): array
    {
        if ($record['state']!=='prepared') throw new RuntimeException('This recurring request has already been attempted. Check its original outcome.');
        $agreement=$this->store->recurringAgreement($record['agreementId']);
        PaymentOperationService::authorize($authorize);
        try { [$provider,$snapshot]=$this->recurringProvider($agreement); }
        catch (Throwable) { return $this->recurringCommit($agreement,fn():array=>$this->recurringChange($record,'held',['issue'=>'scope_changed']),$authorize); }
        $record=$this->recurringCommit($agreement,function()use($record,$agreement,$snapshot):array{
            $this->payments()->assertCurrentScope($snapshot);$this->recurringEligible($agreement,$record['binding']['action']);
            if ($this->store->recurringAgreement($agreement['id'])!==$agreement) throw new RuntimeException('Stale recurring dispatch.');
            if ($record['kind']==='cycle') {
                $operation=$this->store->paymentJournal($record['binding']['payment']['operationId']);
                $this->payments()->change($operation,'attempting');
            }
            return $this->recurringChange($record,'attempting');
        },$authorize);
        PaymentOperationService::authorize($authorize);
        $this->payments()->assertCurrentScope($snapshot);$this->recurringEligible($this->store->recurringAgreement($agreement['id']),$record['binding']['action']);
        $this->store->paymentRequireCallbackBoundary();
        try { $result=$record['kind']==='command'?$provider->executeRecurringCommand($record['binding']):$provider->collectRecurringCycle($record['binding']); }
        catch (Throwable) { return $this->recurringCommit($agreement,fn():array=>$this->recurringChange($record,'unknown',['issue'=>'transport_unknown']),$authorize); }
        return $this->receiveRecurringResult($record,$result,$authorize);
    }

    public function reconcileRecurringRecord(string $recordId,?callable $authorize=null,?int $revision=null): array
    {
        return $this->store->withCommerceLock(function()use($recordId,$authorize,$revision):array{
            PaymentOperationService::authorize($authorize);
            $record=$this->store->recurringJournal($recordId);
            if (!$record) throw new RuntimeException('Recurring request not found.',404);
            if ($record['kind']==='event') throw new RuntimeException('Check the original recurring request instead of its event receipt.');
            $agreement=$this->store->recurringAgreement($record['agreementId']);
            if ($revision!==null && $agreement['revision']!==$revision) throw new RuntimeException('Recurring membership changed. Reload before checking.',409);
            if (in_array($record['state'],RecurringContract::TERMINAL,true)) return $this->recurringProjection($agreement);
            if ($record['state']==='prepared') return $this->recurringProjection($agreement);
            if ($record['state']==='confirmed') { $this->applyRecurringResult($record,$authorize); return $this->recurringProjection($this->store->recurringAgreement($agreement['id'])); }
            try { [$provider,$snapshot]=$this->recurringProvider($agreement); }
            catch (Throwable) { $this->recurringCommit($agreement,fn():array=>$this->recurringChange($record,'held',['issue'=>'scope_changed']),$authorize); return $this->recurringProjection($agreement); }
            PaymentOperationService::authorize($authorize);$this->payments()->assertCurrentScope($snapshot);
            try { $result=$record['kind']==='command'?$provider->lookupRecurringCommand($record['binding']):$provider->lookupRecurringCycle($record['binding']); }
            catch (Throwable) { $result=null; }
            if ($result===null) $this->recurringCommit($agreement,fn():array=>$this->recurringChange($record,'held',['issue'=>'transport_unknown']),$authorize);
            else $this->receiveRecurringResult($record,$result,$authorize);
            return $this->recurringProjection($this->store->recurringAgreement($agreement['id']));
        });
    }

    private function receiveRecurringResult(array $record,array $raw,?callable $authorize=null): array
    {
        $agreement=$this->store->recurringAgreement($record['agreementId']);
        try {
            $result=RecurringContract::result($raw,$record['binding'],$record['createdAt']);
            [, $snapshot]=$this->recurringProvider($agreement);
            if (in_array($record['result']['outcome']??null,['succeeded','declined'],true)) {
                $before=$record['result'];$incoming=$result;unset($before['observedAt'],$incoming['observedAt']);
                if ($before!==$incoming) throw new RuntimeException('Conflicting recurring evidence.');
                $result=$record['result'];
            }
        } catch (Throwable) {
            if (in_array($record['state'],RecurringContract::TERMINAL,true)) throw new RuntimeException('Conflicting terminal recurring evidence.');
            return $this->recurringCommit($agreement,fn():array=>$this->recurringChange($record,'held',['issue'=>'malformed_result']),$authorize);
        }
        if (in_array($record['state'],RecurringContract::TERMINAL,true)) return $record;
        $record=$this->recurringCommit($agreement,function()use($record,$result,$snapshot):array{
            $this->payments()->assertCurrentScope($snapshot);
            $state=match($result['outcome']){'succeeded','declined'=>'confirmed','pending'=>'pending',default=>'unknown'};
            if ($record['kind']==='cycle' && $state==='confirmed') {
                $payment=$this->store->paymentJournal($record['binding']['payment']['operationId']);
                $evidence=PaymentContract::result(['schema'=>1,'binding'=>$record['binding']['payment'],'outcome'=>$result['outcome'],
                    'transactionId'=>$result['transactionId'],'observedAt'=>$result['observedAt']],$payment['data']['binding'],$payment['createdAt']);
                unset($evidence['display']);$this->payments()->assertEvidence($payment,$evidence);
                $this->payments()->change($payment,'confirmed',['result'=>$evidence,'issue'=>null]);
            }
            return $this->recurringChange($record,$state,['result'=>$result,'issue'=>match($state){'pending'=>'pending_provider','unknown'=>'transport_unknown',default=>null}]);
        },$authorize);
        return $this->applyRecurringResult($record,$authorize);
    }

    private function applyRecurringResult(array $record,?callable $authorize=null): array
    {
        if ($record['state']!=='confirmed') return $record;
        if ($record['kind']==='cycle') {
            $operation=$this->store->paymentJournal($record['binding']['payment']['operationId']);
            $this->applyPaymentOperation($operation,[],null,$authorize);$this->runPaymentEffectsLocked($record['orderId'],$authorize);
            return $this->store->recurringJournal($record['id']);
        }
        $agreement=$this->store->recurringAgreement($record['agreementId']);
        try {
            [, $snapshot]=$this->recurringProvider($agreement);
            return $this->recurringCommit($agreement,function()use($agreement,$record,$snapshot):array{
                $this->payments()->assertCurrentScope($snapshot);$binding=$record['binding'];$result=$record['result'];
                if ($agreement['providerRevision']!==$binding['providerRevision'] || $agreement['periodStart']!==$binding['periodStart'] || $agreement['periodEnd']!==$binding['periodEnd']) throw new RuntimeException('Recurring confirmation is out of order.');
                if ($result['outcome']==='declined') {
                    $this->recurringUpdate($agreement,['issue'=>'provider_declined']);
                    return $this->recurringChange($record,'declined');
                }
                $changes=['remoteId'=>$result['remoteId'],'providerRevision'=>$result['providerRevision'],'cancelAtPeriodEnd'=>$result['cancelAtPeriodEnd'],'issue'=>null];
                if (!$agreement['erased']) {
                    $changes['state']=$agreement['state']==='held'?'held':'active';
                    $sub=$this->recurringSubscription($agreement);
                    $this->store->saveSubscription(array_replace($sub,['providerSubscriptionId'=>$result['remoteId'],'cancelAtPeriodEnd'=>$result['cancelAtPeriodEnd']]));
                }
                $this->recurringUpdate($agreement,$changes);
                return $this->recurringChange($record,'applied');
            },$authorize);
        } catch (PaymentRecoveryAuthorizationException $e) {throw $e;}
        catch (Throwable) {return $this->recurringCommit($agreement,fn():array=>$this->recurringChange($record,'held',['issue'=>'local_effect_failed']),$authorize);}
    }

    private function recurringSubscription(array $agreement): array
    {
        foreach ($this->store->subscriptions($agreement['memberId']) as $sub) if ((string)$sub['id']===$agreement['subscriptionId']) return $sub;
        throw new RuntimeException('Recurring membership reference is unavailable.');
    }

    private function prepareRecurringCycle(array $agreement): array
    {
        $id='rcyc_'.hash('sha256',$agreement['id'].':'.$agreement['periodEnd']);$old=$this->store->recurringJournal($id);
        if ($old) return $old;
        $terms=$agreement['terms']['terms'];$end=RecurringContract::nextEnd($agreement['periodEnd'],$terms['interval'],$terms['intervalCount'],$agreement['anchorDay']);
        $this->recurringEligible($agreement,'cycle');
        return $this->recurringCommit($agreement,function()use($agreement,$terms,$end,$id):array{
            $this->recurringEligible($agreement,'cycle');$member=$this->store->member($agreement['memberId']);
            $order=$this->store->createOrder(['memberId'=>$agreement['memberId'],'status'=>'pending','subtotal'=>$terms['recurringMinor']/100,
                'discountTotal'=>0,'taxTotal'=>0,'grandTotal'=>$terms['recurringMinor']/100,'currency'=>$terms['currency'],'providerKey'=>$agreement['scope']['provider'],
                'idempotencyKey'=>$id,'billingEmail'=>$member['email'],'metadata'=>['renewal'=>true,'subscriptionId'=>$agreement['subscriptionId'],
                    'recurringAgreementId'=>$agreement['id'],'recurringRecordId'=>$id,'periodStart'=>$agreement['periodEnd'],'periodEnd'=>$end,'acceptedTermsHash'=>$agreement['termsHash']]],
                [['itemType'=>'plan_renewal','itemId'=>$terms['itemId'],'title'=>$terms['title'].' renewal','quantity'=>1,'unitPrice'=>$terms['recurringMinor']/100,
                    'discountTotal'=>0,'lineTotal'=>$terms['recurringMinor']/100,'metadata'=>['subscriptionId'=>$agreement['subscriptionId']],'fulfillmentStatus'=>'pending']]);
            $payment=$this->payments()->bindingInScope($order,$agreement['scope'],'charge',$terms['recurringMinor']);
            $this->payments()->prepare($payment,'checkout');
            return $this->recurringNewRecord($this->recurringBinding($agreement,'cycle',$id,$id,$payment,$end),'cycle',substr($id,5));
        });
    }

    /** Called inside the normal financial reducer: cycle, money and access become visible together. */
    private function applyRecurringCycleCore(array $order,bool $paid): void
    {
        $record=$this->store->recurringCycleForOrder((string)$order['id']);if (!$record) return;
        if (in_array($record['state'],RecurringContract::TERMINAL,true)) return;
        if ($record['state']!=='confirmed') throw new RuntimeException('Recurring cycle evidence is not confirmed.');
        $agreement=$this->store->recurringAgreement($record['agreementId']);$binding=$record['binding'];$result=$record['result'];
        if (($paid?'succeeded':'declined')!==$result['outcome']) throw new RuntimeException('Recurring financial evidence conflicts.');
        if (!$agreement['erased']) {
            if ($agreement['revision']!==$binding['agreementRevision'] || $agreement['providerRevision']!==$binding['providerRevision']
                || $agreement['state']!=='active' || $agreement['periodEnd']!==$binding['periodStart'] || $agreement['cancelAtPeriodEnd'] || $agreement['termsHash']!==$binding['termsHash']) throw new RuntimeException('Recurring cycle is stale or conflicts with the paid period.');
            $sub=$this->recurringSubscription($agreement);
            if (!in_array($sub['status'],['active','trialing','past_due'],true) || strtotime($sub['currentPeriodStart'])!==$agreement['periodStart'] || strtotime($sub['currentPeriodEnd'])!==$agreement['periodEnd']) throw new RuntimeException('Current membership access facts need review before recurring fulfillment.');
            if ($paid) {
                $this->store->saveSubscription(array_replace($sub,['status'=>'active','currentPeriodStart'=>gmdate('c',$binding['periodStart']),
                    'currentPeriodEnd'=>gmdate('c',$binding['periodEnd']),'graceEndsAt'=>null]));
                $this->recurringUpdate($agreement,['periodStart'=>$binding['periodStart'],'periodEnd'=>$binding['periodEnd'],
                    'providerRevision'=>$result['providerRevision'],'state'=>'active','issue'=>null]);
            } else {
                $this->store->saveSubscription(array_replace($sub,['status'=>'past_due','graceEndsAt'=>null]));
                $this->recurringUpdate($agreement,['issue'=>'provider_declined']);
            }
        } elseif ($paid && $result['providerRevision']>$agreement['providerRevision']) {
            $this->recurringUpdate($agreement,['providerRevision'=>$result['providerRevision']]);
        }
        $this->recurringChange($record,$paid?'applied':'declined');
    }

    private function dispatchRecurringActivations(string $orderId,?callable $authorize=null): void
    {
        $cursor=null;
        do {
            $page=$this->store->recurringAgreementPage($cursor,100);$cursor=$page['nextCursor'];
            foreach ($page['records'] as $agreement) {
                if ($agreement['orderId']!==$orderId) continue;
                foreach ($this->store->recurringJournalForAgreement($agreement['id']) as $record) if ($record['kind']==='command' && $record['binding']['action']==='activate' && $record['state']==='prepared') {
                    if ($agreement['erased']) $this->recurringCommit($agreement,fn():array=>$this->recurringChange($record,'ignored',['issue'=>'account_closed']),$authorize);
                    else $this->executeRecurringRecord($record,$authorize);
                }
            }
        } while($cursor!==null);
    }

    public function processRecurringAgreements(?string $after=null,int $limit=50): array
    {
        return $this->store->withCommerceLock(function()use($after,$limit):array{
            $page=$this->store->recurringAgreementPage($after,$limit);$stats=['processed'=>0,'renewed'=>0,'expired'=>0,'failed'=>0,'deferred'=>0,'nextCursor'=>$page['nextCursor']];
            foreach ($page['records'] as $agreement) {
                ++$stats['processed'];
                try {
                    $open=array_values(array_filter($this->store->recurringJournalForAgreement($agreement['id']),static fn(array $r):bool=>$r['kind']!=='event' && !in_array($r['state'],RecurringContract::TERMINAL,true)));
                    if ($open) {
                        $record=$open[0];
                        if ($agreement['erased'] && $record['state']==='prepared' && $record['binding']['action']!=='cancel') {
                            $this->recurringCommit($agreement,fn():array=>$this->recurringChange($record,'ignored',['issue'=>'account_closed']));
                            ++$stats['deferred'];continue;
                        }
                        if ($record['state']==='prepared') $this->executeRecurringRecord($record);
                        else $this->reconcileRecurringRecord($record['id']);
                        ++$stats['deferred'];continue;
                    }
                    if ($agreement['erased']) {
                        if ($agreement['remoteId']!==null && !$agreement['cancelAtPeriodEnd']) {
                            $record=$this->recurringCommit($agreement,fn():array=>$this->prepareRecurringCommand($agreement,'cancel','privacy-cancel:'.$agreement['id']));
                            if ($record['state']==='prepared') $this->executeRecurringRecord($record);
                        }
                        ++$stats['deferred'];continue;
                    }
                    if ($agreement['periodEnd']>time() || $agreement['state']!=='active') continue;
                    if ($agreement['cancelAtPeriodEnd']) {
                        $this->recurringCommit($agreement,function()use($agreement):void{
                            $sub=$this->recurringSubscription($agreement);
                            $this->store->saveSubscription(array_replace($sub,['status'=>'expired','graceEndsAt'=>null]));
                            $this->recurringUpdate($agreement,['state'=>'cancelled']);
                        });++$stats['expired'];continue;
                    }
                    $record=$this->prepareRecurringCycle($agreement);
                    if ($record['state']==='prepared') $record=$this->executeRecurringRecord($record);
                    if ($record['state']==='applied') ++$stats['renewed'];elseif($record['state']==='declined') ++$stats['failed'];else ++$stats['deferred'];
                } catch (Throwable) {++$stats['deferred'];}
            }
            return $stats;
        });
    }

    public function recurringMemberships(string $memberId): array
    {
        $rows=[];$after=null;
        do {$page=$this->store->recurringAgreementPage($after,100,$memberId);foreach($page['records'] as $row)$rows[$row['subscriptionId']]=$this->recurringProjection($row);$after=$page['nextCursor'];}while($after!==null);
        return $rows;
    }

    public function recurringProjection(array $agreement): array
    {
        return (new RecurringRecoveryService($this->store))->project($agreement);
    }

    private function receiveRecurringEvent(array $event,array $snapshot): array
    {
        RecurringContract::keys($event,['family','eventId','result']);PaymentContract::reference($event['eventId']);
        if ($event['family']!=='recurring' || !is_array($event['result'])) throw new RuntimeException('Invalid recurring event.');
        $binding=RecurringContract::binding($event['result']['binding']);
        $record=$this->store->recurringJournal($binding['recordId']);
        if (!$record || $record['kind']==='event' || $record['binding']!==$binding) throw new RuntimeException('No original authorized recurring request matches this event.');
        $agreement=$this->store->recurringAgreement($record['agreementId']);
        if ($agreement['scope']!==$snapshot['scope']) throw new RuntimeException('Recurring event scope mismatch.');
        $result=RecurringContract::result($event['result'],$binding,$record['createdAt']);
        $id='revt_'.hash('sha256',$agreement['scopeHash'].':'.$event['eventId']);$receipt=$this->store->recurringJournal($id);
        if ($receipt && ($receipt['binding']!==$binding || $receipt['result']!==$result)) throw new RuntimeException('Conflicting recurring event identity.');
        if ($receipt && in_array($receipt['state'],RecurringContract::TERMINAL,true)) return ['duplicate'=>true,'state'=>$receipt['state']];
        $receipt=$this->recurringCommit($agreement,function()use($agreement,$snapshot,$id,$binding,$result,$receipt):array{
            $this->payments()->assertCurrentScope($snapshot);
            return $receipt??$this->store->saveRecurringJournal(['schema'=>1,'id'=>$id,'agreementId'=>$agreement['id'],'kind'=>'event',
                'dedupHash'=>substr($id,5),'orderId'=>$binding['payment']['orderId']??null,'state'=>'prepared','binding'=>$binding,
                'result'=>$result,'issue'=>null,'createdAt'=>time(),'updatedAt'=>time()]);
        });
        if ($record['state']==='prepared') {
            $this->recurringCommit($agreement,fn():array=>$this->recurringChange($receipt,'held',['issue'=>'binding_conflict']));
            throw new RuntimeException('Recurring event arrived before the authorized dispatch.');
        }
        $resolved=$this->receiveRecurringResult($record,$result);
        $agreement=$this->store->recurringAgreement($agreement['id']);
        $terminal=in_array($resolved['state'],RecurringContract::TERMINAL,true);
        $this->recurringCommit($agreement,fn():array=>$this->recurringChange($receipt,$terminal?'applied':'held',['issue'=>$terminal?null:'local_effect_failed']));
        if (!$terminal) throw new RuntimeException('Authenticated recurring evidence is retained for local recovery.',503);
        return ['duplicate'=>false,'state'=>$resolved['state']];
    }
}
