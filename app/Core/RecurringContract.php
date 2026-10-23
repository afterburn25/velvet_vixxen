<?php
declare(strict_types=1);

namespace App\Core;

use App\Payments\PaymentContract;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Strict retained facts. Neither catalog rows nor browser input are provider evidence. */
final class RecurringContract
{
    public const TERMINAL = ['applied','declined','ignored'];

    public static function keys(array $row, array $keys): void
    {
        $actual = array_keys($row); sort($actual); sort($keys);
        if ($actual !== $keys) self::invalid();
    }

    public static function integer(mixed $n, int $min = 0, int $max = 4102444800): int
    {
        if (!is_int($n) || $n < $min || $n > $max) self::invalid();
        return $n;
    }

    public static function hash(mixed $hash): string
    {
        if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) self::invalid();
        return $hash;
    }

    public static function id(mixed $id, string $kind = 'agreement'): string
    {
        $prefix = match ($kind) { 'agreement'=>'agr_', 'command'=>'rcmd_', 'cycle'=>'rcyc_', 'event'=>'revt_', default=>throw new RuntimeException('Invalid recurring kind.') };
        if (!is_string($id) || preg_match('/\A' . $prefix . '[a-f0-9]{64}\z/D', $id) !== 1) self::invalid();
        return $id;
    }

    public static function scope(array $row): array
    {
        self::keys($row, ['installation','provider','account','environment']);
        self::hash($row['installation']); PaymentContract::reference($row['provider']);
        PaymentContract::context(['account'=>$row['account'],'environment'=>$row['environment']]);
        return $row;
    }

    public static function agreement(array $row): array
    {
        self::keys($row, ['schema','id','memberId','subscriptionId','orderId','itemId','scope','scopeHash','remoteId','terms','termsHash',
            'periodStart','periodEnd','anchorDay','state','cancelAtPeriodEnd','providerRevision','revision','erased','issue','createdAt','updatedAt']);
        if ($row['schema'] !== 1) self::invalid();
        self::id($row['id']);
        foreach (['memberId','subscriptionId','orderId','itemId'] as $key) PaymentContract::reference($row[$key]);
        self::scope($row['scope']);
        if ($row['scopeHash'] !== PurchasedTerms::hash($row['scope'])) self::invalid();
        if ($row['remoteId'] !== null) PaymentContract::reference($row['remoteId']);
        self::hash($row['termsHash']);
        if (!is_bool($row['erased']) || !is_bool($row['cancelAtPeriodEnd'])) self::invalid();
        if ($row['erased']) { if ($row['terms'] !== null || $row['state'] !== 'closed') self::invalid(); }
        else {
            $terms = PurchasedTerms::validate($row['terms']);
            if ($terms['hash'] !== $row['termsHash'] || $terms['terms']['kind'] !== 'plan') self::invalid();
        }
        self::integer($row['periodStart'], 1); self::integer($row['periodEnd'], $row['periodStart']+1);
        self::integer($row['anchorDay'], 1, 31); self::integer($row['providerRevision']); self::integer($row['revision'],1);
        self::integer($row['createdAt'],1); self::integer($row['updatedAt'],$row['createdAt']);
        if (!in_array($row['state'], ['pending','active','cancelled','held','closed'],true)) self::invalid();
        self::issue($row['issue']);
        if ($row['state'] === 'active' && $row['remoteId'] === null) self::invalid();
        return $row;
    }

    public static function binding(array $row): array
    {
        self::keys($row,['schema','recordId','agreementId','scope','remoteId','termsHash','action','requestId','agreementRevision','providerRevision',
            'periodStart','periodEnd','cancelAtPeriodEnd','payment']);
        if ($row['schema'] !== 1 || !in_array($row['action'],['activate','cancel','resume','cycle'],true)) self::invalid();
        self::id($row['recordId'], $row['action'] === 'cycle' ? 'cycle' : 'command'); self::id($row['agreementId']);
        self::scope($row['scope']); self::hash($row['termsHash']); PaymentContract::reference($row['requestId']);
        self::integer($row['agreementRevision'],1); self::integer($row['providerRevision']);
        self::integer($row['periodStart'],1); self::integer($row['periodEnd'],$row['periodStart']+1);
        if (!is_bool($row['cancelAtPeriodEnd'])) self::invalid();
        if ($row['remoteId'] !== null) PaymentContract::reference($row['remoteId']);
        if (($row['action'] === 'activate') !== ($row['remoteId'] === null)) self::invalid();
        if ($row['action'] === 'cycle') {
            $payment = PaymentContract::binding($row['payment']);
            foreach ($row['scope'] as $key=>$value) if ($payment[$key] !== $value) self::invalid();
            if ($payment['kind'] !== 'charge' || $row['cancelAtPeriodEnd']) self::invalid();
        } elseif ($row['payment'] !== null || $row['cancelAtPeriodEnd'] !== ($row['action'] === 'cancel')) self::invalid();
        return $row;
    }

    public static function result(array $row, array $binding, int $createdAt): array
    {
        self::keys($row,['schema','binding','outcome','remoteId','providerRevision','transactionId','observedAt','cancelAtPeriodEnd']);
        if ($row['schema'] !== 1 || self::binding($row['binding']) !== self::binding($binding)
            || !in_array($row['outcome'],['succeeded','declined','pending','unknown'],true) || !is_bool($row['cancelAtPeriodEnd'])) self::invalid();
        self::integer($row['providerRevision']); self::integer($row['observedAt'],max(1,$createdAt-300),time()+300);
        foreach (['remoteId','transactionId'] as $key) if ($row[$key] !== null) PaymentContract::reference($row[$key]);
        if ($row['outcome'] === 'succeeded') {
            if ($row['remoteId'] === null || ($binding['remoteId'] !== null && $row['remoteId'] !== $binding['remoteId'])
                || $row['providerRevision'] !== $binding['providerRevision']+1 || $row['cancelAtPeriodEnd'] !== $binding['cancelAtPeriodEnd']) self::invalid();
            if (($binding['action'] === 'cycle') !== ($row['transactionId'] !== null)) self::invalid();
        } elseif ($row['providerRevision'] !== $binding['providerRevision'] || $row['remoteId'] !== $binding['remoteId']
            || $row['transactionId'] !== null) self::invalid();
        return $row;
    }

    public static function journal(array $row): array
    {
        self::keys($row,['schema','id','agreementId','kind','dedupHash','orderId','state','binding','result','issue','createdAt','updatedAt']);
        if ($row['schema'] !== 1 || !in_array($row['kind'],['command','cycle','event'],true)) self::invalid();
        self::id($row['id'],$row['kind']); self::id($row['agreementId']); self::hash($row['dedupHash']);
        $binding = self::binding($row['binding']);
        if (($row['kind']!=='event' && $binding['recordId'] !== $row['id']) || $binding['agreementId'] !== $row['agreementId']
            || ($row['kind']!=='event' && ($binding['action'] === 'cycle') !== ($row['kind'] === 'cycle'))) self::invalid();
        if ($binding['action'] === 'cycle') { if ($row['orderId'] !== $binding['payment']['orderId']) self::invalid(); }
        elseif ($row['orderId'] !== null) self::invalid();
        if (!in_array($row['state'],['prepared','attempting','pending','unknown','confirmed','applied','declined','held','ignored'],true)) self::invalid();
        self::integer($row['createdAt'],1); self::integer($row['updatedAt'],$row['createdAt']); self::issue($row['issue']);
        if ($row['result'] !== null) self::result($row['result'],$binding,$row['kind']==='event'?1:$row['createdAt']);
        if ($row['state']==='confirmed' && !in_array($row['result']['outcome']??null,['succeeded','declined'],true)) self::invalid();
        if ($row['kind']!=='event' && $row['state']==='applied' && ($row['result']['outcome']??null)!=='succeeded') self::invalid();
        if ($row['state'] === 'declined' && ($row['result']['outcome']??null) !== 'declined') self::invalid();
        return $row;
    }

    public static function transition(?array $before, array $after): void
    {
        if ($before === null) { if ($after['state'] !== 'prepared') self::invalid(); return; }
        foreach (['id','agreementId','kind','dedupHash','orderId','binding','createdAt'] as $key) if ($before[$key] !== $after[$key]) self::invalid();
        if ($after['kind']==='event') {
            if (in_array($before['state'],self::TERMINAL,true)) { if($before!==$after)self::invalid(); }
            elseif (!in_array($after['state'],['applied','held','ignored'],true)) self::invalid();
            if ($before['result']!==$after['result']) self::invalid();
            return;
        }
        $allowed = match($before['state']) {
            'prepared'=>['prepared','attempting','held','ignored'],
            'attempting','pending','unknown','held'=>['pending','unknown','confirmed','declined','held','ignored'],
            'confirmed'=>['confirmed','applied','declined','held','ignored'],
            default=>[$before['state']],
        };
        if (!in_array($after['state'],$allowed,true)) self::invalid();
        if (in_array($before['result']['outcome']??null,['succeeded','declined'],true) && $before['result'] !== $after['result']) self::invalid();
        if (in_array($before['state'],self::TERMINAL,true) && $before !== $after) self::invalid();
    }

    /** Calendar arithmetic preserves Jan-31 and leap-year anchors without overflow. */
    public static function nextEnd(int $start, string $interval, int $count, int $anchorDay): int
    {
        self::integer($start,1); self::integer($count,1,120); self::integer($anchorDay,1,31);
        $date = (new DateTimeImmutable('@'.$start))->setTimezone(new DateTimeZone('UTC'));
        if (in_array($interval,['day','week'],true)) return $date->modify('+'.$count.' '.$interval)->getTimestamp();
        if (!in_array($interval,['month','year'],true)) self::invalid();
        $months = (int)$date->format('Y')*12 + (int)$date->format('n')-1 + ($interval==='year'?$count*12:$count);
        $year = intdiv($months,12); $month = $months%12+1;
        $days = (int)$date->setDate($year,$month,1)->format('t');
        return $date->setDate($year,$month,min($anchorDay,$days))->getTimestamp();
    }

    private static function issue(mixed $issue): void
    {
        if ($issue !== null && !in_array($issue,['transport_unknown','malformed_result','scope_changed','account_closed','account_ineligible','local_effect_failed','pending_provider','cycle_declined','binding_conflict','provider_declined'],true)) self::invalid();
    }

    private static function invalid(): never { throw new RuntimeException('Recurring billing facts need review. No new request is authorized.'); }
}
