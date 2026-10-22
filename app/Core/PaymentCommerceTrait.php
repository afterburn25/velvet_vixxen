<?php
declare(strict_types=1);

namespace App\Core;

use App\Payments\PaymentContract;
use App\Payments\SandboxPaymentProvider;
use RuntimeException;
use Throwable;

/** Payment orchestration shared by checkout, authenticated callbacks and query-only recovery. */
trait PaymentCommerceTrait
{
    private ?array $paymentEffectContext = null;

    private function payments(): PaymentOperationService { return new PaymentOperationService($this->store, $this->config, $this->providers); }

    private function chargeOperation(array $order): ?array
    {
        $rows = array_values(array_filter($this->store->paymentJournalForOrder((string) $order['id']), static fn(array $row): bool => $row['kind'] === 'operation' && $row['data']['binding']['kind'] === 'charge'));
        if (count($rows) > 1) throw new RuntimeException('Ambiguous charge operations need reconciliation.');
        return $rows[0] ?? null;
    }

    private function originalCharge(array $order): array
    {
        $operation = $this->chargeOperation($order);
        if ($operation) $this->payments()->assertScope($operation['data']['binding']);
        elseif (!$this->payments()->provider((string) $order['providerKey']) instanceof SandboxPaymentProvider) throw new RuntimeException('Original charge has no authenticated account/environment binding.');
        return $this->originalChargeLocal($order);
    }

    /** No adapter callbacks: re-read and bind the exact local original charge. */
    private function originalChargeLocal(array $order, ?array $scope = null): array
    {
        $operation = $this->chargeOperation($order);
        if ($operation) {
            if ($scope === null) $this->payments()->assertOrderBinding($operation['data']['binding']);
            else $this->payments()->assertBindingScope($operation['data']['binding'], $scope);
        }
        $charges = array_values(array_filter($this->store->paymentTransactionsForOrder((string) $order['id']), static fn(array $row): bool => $row['transactionType'] === 'charge' && $row['status'] === 'paid'));
        if (count($charges) !== 1 || $charges[0]['providerKey'] !== $order['providerKey'] || $charges[0]['currency'] !== $order['currency']
            || PaymentContract::minor($charges[0]['amount']) !== PaymentContract::minor($order['grandTotal'])) throw new RuntimeException('The original successful charge could not be bound to this order.');
        PaymentContract::reference($charges[0]['providerTransactionId']);
        if ($operation && ($operation['data']['transactionId'] !== (string) $charges[0]['id']
            || ($operation['data']['result']['outcome'] ?? null) !== 'succeeded'
            || $operation['data']['result']['transactionId'] !== $charges[0]['providerTransactionId'])) throw new RuntimeException('Original charge journal and local transaction do not match.');
        return $charges[0];
    }

    /** Upgrade compatibility is restricted to the installed local sandbox. */
    private function legacySandboxCharge(array $order): array
    {
        $payments = $this->payments();
        if (!$payments->provider((string) $order['providerKey']) instanceof SandboxPaymentProvider) throw new RuntimeException('An unjournaled provider payment needs adapter-specific reconciliation.');
        $existing = $this->chargeOperation($order); if ($existing) return $existing;
        $charges = array_values(array_filter($this->store->paymentTransactionsForOrder((string) $order['id']), static fn(array $row): bool => $row['transactionType'] === 'charge' && $row['providerKey'] === 'sandbox'));
        if (count($charges) > 1) throw new RuntimeException('Legacy sandbox charge is ambiguous.');
        $charge = $charges[0] ?? null;
        if ($charge && ($charge['currency'] !== $order['currency'] || PaymentContract::minor($charge['amount']) !== PaymentContract::minor($order['grandTotal']))) throw new RuntimeException('Legacy sandbox charge facts conflict.');
        $binding = $payments->binding($order, 'charge', PaymentContract::minor($order['grandTotal']));
        return $payments->commit((string) $order['id'], function () use ($payments, $binding, $charge, $order): array {
            $operation = $payments->prepare($binding, 'legacy');
            $outcome = $charge && $charge['status'] === 'paid' ? 'succeeded' : 'pending';
            $result = ['schema' => 1, 'binding' => $binding, 'outcome' => $outcome, 'transactionId' => $charge['providerTransactionId'] ?? null, 'observedAt' => time()];
            return $payments->change($operation, $outcome === 'succeeded' ? 'confirmed' : 'pending', ['result' => $result, 'transactionId' => $charge['id'] ?? null]);
        });
    }

