<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Read-only safe projection. It never constructs a gateway, mailer or add-on runtime. */
final class RecurringRecoveryService
{
    public function __construct(private readonly Store $store) {}

    public function page(?string $after=null,int $limit=50,?string $memberId=null): array
    {
        $page=$this->store->recurringAgreementPage($after,$limit,$memberId);
        return ['rows'=>array_map(fn(array $row):array=>$this->project($row),$page['records']),'nextCursor'=>$page['nextCursor']];
    }

    public function inspect(string $id,?string $after=null): array
    {
        $row=$this->store->recurringAgreement($id);
        if (!$row) throw new RuntimeException('Recurring membership not found.',404);
        return $this->project($row,$after);
    }

    public function project(array $agreement,?string $after=null): array
    {
        RecurringContract::agreement($agreement);
        $safe=array_intersect_key($agreement,array_flip(['id','memberId','subscriptionId','orderId','revision','periodStart','periodEnd','state','cancelAtPeriodEnd','erased','issue']));
        $page=$this->store->recurringJournalPage($agreement['id'],$after,50);
        $project=static fn(array $row):array=>array_intersect_key($row,array_flip(['id','kind','state','issue','createdAt','updatedAt']))+['action'=>$row['binding']['action'],
            'canReconcile'=>$row['kind']!=='event' && !in_array($row['state'],array_merge(RecurringContract::TERMINAL,['prepared']),true)];
        $safe['requests']=array_map($project,$page['records']);$safe['nextRequestCursor']=$page['nextCursor'];
        $open=array_values(array_filter($this->store->recurringJournalForAgreement($agreement['id']),static fn(array $row):bool=>$row['kind']!=='event' && !in_array($row['state'],RecurringContract::TERMINAL,true)));
        $safe['pendingRequest']=$open?$project($open[0]):null;
        $safe['canCancel']=$open===[] && $agreement['remoteId']!==null && !$agreement['cancelAtPeriodEnd'];
        $safe['canResume']=$open===[] && !$agreement['erased'] && $agreement['state']==='active' && $agreement['cancelAtPeriodEnd'] && $agreement['periodEnd']>time();
        $terms=$agreement['terms']['terms']??null;
        $safe['amountMinor']=$terms['recurringMinor']??null;$safe['currency']=$terms['currency']??null;
        $safe['interval']=$terms['interval']??null;$safe['intervalCount']=$terms['intervalCount']??null;
        return $safe;
    }
}
