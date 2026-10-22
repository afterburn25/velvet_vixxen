<?php
declare(strict_types=1);

namespace App\Core;

use App\Payments\BoundPaymentProviderInterface;
use App\Payments\PaymentContract;
use App\Payments\PaymentProviderRegistry;
use RuntimeException;
use Throwable;

/** Durable intent and authenticated evidence. Local effects belong to CommerceService. */
final class PaymentOperationService
{
    public function __construct(private readonly Store $store, private readonly Config $config, private readonly PaymentProviderRegistry $providers) {}

    public function provider(string $key): BoundPaymentProviderInterface
    {
        $this->store->paymentRequireCallbackBoundary();
        $configuration = $this->store->paymentProvider($key);
        $provider = $this->providers->get($key);
        if (!$configuration || empty($configuration['enabled'])) throw new RuntimeException('Payment provider is not enabled.');
        if (!$provider instanceof BoundPaymentProviderInterface || $provider->key() !== $key) throw new RuntimeException('Payment provider does not implement the bound operation contract.');
        return $provider;
    }

    public function scope(string $key): array
    {
        return $this->captureScope($key)['scope'];
    }

    /** Trusted in-process snapshot only: never persist or expose provider configuration. */
    public function captureScope(string $key): array
    {
        $provider = $this->provider($key);
        $configuration = $this->store->paymentProvider($key);
        if (!$configuration || empty($configuration['enabled'])) throw new RuntimeException('Payment provider changed before its account context was resolved.');
        $context = PaymentContract::context($provider->accountContext($configuration));
        $snapshot = ['scope' => ['installation' => $this->installationIdentity(), 'provider' => $key] + $context, 'configuration' => $configuration];
        $this->assertCurrentScope($snapshot);
        return $snapshot;
    }

    private function installationIdentity(): string
    {
        $identity = $this->config->get('installation_id', '');
        if (!is_string($identity) || $identity === '') $identity = $this->config->get('security.app_key', '');
        if (!is_string($identity) || $identity === '') throw new RuntimeException('Payment installation identity is unavailable.');
        return hash('sha256', 'vvx-payment-installation:' . $identity);
    }

    /** Store/configuration reads only, including when called inside paymentAtomic. */
    public function assertCurrentScope(array $snapshot): void
    {
        if ($snapshot['scope']['installation'] !== $this->installationIdentity()
            || $this->store->paymentProvider($snapshot['scope']['provider']) !== $snapshot['configuration']) {
            throw new RuntimeException('Payment configuration changed after its account context was validated.');
        }
    }

    public function binding(array $order, string $kind, int $amountMinor, ?string $originalTransactionId = null, ?string $operationId = null): array
    {
        return $this->bindingInScope($order, $this->scope((string) $order['providerKey']), $kind, $amountMinor, $originalTransactionId, $operationId);
    }

    /** Build from an already validated trusted scope without invoking an adapter. */
    public function bindingInScope(array $order, array $scope, string $kind, int $amountMinor, ?string $originalTransactionId = null, ?string $operationId = null): array
    {
        if (($scope['provider'] ?? null) !== $order['providerKey']) throw new RuntimeException('Payment scope does not match this order.');
        return PaymentContract::binding(['schema' => 1, 'operationId' => $operationId ?? ('pay_' . bin2hex(random_bytes(16))), 'kind' => $kind]
            + $scope
            + ['orderId' => (string) $order['id'], 'orderNumber' => (string) $order['orderNumber'], 'amountMinor' => $amountMinor,
                'currency' => (string) $order['currency'], 'originalTransactionId' => $originalTransactionId]);
    }

    /** Caller is inside paymentAtomic with the commerce mutex held. */
    public function prepare(array $binding, string $source): array
    {
        $binding = PaymentContract::binding($binding);
        $existing = $this->store->paymentJournal($binding['operationId']);
        if ($existing) {
            if ($existing['kind'] !== 'operation' || $existing['data']['binding'] !== $binding) throw new RuntimeException('Payment operation ID is already bound to a different request.');
            return $existing;
        }
        return $this->store->savePaymentJournal(['id' => $binding['operationId'], 'kind' => 'operation', 'orderId' => $binding['orderId'],
            'state' => 'prepared', 'createdAt' => time(), 'updatedAt' => time(),
            'data' => ['binding' => $binding, 'source' => $source, 'result' => null, 'transactionId' => null, 'issue' => null]]);
    }