    /** Query-only recovery API for a trusted operator/worker, not a public acknowledgement route. */
    public function reconcilePaymentOperation(string $operationId, ?callable $authorize = null): array
    {
        return $this->store->withCommerceLock(function () use ($operationId, $authorize): array {
            PaymentOperationService::authorize($authorize);
            $operation = $this->store->paymentJournal($operationId);
            if (!$operation || $operation['kind'] !== 'operation') throw new RuntimeException('Payment operation not found.');
            $received = $this->payments()->reconcile($operation, $authorize);
            $operation = $this->applyPaymentOperation($received['operation'], $received['display'], null, $authorize);
            // A crash after applying an operation may have left a received callback.
            // Its immutable authenticated envelope can finish without another charge.
            foreach ($this->store->paymentJournalForOrder($operation['orderId']) as $row) {
                if ($row['kind'] === 'event' && $row['data']['operationId'] === $operationId && in_array($row['state'], ['received','held'], true)
                    && in_array($operation['state'], ['applied','ignored'], true) && $this->paymentReceiptMatches($row, $operation)) $this->applyPaymentOperation($operation, [], $row, $authorize);
            }
            return ['operation' => $this->store->paymentJournal($operationId), 'order' => $this->store->order($operation['orderId'])];
        });
    }

    private function paymentReceiptMatches(array $receipt, array $operation): bool
    {
        $event = $receipt['data']['event'];
        return is_array($event) && $event['binding'] === $operation['data']['binding']
            && $event['transactionId'] === ($operation['data']['result']['transactionId'] ?? null)
            && $event['occurredAt'] >= $operation['createdAt'] - 300;
    }

    /** Pending and proven-retryable obligations only; interrupted external calls remain held. */
    public function retryPaymentEffects(string $orderId, ?callable $authorize = null): array
    {
        return $this->store->withCommerceLock(function () use ($orderId, $authorize): array { PaymentOperationService::authorize($authorize); $this->runPaymentEffectsLocked($orderId, $authorize); return array_values(array_filter($this->store->paymentJournalForOrder($orderId), static fn(array $row): bool => $row['kind'] === 'effect')); });
    }

