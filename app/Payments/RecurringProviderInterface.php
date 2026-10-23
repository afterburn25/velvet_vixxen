<?php
declare(strict_types=1);

namespace App\Payments;

/** Explicit capability; supportsRecurring() alone never authorizes lifecycle execution. */
interface RecurringProviderInterface extends BoundPaymentProviderInterface
{
    /** Authenticated evidence in RecurringContract's exact result shape. */
    public function executeRecurringCommand(array $binding): array;
    /** Query only, including when the original request is missing or uncertain. */
    public function lookupRecurringCommand(array $binding): array;
    public function collectRecurringCycle(array $binding): array;
    public function lookupRecurringCycle(array $binding): array;
    /** Authenticate before returning a normalized event; null means another event family. */
    public function parseRecurringWebhook(string $rawBody, string $signature, string $secret): ?array;
}