    /** Lock the owning member before any transactional reads. */
    public function commit(string $orderId, callable $operation, ?callable $authorize = null): mixed
    {
        $order = $this->store->order($orderId);
        if (!$order) throw new RuntimeException('Payment order not found.');
        return $this->store->paymentAtomic(static function () use ($operation, $authorize): mixed { self::authorize($authorize); return $operation(); }, (string) $order['memberId']);
    }

    public static function authorize(?callable $authorize): void
    {
        if ($authorize === null) return;
        try { if ($authorize() === false) throw new RuntimeException('Owner authorization was denied.', 403); }
        catch (PaymentRecoveryAuthorizationException $error) { throw $error; }
        catch (Throwable $error) { throw new PaymentRecoveryAuthorizationException($error); }
    }

    public function change(array $operation, string $state, array $data = []): array
    {
        if ($this->store->paymentJournal($operation['id']) !== $operation) throw new RuntimeException('Payment journal changed before this transition.');
        $operation['state'] = $state; $operation['updatedAt'] = max(time(), $operation['createdAt']);
        $operation['data'] = array_replace($operation['data'], $data);
        return $this->store->savePaymentJournal($operation);
    }

    /** One external attempt only, after the attempting record is durably committed. */
    public function execute(array $operation, array $subject, array $paymentInput = [], string $reason = '', ?callable $authorize = null): array
    {
        self::authorize($authorize);
        if ($operation['state'] !== 'prepared') throw new RuntimeException('This payment operation has already been attempted. Reconcile it before any new action.');
        $binding = $operation['data']['binding'];
        $scope = $this->assertScope($binding);
        $provider = $this->provider($binding['provider']);
        $operation = $this->commit($operation['orderId'], function () use ($operation, $binding, $scope): array {
            $this->assertCurrentScope($scope);
            $this->assertBindingScope($binding, $scope['scope']);
            if ($binding['kind'] === 'charge') {
                try { $this->chargeSubject($binding); }
                catch (RuntimeException $error) {
                    $issue = match ($error->getCode()) { 409 => 'account_closed', 412 => 'account_ineligible', default => throw $error };
                    return $this->change($operation, 'held', ['issue' => $issue]);
                }
            }
            return $this->change($operation, 'attempting');
        }, $authorize);
        if ($operation['state'] !== 'attempting') return ['operation' => $operation, 'display' => []];
        // Authority may have expired while the claim waited, and privacy may
        // have frozen the account after it committed. Re-read at dispatch.
        self::authorize($authorize);
        try { $this->assertCurrentScope($scope); }
        catch (RuntimeException) {
            return ['operation' => $this->commit($operation['orderId'], fn(): array => $this->change($operation, 'held', ['issue' => 'scope_changed']), $authorize), 'display' => []];
        }
        if ($binding['kind'] === 'charge') {
            try { $subject = $this->chargeSubject($binding); }
            catch (RuntimeException $error) {
                $issue = match ($error->getCode()) { 409 => 'account_closed', 412 => 'account_ineligible', default => throw $error };
                return ['operation' => $this->commit($operation['orderId'], fn(): array => $this->change($operation, 'held', ['issue' => $issue]), $authorize), 'display' => []];
            }
        } elseif ($binding['kind'] === 'refund') {
            $original = $this->store->paymentTransactionsByReference($binding['provider'], $binding['originalTransactionId']);
            $order = $this->store->order($binding['orderId']);
            if (count($original) !== 1 || (string) $original[0]['orderId'] !== $binding['orderId'] || $original[0]['transactionType'] !== 'charge'
                || $original[0]['status'] !== 'paid' || $original[0]['currency'] !== $binding['currency']
                || (string) $original[0]['id'] !== (string) ($subject['id'] ?? '') || PaymentContract::minor($original[0]['amount']) !== PaymentContract::minor($order['grandTotal'])
                || !in_array($order['status'], ['paid', 'partially_refunded'], true) || $binding['amountMinor'] > PaymentContract::minor($order['grandTotal']) - PaymentContract::minor($order['refundedTotal'])) {
                return ['operation' => $this->commit($operation['orderId'], fn(): array => $this->change($operation, 'held', ['issue' => 'binding_conflict']), $authorize), 'display' => []];
            }
            $subject = $original[0];
        }
        try {
            if ($binding['kind'] === 'charge' && $binding['amountMinor'] === 0) {
                $result = ['schema' => 1, 'binding' => $binding, 'outcome' => 'succeeded', 'transactionId' => 'free_' . substr($operation['id'], 4), 'observedAt' => time(), 'display' => ['brand' => 'No charge']];
            } elseif ($binding['kind'] === 'charge') $result = $provider->chargeBound($binding, $subject, $paymentInput);
            elseif ($binding['kind'] === 'refund') $result = $provider->refundBound($binding, $subject, $reason);
            else throw new RuntimeException('Disputes cannot be initiated by checkout.');
        } catch (Throwable) {
            return ['operation' => $this->commit($operation['orderId'], fn(): array => $this->change($operation, 'unknown', ['issue' => 'transport_unknown']), $authorize), 'display' => []];
        }
        return $this->receive($operation, $result, $authorize);
    }