    private function applyPaymentOperation(array $operation, array $display = [], ?array $receipt = null, ?callable $authorize = null): array
    {
        if (!in_array($operation['state'], ['confirmed','pending','applied','declined','ignored'], true)) return $operation;
        $payments = $this->payments(); $binding = $operation['data']['binding'];
        try {
            $scope = $payments->assertScope($binding);
            if ($binding['kind'] !== 'charge' && ($operation['data']['result']['outcome'] ?? null) === 'succeeded'
                && !in_array($operation['state'], ['applied','declined','ignored'], true)) $this->originalCharge($this->store->order($operation['orderId']));
            return $payments->commit($operation['orderId'], function () use ($payments, $operation, $binding, $display, $receipt, $scope): array {
                $payments->assertCurrentScope($scope);
                $payments->assertBindingScope($binding, $scope['scope']);
                $fresh = $this->store->paymentJournal($operation['id']);
                if ($fresh !== $operation) throw new RuntimeException('Payment operation changed before its commit.');
                $order = $this->store->order($operation['orderId']);
                if ($receipt !== null && !$this->paymentReceiptMatches($receipt, $operation)) throw new RuntimeException('Held event does not match the confirmed operation.');
                if (in_array($operation['state'], ['applied','declined','ignored'], true)) {
                    $latePaid = $receipt && $receipt['data']['eventType'] === 'order.paid' && in_array($order['status'], ['refunded','partially_refunded','chargeback','cancelled'], true);
                    if ($receipt) $this->completePaymentReceipt($receipt, $operation['state'] === 'ignored' || $latePaid ? 'ignored' : 'processed');
                    return $operation;
                }
                $result = $operation['data']['result'];
                if (!is_array($result)) throw new RuntimeException('Payment evidence is unavailable.');
                $payments->assertEvidence($operation, $result);
                $this->paymentEffectContext = $operation;
                if ($binding['kind'] === 'charge') {
                    if (in_array($order['status'], ['refunded','partially_refunded','chargeback','cancelled'], true)) {
                        $operation = $payments->change($operation, 'ignored', ['issue' => null]);
                    } else {
                        $status = match ($result['outcome']) { 'succeeded' => 'paid', 'declined' => 'failed', default => 'processing' };
                        $previous = null;
                        foreach ($this->store->paymentTransactionsForOrder($order['id']) as $candidate) if ((string) $candidate['id'] === ($operation['data']['transactionId'] ?? '')) $previous = $candidate;
                        $transaction = $this->store->savePaymentTransaction(array_replace($previous ?? [], [
                            'orderId' => $order['id'], 'providerKey' => $binding['provider'], 'providerTransactionId' => $result['transactionId'],
                            'transactionType' => 'charge', 'status' => $status, 'amount' => $binding['amountMinor'] / 100, 'currency' => $binding['currency'],
                            'last4' => $display['last4'] ?? ($previous['last4'] ?? null), 'brand' => $display['brand'] ?? ($previous['brand'] ?? null),
                            'payload' => ['operationId' => $operation['id']],
                        ]));
                        $order = $this->store->updateOrder($order['id'], ['status' => $status, 'providerCheckoutId' => $result['transactionId']]
                            + ($status === 'paid' ? ['paidAt' => $order['paidAt'] ?? now_iso()] : []));
                        if ($status === 'paid') {
                            if ($this->store->privacyMemberErased($order['memberId'])) $this->store->settleMerchandiseStock($order);
                            else $this->store->reserveMerchandiseStock($order);
                            $order = $this->fulfillOrderCore($order);
                            if (!$this->store->privacyMemberErased($order['memberId'])) {
                                if (!empty($order['couponCode']) && (float) $order['discountTotal'] > 0) {
                                    $coupon = $this->store->coupon((string) $order['couponCode']);
                                    if ($coupon) $this->store->recordCouponRedemption((string) $coupon['id'], $order['memberId'], $order['id'], (float) $order['discountTotal']);
                                }
                                $this->queuePaymentEffect($operation, 'receipt');
                            }
                            $this->store->recordMemberEvent($order['memberId'], 'commerce.order.paid', ['orderId' => $order['id'], 'orderNumber' => $order['orderNumber'], 'amount' => $order['grandTotal']]);
                        } elseif ($status === 'failed') {
                            $this->store->settleMerchandiseStock($order, true);
                            $this->store->recordMemberEvent($order['memberId'], 'commerce.order.failed', ['orderId' => $order['id'], 'message' => 'Payment was declined.']);
                        }
                        $operation = $payments->change($operation, match ($status) { 'paid' => 'applied', 'failed' => 'declined', default => 'pending' }, ['transactionId' => (string) $transaction['id'], 'issue' => $status === 'processing' ? 'pending_provider' : null]);
                    }
                } elseif ($result['outcome'] === 'declined') {
                    $operation = $payments->change($operation, 'declined', ['issue' => null]);
                } elseif ($result['outcome'] === 'succeeded') {
                    $charge = $this->originalChargeLocal($order, $scope['scope']);
                    if ($binding['originalTransactionId'] !== $charge['providerTransactionId']) throw new RuntimeException('Refund/dispute original charge does not match.');
                    if ($binding['kind'] === 'refund') {
                        $remaining = PaymentContract::minor($order['grandTotal']) - PaymentContract::minor($order['refundedTotal']);
                        if (!in_array($order['status'], ['paid','partially_refunded'], true) || $binding['amountMinor'] > $remaining) throw new RuntimeException('Refund exceeds the remaining confirmed payment or order state.');
                        $order = $this->applyExternalRefund($order, $binding['provider'], $result['transactionId'], $binding['amountMinor'] / 100,
                            $operation['data']['source'] === 'refund' ? (string) ($order['metadata']['pendingRefundReason'] ?? '') : 'Provider webhook refund', ['operationId' => $operation['id']]);
                        $transaction = $this->store->paymentTransactionsByReference($binding['provider'], $result['transactionId']);
                        if (count($transaction) !== 1) throw new RuntimeException('Refund transaction identity is ambiguous.');
                        $operation = $payments->change($operation, 'applied', ['transactionId' => (string) $transaction[0]['id'], 'issue' => null]);
                    } else {
                        if ($binding['amountMinor'] !== PaymentContract::minor($order['grandTotal'])) throw new RuntimeException('Partial chargebacks require an explicit provider integration.');
                        if (!in_array($order['status'], ['paid','partially_refunded','chargeback'], true)) throw new RuntimeException('Chargeback does not match a confirmed paid order.');
                        if ($order['status'] !== 'chargeback') {
                            $order = $this->store->updateOrder($order['id'], ['status' => 'chargeback', 'metadata' => ['chargebackAt' => now_iso()]]);
                            $this->revokeOrderAccess($order, 'chargeback');
                            $this->dispatchAddonEvent('order.chargeback', ['order' => $order]);
                        }
                        $operation = $payments->change($operation, 'applied', ['issue' => null]);
                    }
                }
                if ($binding['kind'] !== 'charge' && $operation['state'] === 'applied' && $this->store->privacyMemberErased($order['memberId'])
                    && !isset($order['metadata']['shippingStatus']) && array_filter($order['items'], static fn(array $item): bool => $item['itemType'] === 'merchandise') !== []) {
                    $this->queuePaymentEffect($operation, 'inventory');
                }
                if (isset($order['metadata']['attribution'])) $this->queuePaymentEffect($operation, 'marketing');
                if ($receipt && in_array($operation['state'], ['applied','ignored'], true)) $this->completePaymentReceipt($receipt, $operation['state'] === 'ignored' ? 'ignored' : 'processed');
                return $operation;
            }, $authorize);
        } catch (PaymentRecoveryAuthorizationException $error) { throw $error; }
        catch (Throwable) {
            // No new transport attempt is authorized. If the atomic commit already
            // became visible before an I/O error, its marker wins on the next read.
            $current = $this->store->paymentJournal($operation['id']);
            if (in_array($current['state'], ['applied','declined','ignored'], true)) return $current;
            return $payments->commit($current['orderId'], fn(): array => $payments->change($current, $current['state'], ['issue' => 'local_effect_failed']), $authorize);
        } finally { $this->paymentEffectContext = null; }
    }

