<?php
declare(strict_types=1);

namespace App\Payments;

use RuntimeException;

final class SandboxPaymentProvider implements PaymentProviderInterface
{
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
        $expected = hash_hmac('sha256', $rawBody, $secret);
        $valid = $signature !== '' && hash_equals($expected, $signature);
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $payload = [];
        }
        return [
            'valid' => $valid,
            'eventId' => (string) ($payload['event_id'] ?? ''),
            'eventType' => (string) ($payload['type'] ?? ''),
            'payload' => $payload,
            'message' => $valid ? 'Webhook signature accepted.' : 'Webhook signature was not accepted.',
        ];
    }
}
