<?php
declare(strict_types=1);

namespace App\Payments;

interface PaymentProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function supportsRecurring(): bool;

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $paymentInput
     * @return array<string,mixed>
     */
    public function charge(array $order, array $paymentInput): array;

    /**
     * @param array<string,mixed> $transaction
     * @return array<string,mixed>
     */
    public function refund(array $transaction, float $amount, string $reason = ''): array;

    /**
     * @return array{valid:bool,eventId:string,eventType:string,payload:array<string,mixed>,message:string}
     */
    public function parseWebhook(string $rawBody, string $signature, string $secret): array;
}