    private function queuePaymentEffect(array $operation, string $effect, ?string $event = null, ?string $subscriptionId = null, ?int $amountMinor = null): void
    {
        $id = 'fx_' . hash('sha256', $operation['id'] . ':' . $effect . ':' . $event . ':' . $subscriptionId);
        if ($this->store->paymentJournal($id)) return;
        $this->store->savePaymentJournal(['id' => $id, 'kind' => 'effect', 'orderId' => $operation['orderId'], 'state' => $effect === 'inventory' ? 'held' : 'pending', 'createdAt' => time(), 'updatedAt' => time(),
            'data' => ['operationId' => $operation['id'], 'effect' => $effect, 'event' => $event, 'subscriptionId' => $subscriptionId,
                'amountMinor' => $amountMinor ?? $operation['data']['binding']['amountMinor'],
                'source' => match ($operation['data']['source']) { 'refund' => 'admin', 'webhook' => 'webhook', default => 'checkout' }, 'issue' => $effect === 'inventory' ? 'inventory_review' : null]]);
    }

    private function runPaymentEffectsLocked(string $orderId, ?callable $authorize = null): void
    {
        foreach ($this->store->paymentJournalForOrder($orderId) as $effect) {
            if ($effect['kind'] !== 'effect') continue;
            PaymentOperationService::authorize($authorize);
            if ($effect['state'] === 'attempting') {
                $this->payments()->commit($effect['orderId'], fn(): array => $this->payments()->change($effect, 'held', ['issue' => 'external_interrupted']), $authorize);
                continue;
            }
            if (!in_array($effect['state'], ['pending','retryable'], true)) continue;
            try {
                $order = $this->store->order($orderId);
                if (!$order || $this->store->privacyMemberErased($order['memberId'])) {
                    $this->payments()->commit($effect['orderId'], fn(): array => $this->payments()->change($effect, 'skipped', ['issue' => 'account_closed']), $authorize); continue;
                }
                $effect = $this->payments()->commit($effect['orderId'], function () use ($effect, $order): array {
                    if ($this->store->privacyMemberErased($order['memberId'])) return $this->payments()->change($effect, 'skipped', ['issue' => 'account_closed']);
                    $this->store->privacyRequireOpenMember($order['memberId']);
                    return $this->payments()->change($effect, 'attempting', ['issue' => null]);
                }, $authorize);
                if ($effect['state'] !== 'attempting') continue;
                // The claim may have waited behind privacy freeze. Never reuse
                // the order payload read before that wait for an external effect.
                $order = $this->store->order($orderId);
                if ($this->store->privacyMemberErased($order['memberId'])) {
                    $this->payments()->commit($effect['orderId'], fn(): array => $this->payments()->change($effect, 'skipped', ['issue' => 'account_closed']), $authorize); continue;
                }
                PaymentOperationService::authorize($authorize);
                $state = 'done'; $issue = null;
                if ($effect['data']['effect'] === 'receipt') {
                    $sent = $this->sendReceipt($this->store->member($order['memberId']), $order, $authorize);
                    $state = match ($sent['status'] ?? 'unknown') { 'sent' => 'done', 'skipped' => 'skipped', 'failed' => !empty($sent['retryable']) ? 'retryable' : 'held', default => 'held' };
                    $issue = match ($state) { 'held' => 'external_unknown', 'retryable' => 'external_retryable', 'skipped' => 'disabled', default => null };
                } elseif ($effect['data']['effect'] === 'marketing') {
                    if (!MarketingAttributionBridge::project($this->config, $this->store, 'checkout', $orderId)) { $state = 'retryable'; $issue = 'external_retryable'; }
                }
                elseif (!function_exists('addon_event') || !function_exists('is_installed') || !is_installed()) $state = 'skipped';
                else {
                    // Addons run outside the core transaction. Reauthorize and
                    // reload at their actual callback boundary, as mail does at
                    // transmission. An addon must also fence its own later work.
                    $order = $this->store->order($orderId);
                    if ($this->store->privacyMemberErased($order['memberId'])) {
                        $this->payments()->commit($effect['orderId'], fn(): array => $this->payments()->change($effect, 'skipped', ['issue' => 'account_closed']), $authorize); continue;
                    }
                    $this->store->privacyRequireOpenMember($order['memberId']);
                    $delivery = addon_runtime()->dispatchPaymentEffect($effect['data']['event'], [], fn(array $payload): ?array => $this->paymentAddonPayload($effect, $payload, $authorize));
                    $state = match ($delivery['status'] ?? 'unknown') { 'done' => 'done', 'retryable' => 'retryable', 'cancelled' => 'skipped', default => 'held' };
                    $issue = match ($state) { 'held' => 'external_unknown', 'retryable' => 'external_retryable', 'skipped' => 'account_closed', default => null };
                }
                $this->payments()->commit($effect['orderId'], fn(): array => $this->payments()->change($effect, $state, ['issue' => $issue]), $authorize);
            } catch (PaymentRecoveryAuthorizationException $error) { throw $error; }
            catch (Throwable) {
                $current = $this->store->paymentJournal($effect['id']);
                if (!in_array($current['state'], ['done','skipped'], true)) $this->payments()->commit($current['orderId'], fn(): array => $this->payments()->change($current, 'held', ['issue' => 'external_unknown']), $authorize);
            }
        }
    }

