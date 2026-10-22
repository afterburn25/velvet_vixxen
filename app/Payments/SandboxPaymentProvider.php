<?php
declare(strict_types=1);

namespace App\Payments;

use RuntimeException;

final class SandboxPaymentProvider implements BoundPaymentProviderInterface
{
    public function accountContext(array $configuration): array
    {
        return ['account' => hash('sha256', 'velvet-vixxen-local-sandbox'), 'environment' => 'sandbox'];
    }

    public function chargeBound(array $operation, array $order, array $paymentInput): array
    {
        $charge = $this->charge($order, $paymentInput);
        return ['schema' => 1, 'binding' => $operation, 'outcome' => match ($charge['status']) { 'paid' => 'succeeded', 'processing' => 'pending', default => 'declined' },
            'transactionId' => $charge['providerTransactionId'], 'observedAt' => time(), 'display' => ['last4' => $charge['last4'], 'brand' => $charge['brand']]];
    }

    public function refundBound(array $operation, array $transaction, string $reason = ''): array
    {
        $refund = $this->refund($transaction, $operation['amountMinor'] / 100, $reason);
        return ['schema' => 1, 'binding' => $operation, 'outcome' => 'succeeded', 'transactionId' => $refund['providerTransactionId'], 'observedAt' => time()];
    }

    public function lookupOperation(array $operation): array
    {
        // The local demo has no remote ledger. An interrupted response cannot be
        // invented; only its signed sandbox webhook can supply later evidence.
        return ['schema' => 1, 'binding' => $operation, 'outcome' => 'unknown', 'transactionId' => null, 'observedAt' => time()];
    }

    public function key(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox Test Gateway';
    }

    public function supportsRecurring(): bool
    {
        return true;
    }

    public function charge(array $order, array $paymentInput): array
    {
        $result = strtolower(trim((string) ($paymentInput['sandboxResult'] ?? 'success')));
        if (!in_array($result, ['success', 'decline', 'processing'], true)) {
            $result = 'success';
        }

        $last4 = preg_replace('/\D+/', '', (string) ($paymentInput['cardNumber'] ?? ''));
        $last4 = $last4 !== '' ? substr($last4, -4) : '4242';
        $id = 'sbx_' . bin2hex(random_bytes(12));

        return [
            'ok' => $result === 'success',
            'status' => match ($result) {
                'success' => 'paid',
                'processing' => 'processing',
                default => 'failed',
            },
            'providerTransactionId' => $id,
            'message' => match ($result) {
                'success' => 'Sandbox payment approved.',
                'processing' => 'Sandbox payment is processing.',
                default => 'Sandbox payment was declined.',
            },
            'last4' => $last4,
            'brand' => 'Test Card',
            'payload' => [
                'mode' => 'sandbox',
                'result' => $result,
                'orderNumber' => (string) ($order['orderNumber'] ?? ''),
            ],
        ];
    }

    public function refund(array $transaction, float $amount, string $reason = ''): array
    {
        if ($amount <= 0) {
            throw new RuntimeException('Refund amount must be greater than zero.');
        }
        return [
            'ok' => true,
            'status' => 'refunded',
            'providerTransactionId' => 'sbr_' . bin2hex(random_bytes(12)),
            'message' => 'Sandbox refund completed.',
            'payload' => [
                'originalTransactionId' => (string) ($transaction['providerTransactionId'] ?? ''),
                'amount' => round($amount, 2),
                'reason' => $reason,
            ],
        ];
    }

    public function parseWebhook(string $rawBody, string $signature, string $secret): array
    {
        if (strlen($rawBody) > PaymentContract::MAX_BODY_BYTES || strlen($signature) !== 64) throw new RuntimeException('Sandbox webhook envelope is invalid.');
        $expected = hash_hmac('sha256', $rawBody, $secret);
        $valid = $signature !== '' && hash_equals($expected, $signature);
        $payload = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload) || !is_string($payload['event_id'] ?? null) || !is_string($payload['type'] ?? null)) throw new RuntimeException('Sandbox webhook payload is invalid.');
        return [
            'valid' => $valid,
            'eventId' => (string) ($payload['event_id'] ?? ''),
            'eventType' => (string) ($payload['type'] ?? ''),
            'payload' => $payload,
            'message' => $valid ? 'Webhook signature accepted.' : 'Webhook signature was not accepted.',
        ];
    }
}
