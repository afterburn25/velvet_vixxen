<?php
declare(strict_types=1);

namespace App\Payments;

/**
 * Trusted adapter boundary. No production adapter is selected by this interface.
 *
 * The adapter must bind its real authenticated response to the supplied operation,
 * not simply echo the request fields beside an unverified success flag. The
 * operation ID is the stable idempotency/reconciliation key for its entire life.
 * Account is a non-secret SHA-256 identifier of the merchant account; environment
 * is sandbox, test or live. Credential rotation must not change that identity.
 *
 * parseWebhook() must authenticate the provider's actual protocol (including any
 * required timestamp/replay rules) and return valid === true and an `event` in
 * PaymentContract's normalized shape. Browser returns are never evidence.
 */
interface BoundPaymentProviderInterface extends PaymentProviderInterface
{
    /** @return array{account:string,environment:string} */
    public function accountContext(array $configuration): array;

    /** Return PaymentContract's result shape, with amounts in existing two-decimal minor units. */
    public function chargeBound(array $operation, array $order, array $paymentInput): array;

    public function refundBound(array $operation, array $transaction, string $reason = ''): array;

    /**
     * Read/query only: must never create or repeat a charge/refund. A missing or
     * eventually-consistent record is unknown, unless the protocol proves a
     * definitive non-acceptance. Unsupported/ambiguous lookup remains unknown.
     */
    public function lookupOperation(array $operation): array;
}