    /** Rebuild private data immediately before every actual addon listener. */
    private function paymentAddonPayload(array $effect, array $payload, ?callable $authorize = null): ?array
    {
        PaymentOperationService::authorize($authorize);
        $order = $this->store->order($effect['orderId']);
        if (!$order || $this->store->privacyMemberErased($order['memberId'])) return null;
        $this->store->privacyRequireOpenMember($order['memberId']);
        $fresh = ['order' => $order, 'paymentOperationId' => $effect['data']['operationId'], 'paymentEffectId' => $effect['id']];
        $event = $effect['data']['event'];
        if ($event === 'order.completed') $fresh['items'] = array_map(static fn(array $item): array => ['itemType' => $item['itemType'], 'itemId' => $item['itemId'], 'fulfillment' => $item['metadata']], $order['items']);
        if ($effect['data']['subscriptionId'] !== null) {
            $subscriptions = array_values(array_filter($this->store->subscriptions($order['memberId']), static fn(array $row): bool => (string) $row['id'] === $effect['data']['subscriptionId']));
            if (count($subscriptions) !== 1) throw new RuntimeException('Deferred subscription event needs review.');
            $fresh['subscription'] = $subscriptions[0];
        }
        if ($event === 'order.refunded') $fresh += ['amount' => $effect['data']['amountMinor'] / 100, 'source' => $effect['data']['source'], 'fullRefund' => $order['status'] === 'refunded', 'reason' => (string) ($order['metadata']['refundReason'] ?? '')];
        return array_replace($payload, $fresh);
    }

