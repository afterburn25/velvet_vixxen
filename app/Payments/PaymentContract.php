<?php
declare(strict_types=1);

namespace App\Payments;

use RuntimeException;

/** Strict, provider-independent financial envelopes; never accepts client assertions. */
final class PaymentContract
{
    public const MAX_BODY_BYTES = 65536;
    private const BINDING_KEYS = ['schema','operationId','kind','installation','provider','account','environment','orderId','orderNumber','amountMinor','currency','originalTransactionId'];

    /** Existing catalog/storage money has exactly two decimal places. */
    public static function minor(mixed $amount): int
    {
        if ((!is_int($amount) && !is_float($amount) && !is_string($amount)) || !is_numeric($amount)
            || !is_finite((float) $amount) || (float) $amount < 0 || (float) $amount > 9999999999.99
            || abs((float) $amount * 100 - round((float) $amount * 100)) > 0.0001) {
            throw new RuntimeException('Payment amount is not an exact supported monetary value.');
        }
        return (int) round((float) $amount * 100);
    }

    public static function reference(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,189}\z/D', $value) !== 1) {
            throw new RuntimeException('Payment reference is missing or malformed.');
        }
        return $value;
    }

    public static function context(array $context): array
    {
        self::keys($context, ['account','environment']);
        if (!is_string($context['account'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $context['account']) !== 1
            || !in_array($context['environment'] ?? null, ['sandbox','test','live'], true)) {
            throw new RuntimeException('Payment account context is not configured.');
        }
        return ['account' => $context['account'], 'environment' => $context['environment']];
    }

    public static function binding(array $binding): array
    {
        self::keys($binding, self::BINDING_KEYS);
        if (($binding['schema'] ?? null) !== 1 || !is_string($binding['operationId'] ?? null)
            || preg_match('/\Apay_[a-f0-9]{32}\z/D', $binding['operationId']) !== 1
            || !in_array($binding['kind'] ?? null, ['charge','refund','chargeback'], true)
            || !is_string($binding['installation'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $binding['installation']) !== 1
            || !is_int($binding['amountMinor'] ?? null) || $binding['amountMinor'] < 0 || $binding['amountMinor'] > 999999999999
            || !is_string($binding['currency'] ?? null) || preg_match('/\A[A-Z]{3}\z/D', $binding['currency']) !== 1) {
            throw new RuntimeException('Payment binding is incomplete or malformed.');
        }
        self::context(['account' => $binding['account'] ?? null, 'environment' => $binding['environment'] ?? null]);
        foreach (['provider','orderId','orderNumber'] as $field) self::reference($binding[$field] ?? null);
        if (!array_key_exists('originalTransactionId', $binding)
            || ($binding['kind'] === 'charge' && $binding['originalTransactionId'] !== null)) {
            throw new RuntimeException('Payment original transaction binding is invalid.');
        }
        if ($binding['kind'] !== 'charge') {
            self::reference($binding['originalTransactionId']);
            if ($binding['amountMinor'] === 0) throw new RuntimeException('Refund/dispute amount must be positive.');
        }
        return array_replace(array_fill_keys(self::BINDING_KEYS, null), $binding);
    }

    /** Only the financial fields are durable; optional card display hints are returned separately. */
    public static function result(array $result, array $binding, int $createdAt): array
    {
        self::keys($result, ['schema','binding','outcome','transactionId','observedAt'], ['display']);
        if (($result['schema'] ?? null) !== 1 || !is_array($result['binding'] ?? null)
            || self::binding($result['binding']) !== self::binding($binding)
            || !in_array($result['outcome'] ?? null, ['succeeded','declined','pending','unknown'], true)
            || !array_key_exists('transactionId', $result)) {
            throw new RuntimeException('Payment result does not match the durable operation.');
        }
        self::time($result['observedAt'] ?? null, $createdAt);
        if ($result['transactionId'] !== null) self::reference($result['transactionId']);
        if ($result['outcome'] === 'succeeded' && $result['transactionId'] === null) {
            throw new RuntimeException('A confirmed payment needs an authenticated transaction reference.');
        }
        $display = $result['display'] ?? [];
        if (!is_array($display)) throw new RuntimeException('Payment display metadata is invalid.');
        self::keys($display, [], ['last4','brand']);
        if (isset($display['last4']) && (!is_string($display['last4']) || preg_match('/\A[0-9]{4}\z/D', $display['last4']) !== 1)) throw new RuntimeException('Payment card hint is invalid.');
        if (isset($display['brand']) && (!is_string($display['brand']) || preg_match('/\A[\x20-\x7e]{1,80}\z/D', $display['brand']) !== 1)) throw new RuntimeException('Payment brand hint is invalid.');
        return ['schema' => 1, 'binding' => self::binding($binding), 'outcome' => $result['outcome'],
            'transactionId' => $result['transactionId'], 'observedAt' => $result['observedAt'], 'display' => $display];
    }

    public static function event(array $event): array
    {
        self::keys($event, ['schema','eventId','eventType','binding','transactionId','occurredAt']);
        if (($event['schema'] ?? null) !== 1 || !is_array($event['binding'] ?? null)) throw new RuntimeException('Payment event envelope is invalid.');
        self::reference($event['eventId'] ?? null); self::reference($event['transactionId'] ?? null);
        $binding = self::binding($event['binding']);
        $types = ['order.paid' => 'charge', 'order.refunded' => 'refund', 'order.chargeback' => 'chargeback'];
        if (!is_string($event['eventType'] ?? null) || ($types[$event['eventType']] ?? null) !== $binding['kind']) throw new RuntimeException('Payment event type does not match its operation.');
        self::time($event['occurredAt'] ?? null, 0);
        return ['schema' => 1, 'eventId' => $event['eventId'], 'eventType' => $event['eventType'], 'binding' => $binding,
            'transactionId' => $event['transactionId'], 'occurredAt' => $event['occurredAt']];
    }

    public static function fingerprint(array $value): string
    {
        $sort = static function (mixed $part) use (&$sort): mixed {
            if (!is_array($part)) return $part;
            if (!array_is_list($part)) ksort($part, SORT_STRING);
            return array_map($sort, $part);
        };
        return hash('sha256', json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** No arbitrary provider/customer payload is allowed in the retained financial journal. */
    public static function journal(array $row): array
    {
        self::keys($row, ['id','kind','orderId','state','createdAt','updatedAt','data']);
        if (!is_string($row['id']) || preg_match('/\A(?:pay_[a-f0-9]{32}|evt_[a-f0-9]{64}|fx_[a-f0-9]{64})\z/D', $row['id']) !== 1
            || !is_array($row['data']) || !is_int($row['createdAt']) || !is_int($row['updatedAt'])
            || $row['createdAt'] < 1 || $row['updatedAt'] < $row['createdAt']) throw new RuntimeException('Payment journal record is malformed.');
        if ($row['orderId'] !== null) self::reference($row['orderId']);
        $data = $row['data'];
        $states = [
            'operation' => ['prepared','attempting','unknown','pending','confirmed','applied','declined','held','ignored'],
            'event' => ['received','applied','ignored','held'],
            'effect' => ['pending','attempting','retryable','done','held','skipped'],
        ];
        if (!isset($states[$row['kind']]) || !in_array($row['state'], $states[$row['kind']], true)) throw new RuntimeException('Payment journal state is invalid.');
        if (!str_starts_with($row['id'], match ($row['kind']) { 'operation' => 'pay_', 'event' => 'evt_', 'effect' => 'fx_' })) throw new RuntimeException('Payment journal identity has the wrong record type.');
        if ($row['kind'] === 'operation') {
            self::keys($data, ['binding','source','result','transactionId','issue']);
            $binding = is_array($data['binding']) ? self::binding($data['binding']) : [];
            if ($binding === [] || $binding['operationId'] !== $row['id'] || $binding['orderId'] !== $row['orderId']
                || !in_array($data['source'], ['checkout','refund','webhook','legacy'], true)) throw new RuntimeException('Payment journal operation binding is invalid.');
            if ($data['transactionId'] !== null) self::reference($data['transactionId']);
            if ($data['result'] !== null) {
                if (!is_array($data['result']) || isset($data['result']['display'])) throw new RuntimeException('Card hints cannot enter the payment journal.');
                self::result($data['result'], $binding, $row['createdAt']);
            }
        } elseif ($row['kind'] === 'event') {
            self::keys($data, ['provider','account','environment','eventId','eventType','fingerprint','operationId','event','issue']);
            self::reference($data['provider']); self::reference($data['eventId']); self::reference($data['eventType']);
            self::context(['account' => $data['account'], 'environment' => $data['environment']]);
            if (!is_string($data['fingerprint']) || preg_match('/\A[a-f0-9]{64}\z/D', $data['fingerprint']) !== 1) throw new RuntimeException('Payment event fingerprint is invalid.');
            if ($data['event'] !== null) {
                if (!is_array($data['event'])) throw new RuntimeException('Payment event journal is invalid.');
                $event = self::event($data['event']);
                if ($event['binding']['orderId'] !== $row['orderId'] || $event['binding']['operationId'] !== $data['operationId']) throw new RuntimeException('Payment event journal order binding is invalid.');
                foreach (['provider','account','environment'] as $field) if ($event['binding'][$field] !== $data[$field]) throw new RuntimeException('Payment event journal scope binding is invalid.');
                if ($event['eventId'] !== $data['eventId'] || $event['eventType'] !== $data['eventType']) throw new RuntimeException('Payment event journal envelope binding is invalid.');
            } elseif ($data['operationId'] !== null) throw new RuntimeException('Unbound payment events cannot claim an operation.');
        } else {
            self::keys($data, ['operationId','effect','event','subscriptionId','amountMinor','source','issue']);
            if ($row['orderId'] === null || !is_string($data['operationId']) || preg_match('/\Apay_[a-f0-9]{32}\z/D', $data['operationId']) !== 1
                || !in_array($data['effect'], ['receipt','marketing','addon','inventory'], true)
                || !in_array($data['source'], ['checkout','admin','webhook'], true)
                || !is_int($data['amountMinor']) || $data['amountMinor'] < 0 || $data['amountMinor'] > 999999999999) throw new RuntimeException('Payment obligation is invalid.');
            if ($data['subscriptionId'] !== null) self::reference($data['subscriptionId']);
            if ($data['event'] !== null && !in_array($data['event'], ['order.completed','subscription.started','subscription.cancelled','order.refunded','order.chargeback'], true)) throw new RuntimeException('Payment addon obligation is invalid.');
            if (($data['effect'] === 'addon') !== ($data['event'] !== null)
                || ($data['effect'] !== 'addon' && $data['subscriptionId'] !== null)
                || ($data['effect'] === 'inventory' && ($row['state'] !== 'held' || $data['issue'] !== 'inventory_review'))) throw new RuntimeException('Payment obligation kind does not match its delivery boundary.');
        }
        if ($data['issue'] !== null && !in_array($data['issue'], ['transport_unknown','malformed_result','pending_provider','interrupted_call','local_effect_failed','reference_conflict','binding_conflict','scope_changed','external_interrupted','external_unknown','external_retryable','account_closed','account_ineligible','disabled','unsupported','inventory_review'], true)) throw new RuntimeException('Payment diagnostic code is invalid.');
        return $row;
    }

    /** Terminal/ambiguous effects cannot be reset into an automatic resend. */
    public static function transition(?array $previous, array $row): void
    {
        if ($previous === null) {
            $initial = match ($row['kind']) { 'operation' => 'prepared', 'event' => 'received', 'effect' => $row['data']['effect'] === 'inventory' ? 'held' : 'pending' };
            if ($row['state'] !== $initial) throw new RuntimeException('Payment journal must start at its durable intent boundary.');
            return;
        }
        $allowed = match ($row['kind']) {
            'operation' => [
                'prepared' => ['prepared','attempting','confirmed','pending','unknown','held'], 'attempting' => ['attempting','unknown','pending','confirmed','held'],
                'unknown' => ['unknown','pending','confirmed','held'], 'pending' => ['pending','confirmed','unknown','held','ignored'],
                'confirmed' => ['confirmed','applied','declined','ignored'], 'held' => ['held','unknown','pending','confirmed'],
                'applied' => ['applied'], 'declined' => ['declined'], 'ignored' => ['ignored'],
            ],
            'event' => ['received'=>['received','applied','held','ignored'], 'held'=>['held','applied','ignored'], 'applied'=>['applied'], 'ignored'=>['ignored']],
            'effect' => ['pending'=>['pending','attempting','held','skipped'], 'retryable'=>['retryable','attempting','held','skipped'],
                'attempting'=>['attempting','done','retryable','held','skipped'], 'held'=>['held'], 'done'=>['done'], 'skipped'=>['skipped']],
        };
        if (!in_array($row['state'], $allowed[$previous['state']] ?? [], true)) throw new RuntimeException('Payment journal transition would repeat or contradict an existing outcome.');
    }

    private static function keys(array $value, array $required, array $optional = []): void
    {
        if (array_diff($required, array_keys($value)) !== [] || array_diff(array_keys($value), array_merge($required, $optional)) !== []) {
            throw new RuntimeException('Payment envelope has missing or unsupported fields.');
        }
    }

    private static function time(mixed $time, int $createdAt): void
    {
        if (!is_int($time) || $time < max(1, $createdAt - 300) || $time > time() + 300) throw new RuntimeException('Payment evidence timestamp is invalid.');
    }
}
