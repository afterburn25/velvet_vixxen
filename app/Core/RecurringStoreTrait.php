<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/** Indexed identities and JSON are checked together; all writes share the member financial fence. */
trait RecurringStoreTrait
{
    private function recurringRows(string $kind, ?string $owner = null, ?string $id = null, ?string $after = null, ?int $limit = null): array
    {
        $agreement = $kind === 'agreement';
        $map = $agreement ? 'recurringAgreements' : 'recurringJournal';
        if ($this->mode === 'file') {
            $rows = [];
            foreach ($this->readFileStore()[$map] ?? [] as $mapId=>$row) {
                $row = $agreement ? RecurringContract::agreement($row) : RecurringContract::journal($row);
                if ((string)$mapId !== $row['id']) throw new RuntimeException('Recurring storage identity needs review.');
                if ($owner === null || ($agreement ? $row['memberId'] : $row['agreementId']) === $owner) $rows[] = $row;
            }
        } else {
            $table = $agreement ? 'recurring_agreements' : 'recurring_journal';
            $column = $agreement ? 'member_id' : 'agreement_id';
            $where=[];$parameters=[];
            if ($owner!==null) {$where[]=$column.'=?';$parameters[]=$owner;}
            if ($id!==null) {$where[]='id=?';$parameters[]=$id;}
            if ($after!==null) {$where[]='id>?';$parameters[]=$after;}
            $sql='SELECT * FROM '.$table.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id';
            if ($limit!==null) {if($limit<1||$limit>100)throw new RuntimeException('Invalid recurring page size.');$sql.=' LIMIT '.($limit+1);}
            $statement=$this->pdo()->prepare($sql);$statement->execute($parameters);$rows=[];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $indexed) {
                $row = json_decode($indexed['record_json'],true,32,JSON_THROW_ON_ERROR);
                $row = $agreement ? RecurringContract::agreement($row) : RecurringContract::journal($row);
                $columns = $agreement ? ['id'=>'id','member_id'=>'memberId','subscription_id'=>'subscriptionId','scope_hash'=>'scopeHash','remote_id'=>'remoteId']
                    : ['id'=>'id','agreement_id'=>'agreementId','record_kind'=>'kind','dedup_hash'=>'dedupHash','order_id'=>'orderId'];
                foreach ($columns as $sql=>$json) if (($indexed[$sql] === null ? null : (string)$indexed[$sql]) !== $row[$json]) throw new RuntimeException('Recurring storage index needs review.');
                $rows[] = $row;
            }
        }
        usort($rows,static fn(array $a,array $b):int=>strcmp($a['id'],$b['id']));
        $rows=array_values(array_filter($rows,static fn(array $r):bool=>($id===null||$r['id']===$id)&&($after===null||strcmp($r['id'],$after)>0)));
        return $limit===null?$rows:array_slice($rows,0,$limit+1);
    }

    public function recurringAgreement(string $id): ?array
    {
        RecurringContract::id($id);
        return $this->recurringRows('agreement',null,$id)[0]??null;
    }

    public function recurringAgreementForSubscription(string $id): ?array
    {
        if ($this->mode!=='file') {
            $q=$this->pdo()->prepare('SELECT id FROM recurring_agreements WHERE subscription_id=?');$q->execute([$id]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
            if(count($ids)>1)throw new RuntimeException('Ambiguous recurring subscription binding.');
            return $ids?$this->recurringAgreement($ids[0]):null;
        }
        $matches = array_values(array_filter($this->recurringRows('agreement'),static fn(array $row):bool=>$row['subscriptionId']===$id));
        if (count($matches)>1) throw new RuntimeException('Ambiguous recurring subscription binding.');
        return $matches[0]??null;
    }

    public function recurringJournal(string $id): ?array
    {
        if (preg_match('/\A(?:rcmd_|rcyc_|revt_)[a-f0-9]{64}\z/D',$id)!==1) throw new RuntimeException('Recurring request identifier is invalid.');
        return $this->recurringRows('journal',null,$id)[0]??null;
    }

    public function recurringJournalForAgreement(string $id): array
    {
        RecurringContract::id($id);
        return $this->recurringRows('journal',$id);
    }

    public function recurringCycleForOrder(string $orderId): ?array
    {
        if ($this->mode!=='file') {
            $q=$this->pdo()->prepare("SELECT id FROM recurring_journal WHERE order_id=? AND record_kind='cycle'");$q->execute([$orderId]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
            if(count($ids)>1)throw new RuntimeException('Recurring order identity is ambiguous.');
            return $ids?$this->recurringJournal($ids[0]):null;
        }
        $rows=array_values(array_filter($this->recurringRows('journal'),static fn(array $row):bool=>$row['kind']==='cycle' && $row['orderId']===$orderId));
        if (count($rows)>1) throw new RuntimeException('Recurring order identity is ambiguous.');
        return $rows[0]??null;
    }

    public function recurringAgreementPage(?string $after=null,int $limit=50,?string $memberId=null): array
    {
        return $this->recurringPage($this->recurringRows('agreement',$memberId,null,$after,$limit),$after,$limit);
    }

    public function recurringJournalPage(string $agreementId,?string $after=null,int $limit=50): array
    {
        return $this->recurringPage($this->recurringRows('journal',$agreementId,null,$after,$limit),$after,$limit);
    }

    private function recurringPage(array $rows,?string $after,int $limit): array
    {
        if ($limit<1 || $limit>100) throw new RuntimeException('Invalid recurring page size.');
        if ($after!==null && preg_match('/\A(?:agr_|rcmd_|rcyc_|revt_)[a-f0-9]{64}\z/D',$after)!==1) throw new RuntimeException('Invalid recurring cursor.');
        $rows = array_values(array_filter($rows,static fn(array $r):bool=>$after===null || strcmp($r['id'],$after)>0));
        $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);
        return ['records'=>$rows,'nextCursor'=>$more?$rows[array_key_last($rows)]['id']:null];
    }

    private function recurringWriteFence(string $memberId): void
    {
        if (!$this->commerceLockHeld || !$this->paymentAtomicActive || $this->paymentMemberId !== $memberId) throw new RuntimeException('Recurring writes require the owning member financial commit.');
    }

    public function saveRecurringAgreement(array $row): array
    {
        $row=RecurringContract::agreement($row);$this->recurringWriteFence($row['memberId']);
        $before=$this->recurringAgreement($row['id']);
        if ($before === $row) return $row;
        if ($before === null) {
            $this->privacyRequireOpenMember($row['memberId']);
            if ($row['revision']!==1 || $row['state']!=='pending' || $row['remoteId']!==null || $row['erased'] || $row['providerRevision']!==0) throw new RuntimeException('Invalid initial recurring agreement.');
            $order=$this->order($row['orderId']);$item=null;$subscription=null;
            foreach ($order['items']??[] as $candidate) if ((string)$candidate['id']===$row['itemId']) $item=$candidate;
            foreach ($this->subscriptions($row['memberId']) as $candidate) if ((string)$candidate['id']===$row['subscriptionId']) $subscription=$candidate;
            if (!$order || $order['status']!=='paid' || (string)$order['memberId']!==$row['memberId'] || !$item || $item['itemType']!=='plan'
                || (string)($item['metadata']['subscriptionId']??'')!==$row['subscriptionId'] || !$subscription
                || PurchasedTerms::forItem($order,$item)!==$row['terms']) throw new RuntimeException('Recurring agreement has no original paid purchase binding.');
        } else {
            foreach (['id','memberId','subscriptionId','orderId','itemId','scope','scopeHash','termsHash','anchorDay','createdAt'] as $key) if ($before[$key]!==$row[$key]) throw new RuntimeException('Recurring agreement identity cannot change.');
            if ($row['revision']!==$before['revision']+1 || $row['updatedAt']<$before['updatedAt'] || $row['providerRevision']<$before['providerRevision']) throw new RuntimeException('Stale recurring agreement revision.');
            if ($before['remoteId']!==null && $before['remoteId']!==$row['remoteId']) throw new RuntimeException('Recurring remote binding cannot change.');
            if ($before['terms']!==$row['terms'] && !($row['erased'] && $row['terms']===null)) throw new RuntimeException('Recurring accepted terms cannot change.');
            if ($before['erased'] && (!$row['erased'] || $row['terms']!==null)) throw new RuntimeException('Erased recurring terms cannot be reconstructed.');
            if ($before['periodStart']!==$row['periodStart'] || $before['periodEnd']!==$row['periodEnd']) {
                if ($row['periodStart']!==$before['periodEnd'] || $row['providerRevision']!==$before['providerRevision']+1 || $row['state']!=='active' || $row['erased']) throw new RuntimeException('Recurring period cannot be moved without the next confirmed cycle.');
            }
        }
        if ($this->privacyMemberErased($row['memberId']) && !$row['erased']) throw new RuntimeException('Recurring terms cannot survive privacy erasure.');
        foreach ($this->mode==='file'?$this->recurringRows('agreement'):[] as $other) {
            if ($other['id']===$row['id']) continue;
            if ($other['subscriptionId']===$row['subscriptionId'] || ($row['remoteId']!==null && $other['scopeHash']===$row['scopeHash'] && $other['remoteId']===$row['remoteId'])) throw new RuntimeException('Recurring identity is already claimed.');
        }
        if ($this->mode==='file') {$all=$this->readFileStore();$all['recurringAgreements'][$row['id']]=$row;$this->writeFileStore($all);}
        else {
            $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if ($before===null) $this->pdo()->prepare('INSERT INTO recurring_agreements (id,member_id,subscription_id,scope_hash,remote_id,record_json) VALUES (?,?,?,?,?,?)')->execute([$row['id'],$row['memberId'],$row['subscriptionId'],$row['scopeHash'],$row['remoteId'],$json]);
            else $this->pdo()->prepare('UPDATE recurring_agreements SET remote_id=?,record_json=? WHERE id=?')->execute([$row['remoteId'],$json,$row['id']]);
        }
        return $row;
    }

    public function saveRecurringJournal(array $row): array
    {
        $row=RecurringContract::journal($row);$agreement=$this->recurringAgreement($row['agreementId']);
        if (!$agreement) throw new RuntimeException('Recurring agreement is missing.');
        $this->recurringWriteFence($agreement['memberId']);
        $before=$this->recurringJournal($row['id']);RecurringContract::transition($before,$row);
        if ($row['binding']['scope']!==$agreement['scope'] || $row['binding']['termsHash']!==$agreement['termsHash']) throw new RuntimeException('Recurring journal scope mismatch.');
        if ($before===null && $row['kind']!=='event') {
            if ($agreement['erased'] && $row['binding']['action']!=='cancel') throw new RuntimeException('Closed accounts cannot create new billing requests.');
            if ($row['binding']['agreementRevision']!==$agreement['revision'] || $row['binding']['providerRevision']!==$agreement['providerRevision'] || $row['binding']['remoteId']!==$agreement['remoteId']) throw new RuntimeException('Stale recurring request.');
        }
        foreach ($this->recurringRows('journal',$this->mode==='file'?null:$row['agreementId']) as $other) {
            if ($other['id']===$row['id']) continue;
            if ($other['kind']===$row['kind'] && $other['dedupHash']===$row['dedupHash']) throw new RuntimeException('Recurring request identity is already claimed.');
            if ($before===null && $row['kind']!=='event' && $other['kind']!=='event' && $other['agreementId']===$row['agreementId']) {
                if (!in_array($other['state'],RecurringContract::TERMINAL,true)) throw new RuntimeException('Another recurring outcome needs reconciliation first.');
                if ($row['kind']==='cycle' && $other['kind']==='cycle' && $row['binding']['periodStart']===$other['binding']['periodStart']) throw new RuntimeException('This recurring period already has a durable cycle.');
            }
        }
        if ($row['kind']==='event') {
            $original=$this->recurringJournal($row['binding']['recordId']);
            if (!$original || $original['kind']==='event' || $original['agreementId']!==$agreement['id'] || $original['binding']!==$row['binding']) throw new RuntimeException('Recurring event has no original authorized request.');
        }
        if ($row['kind']==='cycle') {
            $order=$this->order($row['orderId']);
            if (!$order || (string)$order['memberId']!==$agreement['memberId']) throw new RuntimeException('Recurring order member mismatch.');
        }
        if ($this->mode==='file') {$all=$this->readFileStore();$all['recurringJournal'][$row['id']]=$row;$this->writeFileStore($all);}
        else {
            $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if ($before===null) $this->pdo()->prepare('INSERT INTO recurring_journal (id,agreement_id,record_kind,dedup_hash,order_id,record_json) VALUES (?,?,?,?,?,?)')->execute([$row['id'],$row['agreementId'],$row['kind'],$row['dedupHash'],$row['orderId'],$json]);
            else $this->pdo()->prepare('UPDATE recurring_journal SET record_json=? WHERE id=?')->execute([$json,$row['id']]);
        }
        return $row;
    }
}