    private function receivePaymentWebhook(string $providerKey, string $rawBody, string $signature): array
    {
        if ($rawBody === '' || strlen($rawBody) > PaymentContract::MAX_BODY_BYTES || strlen($signature) > 4096) throw new RuntimeException('Payment webhook exceeds the supported envelope.');
        $payments = $this->payments(); $provider = $payments->provider($providerKey); $scope = $payments->scope($providerKey);
        $configuration = $this->store->paymentProvider($providerKey);
        $secret = (string) ($configuration['settings']['webhookSecret'] ?? '');
        if ($secret === '' && $provider instanceof SandboxPaymentProvider) $secret = (string) $this->config->get('security.signing_key', '');
        if ($secret === '') throw new RuntimeException('Webhook authentication is not configured.');
        $parsed = $provider->parseWebhook($rawBody, $signature, $secret);
        if (($parsed['valid'] ?? null) !== true) throw new RuntimeException('Webhook authentication was not accepted.');
        $sandbox = $provider instanceof SandboxPaymentProvider;
        if ($sandbox) {
            if (!is_array($parsed['payload'] ?? null)) throw new RuntimeException('Sandbox webhook payload is invalid.');
            $eventId = PaymentContract::reference($parsed['eventId'] ?? null);
            $type = PaymentContract::reference($parsed['eventType'] ?? null);
            $fingerprint = PaymentContract::fingerprint($parsed['payload']);
        } else {
            if (!is_array($parsed['event'] ?? null)) throw new RuntimeException('Provider must return a bound authenticated payment event.');
            $event = PaymentContract::event($parsed['event']);
            $eventId = $event['eventId']; $type = $event['eventType']; $fingerprint = PaymentContract::fingerprint($event);
            foreach ($scope as $key => $value) if ($event['binding'][$key] !== $value) throw new RuntimeException('Payment event account/environment/installation does not match.');
        }
        $receiptId = 'evt_' . hash('sha256', PaymentContract::fingerprint($scope) . ':' . $eventId);
        $receipt = $this->store->paymentJournal($receiptId);
        if ($receipt) {
            if ($receipt['kind'] !== 'event' || !hash_equals($receipt['data']['fingerprint'], $fingerprint)) throw new RuntimeException('Conflicting content reused a payment event ID.');
            if (in_array($receipt['state'], ['applied','ignored'], true)) return ['duplicate' => true, 'event' => $this->store->webhookEvent($providerKey, $eventId)];
            // Resume exactly the previously authenticated evidence. Do not map an
            // old sandbox delta again against a now-changed refund balance.
            $event = $receipt['data']['event'];
        } else {
            if ($this->store->webhookEvent($providerKey, $eventId)) throw new RuntimeException('An older or different-scope webhook ID requires reconciliation.');
            if ($sandbox) $event = $this->sandboxPaymentEvent($parsed['payload'], $eventId, $type);
            $order = $event === null ? null : $this->store->order($event['binding']['orderId']);
            if ($event !== null && !$order) {
                // An unknown order cannot authorize money/access. Keep only its
                // authenticated digest; do not claim ownership of a nonexistent row.
                $event = null;
            }
            $receipt = ['id' => $receiptId, 'kind' => 'event', 'orderId' => $event['binding']['orderId'] ?? null, 'state' => 'received', 'createdAt' => time(), 'updatedAt' => time(),
                'data' => ['provider' => $providerKey, 'account' => $scope['account'], 'environment' => $scope['environment'], 'eventId' => $eventId, 'eventType' => $type,
                    'fingerprint' => $fingerprint, 'operationId' => $event['binding']['operationId'] ?? null, 'event' => $event, 'issue' => null]];
            $write = fn(): array => $this->store->savePaymentJournal($receipt);
            $receipt = $receipt['orderId'] === null ? $this->store->paymentAtomic($write) : $payments->commit($receipt['orderId'], $write);
        }
        if ($event === null) {
            $saved = $this->store->paymentAtomic(fn(): array => $this->completePaymentReceipt($receipt, 'ignored'));
            return ['duplicate' => false, 'event' => $saved];
        }
        $binding = $event['binding'];
        try {
            $payments->assertScope($binding);
            $order = $this->store->order($binding['orderId']);
            $operation = $this->store->paymentJournal($binding['operationId']);
            if ($operation === null && $binding['kind'] === 'charge') throw new RuntimeException('Payment event has no matching durable charge intent.');
            if ($operation !== null && ($operation['kind'] !== 'operation' || $operation['data']['binding'] !== $binding)) throw new RuntimeException('Payment event operation binding conflicts.');
            if ($binding['kind'] !== 'charge') {
                $charge = $this->originalCharge($order);
                if ($binding['originalTransactionId'] !== $charge['providerTransactionId']) throw new RuntimeException('Payment event refers to another original charge.');
            }
            if ($operation && $event['occurredAt'] < $operation['createdAt'] - 300) throw new RuntimeException('Payment event predates its operation.');
            $result = ['schema' => 1, 'binding' => $binding, 'outcome' => 'succeeded', 'transactionId' => $event['transactionId'], 'observedAt' => time()];
            $operation = $payments->commit($binding['orderId'], function () use ($payments, $operation, $binding, $result): array {
                $operation ??= $payments->prepare($binding, 'webhook');
                $payments->assertEvidence($operation, $result);
                if (in_array($operation['state'], ['applied','ignored'], true)) return $operation;
                return $payments->change($operation, 'confirmed', ['result' => $result, 'issue' => null]);
            });
        } catch (Throwable) {
            $payments->commit($receipt['orderId'], fn(): array => $payments->change($receipt, 'held', ['issue' => 'binding_conflict']));
            throw new RuntimeException('Payment event binding needs reconciliation; no financial effect was applied.');
        }
        $operation = $this->applyPaymentOperation($operation, [], $receipt);
        $this->runPaymentEffectsLocked($binding['orderId']);
        if (!in_array($operation['state'], ['applied','ignored'], true)) {
            $payments->commit($receipt['orderId'], fn(): array => $payments->change($receipt, 'held', ['issue' => 'local_effect_failed']));
            throw new RuntimeException('Authenticated payment is retained for local recovery.', 503);
        }
        return ['duplicate' => false, 'event' => $this->store->webhookEvent($providerKey, $eventId)];
    }

