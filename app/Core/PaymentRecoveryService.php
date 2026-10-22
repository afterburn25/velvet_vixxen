<?php
declare(strict_types=1);

namespace App\Core;

use App\Payments\PaymentContract;
use RuntimeException;

/** Safe owner-facing inspection; the HTTP caller supplies current-owner authorization. */
final class PaymentRecoveryService
{
    public function __construct(private readonly Store $store) {}

    public function page(?string $afterId = null, int $limit = 50, bool $openOnly = true, ?string $orderId = null): array
    {
        $page = $this->store->paymentJournalPage($afterId, $limit, $openOnly, $orderId);
        return ['rows' => array_map($this->project(...), $page['records']), 'nextCursor' => $page['nextCursor']];
    }

    public function inspect(string $journalId): array
    {
        if (preg_match('/\A(?:pay_[a-f0-9]{32}|evt_[a-f0-9]{64}|fx_[a-f0-9]{64})\z/D', $journalId) !== 1) throw new RuntimeException('Payment recovery record not found.', 404);
        $row = $this->store->paymentJournal($journalId);
        if ($row === null) throw new RuntimeException('Payment recovery record not found.', 404);
        return $this->project($row);
    }

    private function project(array $row): array
    {
        $row = PaymentContract::journal($row);
        $order = $row['orderId'] === null ? null : $this->store->order($row['orderId']);
        $operationId = $row['kind'] === 'operation' ? $row['id'] : $row['data']['operationId'];
        $operation = $row['kind'] === 'operation' ? $row : ($operationId === null ? null : $this->store->paymentJournal($operationId));
        if ($operation !== null && ($operation['kind'] !== 'operation' || $operation['orderId'] !== $row['orderId']
            || ($row['kind'] === 'event' && ($row['data']['event']['binding'] ?? null) !== $operation['data']['binding']))) {
            // A retained rejected callback may claim another order's operation.
            // It remains inspectable, without an actionable foreign identifier.
            if ($row['kind'] !== 'event') throw new RuntimeException('Payment recovery operation binding is inconsistent.');
            $operation = null;
        }
        if ($row['kind'] === 'event' && $operation === null) $operationId = null;
        $binding = $operation['data']['binding'] ?? null;
        $canReconcile = $row['kind'] === 'operation' && !in_array($row['state'], ['applied','declined','ignored'], true);
        if ($row['kind'] === 'event' && $operation !== null && in_array($row['state'], ['received','held'], true)) $canReconcile = true;
        return [
            'id' => $row['id'], 'kind' => $row['kind'], 'state' => $row['state'], 'orderId' => $row['orderId'],
            'orderNumber' => $order['orderNumber'] ?? null, 'operationId' => $operationId, 'operationType' => $binding['kind'] ?? null,
            'provider' => $binding['provider'] ?? ($row['data']['provider'] ?? null), 'amountMinor' => $binding['amountMinor'] ?? null, 'currency' => $binding['currency'] ?? null,
            'effect' => $row['kind'] === 'effect' ? $row['data']['effect'] : null, 'eventType' => $row['kind'] === 'event' ? $row['data']['eventType'] : ($row['kind'] === 'effect' ? $row['data']['event'] : null),
            'reasonCode' => $row['data']['issue'], 'createdAt' => $row['createdAt'], 'updatedAt' => $row['updatedAt'],
            'canReconcile' => $canReconcile, 'canRetryEffects' => $row['kind'] === 'effect' && in_array($row['state'], ['pending','retryable'], true),
        ];
    }
}