    /** A first charge needs an open account; accepted settlement remains financial. */
    private function chargeSubject(array $binding): array
    {
        $this->assertOrderBinding($binding);
        $order = $this->store->order($binding['orderId']);
        $this->store->privacyRequireOpenMember($order['memberId']);
        $member = $this->store->member($order['memberId']);
        if (($member['status'] ?? null) !== 'active' || empty($member['emailVerifiedAt'])) throw new RuntimeException('An active, verified member account is required for a first charge.', 412);
        return $order;
    }

    /** Query only. Never repeat execute, including when the provider reports not found. */
    public function reconcile(array $operation, ?callable $authorize = null): array
    {
        if (in_array($operation['state'], ['applied','declined','ignored','confirmed'], true)) return ['operation' => $operation, 'display' => []];
        $binding = $operation['data']['binding'];
        try {
            $scope = $this->assertScope($binding);
        } catch (Throwable) {
            return ['operation' => $this->commit($operation['orderId'], fn(): array => $this->change($operation, 'held', ['issue' => 'scope_changed']), $authorize), 'display' => []];
        }
        self::authorize($authorize);
        if ($binding['kind'] === 'charge' && $binding['amountMinor'] === 0) {
            // A free operation has no provider evidence to query. Its first
            // local confirmation must obey the same current member fence.
            return $this->commit($operation['orderId'], function () use ($operation, $binding, $scope): array {
                $this->assertCurrentScope($scope);
                $this->assertBindingScope($binding, $scope['scope']);
                try { $this->chargeSubject($binding); }
                catch (RuntimeException $error) {
                    $issue = match ($error->getCode()) { 409 => 'account_closed', 412 => 'account_ineligible', default => throw $error };
                    return ['operation' => $this->change($operation, 'held', ['issue' => $issue]), 'display' => []];
                }
                $result = ['schema' => 1, 'binding' => $binding, 'outcome' => 'succeeded', 'transactionId' => 'free_' . substr($operation['id'], 4), 'observedAt' => time()];
                try { $this->assertEvidence($operation, $result); }
                catch (Throwable) { return ['operation' => $this->change($operation, 'held', ['issue' => 'malformed_result']), 'display' => []]; }
                return ['operation' => $this->change($operation, 'confirmed', ['result' => $result, 'issue' => null]), 'display' => []];
            }, $authorize);
        }
        try {
            $result = $this->provider($binding['provider'])->lookupOperation($binding);
        } catch (Throwable) {
            return ['operation' => $this->commit($operation['orderId'], fn(): array => $this->change($operation, 'held', ['issue' => 'transport_unknown']), $authorize), 'display' => []];
        }
        return $this->receive($operation, $result, $authorize);
    }