    /** The historical signed demo format is never accepted from a non-sandbox adapter. */
    private function sandboxPaymentEvent(array $payload, string $eventId, string $type): ?array
    {
        if (!in_array($type, ['order.paid','order.refunded','order.chargeback'], true)) return null;
        $orderNumber = PaymentContract::reference($payload['order_number'] ?? null);
        $order = $this->store->order($orderNumber);
        if (!$order) return null;
        if ($order['providerKey'] !== 'sandbox') throw new RuntimeException('Sandbox event cannot affect another payment provider.');
        $payments = $this->payments();
        $operation = $this->chargeOperation($order) ?? $this->legacySandboxCharge($order);
        if ($type === 'order.paid') {
            $binding = $operation['data']['binding'];
            $transactionId = $payload['transaction_id'] ?? ($operation['data']['result']['transactionId'] ?? ('sbx_' . substr($operation['id'], 4)));
        } else {
            $charge = $this->originalCharge($order);
            $kind = $type === 'order.refunded' ? 'refund' : 'chargeback';
            $amount = array_key_exists('amount', $payload) ? PaymentContract::minor($payload['amount'])
                : ($kind === 'refund' ? PaymentContract::minor($order['grandTotal']) - PaymentContract::minor($order['refundedTotal']) : PaymentContract::minor($order['grandTotal']));
            $transactionId = $payload['transaction_id'] ?? ('sbx_event_' . substr(hash('sha256', $eventId), 0, 32));
            // Repeated sandbox reports of a full dispute are one local outcome.
            $id = 'pay_' . substr(hash('sha256', ($kind === 'chargeback' ? 'chargeback:' . $order['id'] : 'refund:' . $transactionId) . ':' . $payments->scope('sandbox')['installation']), 0, 32);
            $prior = $this->store->paymentJournal($id);
            if ($prior) { $binding = $prior['data']['binding']; if ($kind === 'chargeback') $transactionId = $prior['data']['result']['transactionId']; }
            else $binding = $payments->binding($order, $kind, $amount, $charge['providerTransactionId'], $id);
        }
        if (isset($payload['currency']) && $payload['currency'] !== $binding['currency']) throw new RuntimeException('Sandbox event currency conflicts with the order.');
        if (isset($payload['amount']) && PaymentContract::minor($payload['amount']) !== $binding['amountMinor']) throw new RuntimeException('Sandbox event amount conflicts with the operation.');
        return PaymentContract::event(['schema' => 1, 'eventId' => $eventId, 'eventType' => $type, 'binding' => $binding,
            'transactionId' => $transactionId, 'occurredAt' => $payload['occurred_at'] ?? time()]);
    }

    private function completePaymentReceipt(array $receipt, string $status): array
    {
        $data = $receipt['data']; $event = $data['event'];
        $payload = $event === null ? [] : ['order_id' => $receipt['orderId'], 'order_number' => $event['binding']['orderNumber'], 'operation_id' => $event['binding']['operationId']];
        $saved = $this->store->saveWebhookEvent($data['provider'], $data['eventId'], $data['eventType'], $payload, $status);
        $this->payments()->change($receipt, $status === 'ignored' ? 'ignored' : 'applied', ['issue' => null]);
        return $saved;
    }
}