    public function receive(array $operation, array $raw, ?callable $authorize = null): array
    {
        try {
            $result = PaymentContract::result($raw, $operation['data']['binding'], $operation['createdAt']);
            $this->assertScope($result['binding']);
            $this->assertEvidence($operation, $result);
        } catch (Throwable) {
            return ['operation' => $this->commit($operation['orderId'], fn(): array => $this->change($operation, 'held', ['issue' => 'malformed_result']), $authorize), 'display' => []];
        }
        $display = $result['display']; unset($result['display']);
        $state = match ($result['outcome']) { 'succeeded','declined' => 'confirmed', 'pending' => 'pending', default => 'unknown' };
        $issue = match ($state) { 'pending' => 'pending_provider', 'unknown' => 'transport_unknown', default => null };
        return ['operation' => $this->commit($operation['orderId'], fn(): array => $this->change($operation, $state, ['result' => $result, 'issue' => $issue]), $authorize), 'display' => $display];
    }

    public function assertScope(array $binding): array
    {
        $snapshot = $this->captureScope($binding['provider']);
        $this->assertBindingScope($binding, $snapshot['scope']);
        return $snapshot;
    }

    /** Pure/local validation for a financial commit; the scope was captured before it. */
    public function assertBindingScope(array $binding, array $scope): void
    {
        foreach ($scope as $key => $value) if ($binding[$key] !== $value) throw new RuntimeException('Payment account, environment or installation no longer matches the durable operation.');
        $this->assertOrderBinding($binding);
    }

    public function assertOrderBinding(array $binding): void
    {
        $order = $this->store->order($binding['orderId']);
        if (!$order || (string) $order['id'] !== $binding['orderId'] || $order['orderNumber'] !== $binding['orderNumber']
            || $order['providerKey'] !== $binding['provider'] || $order['currency'] !== $binding['currency']
            || ($binding['kind'] === 'charge' && PaymentContract::minor($order['grandTotal']) !== $binding['amountMinor'])) throw new RuntimeException('Payment evidence does not match the authoritative order.');
    }

    public function assertEvidence(array $operation, array $result): void
    {
        $prior = $operation['data']['result'];
        if (is_array($prior)) {
            if (in_array($prior['outcome'], ['succeeded','declined'], true) && ($result['outcome'] !== $prior['outcome'] || $result['transactionId'] !== $prior['transactionId'])) throw new RuntimeException('Conflicting terminal payment evidence needs reconciliation.');
            // The historic sandbox emits a temporary id for its processing demo.
            // Other adapters must leave transactionId null until the identity is stable.
            if ($prior['transactionId'] !== null && $result['transactionId'] !== null && $prior['transactionId'] !== $result['transactionId']
                && !($operation['data']['binding']['provider'] === 'sandbox' && $prior['outcome'] === 'pending')) throw new RuntimeException('Payment transaction identity changed.');
        }
        $localId = $operation['data']['transactionId'];
        if ($localId !== null) {
            $local = array_values(array_filter($this->store->paymentTransactionsForOrder($operation['orderId']), static fn(array $row): bool => (string) $row['id'] === $localId));
            if (count($local) !== 1 || $local[0]['providerKey'] !== $result['binding']['provider'] || $local[0]['transactionType'] !== $result['binding']['kind']
                || PaymentContract::minor($local[0]['amount']) !== $result['binding']['amountMinor'] || $local[0]['currency'] !== $result['binding']['currency']
                || (isset($local[0]['payload']['operationId']) && $local[0]['payload']['operationId'] !== $operation['id'])) throw new RuntimeException('The operation has no exact local financial record binding.');
        }
        if ($result['transactionId'] === null) return;
        $matches = $this->store->paymentTransactionsByReference($result['binding']['provider'], $result['transactionId']);
        if (count($matches) > 1 || ($matches !== [] && $localId === null)) throw new RuntimeException('Payment reference is ambiguous or has no exact operation binding.');
        foreach ($matches as $transaction) {
            if ((string) $transaction['orderId'] !== $operation['orderId'] || $transaction['transactionType'] !== $result['binding']['kind']
                || PaymentContract::minor($transaction['amount']) !== $result['binding']['amountMinor'] || $transaction['currency'] !== $result['binding']['currency']
                || ($operation['data']['transactionId'] !== null && (string) $transaction['id'] !== $operation['data']['transactionId'])) throw new RuntimeException('Payment reference is already bound to different financial facts.');
        }
        foreach ($this->store->paymentOperationsByReference($result['binding']['provider'], $result['transactionId']) as $row) if ($row['id'] !== $operation['id']) throw new RuntimeException('Payment reference is already bound to another operation.');
    }
}
