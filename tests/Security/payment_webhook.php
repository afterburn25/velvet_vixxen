<?php
declare(strict_types=1);

require dirname(__DIR__) . '/TestEnvironment.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$env = velvet_test_environment('payment-webhook');
try {
    $store = $env['store'];
    $commerce = $env['commerce'];
    $member = $store->createMember([
        'displayName' => 'Webhook Member', 'email' => 'webhook@example.test', 'password' => 'WebhookVelvet12!',
        'status' => 'active', 'emailVerifiedAt' => now_iso(),
    ]);

    $checkout = $commerce->checkout(
        (string) $member['id'],
        [['type' => 'gallery', 'id' => 'demo-gallery-lounge', 'mode' => 'purchase']],
        '', 'sandbox', ['sandboxResult' => 'processing'], 'checkout:webhook:security'
    );
    $order = $checkout['order'];
    $payload = json_encode([
        'event_id' => 'evt-security-paid',
        'type' => 'order.paid',
        'order_number' => $order['orderNumber'],
    ], JSON_UNESCAPED_SLASHES);

    foreach (['', 'deadbeef', hash('sha256', (string) $payload)] as $badSignature) {
        try {
            $commerce->handleWebhook('sandbox', (string) $payload, $badSignature);
            $failures[] = 'A webhook with an invalid signature was accepted.';
        } catch (Throwable) {
        }
    }

    $signature = hash_hmac('sha256', (string) $payload, str_repeat('b', 64));
    $accepted = $commerce->handleWebhook('sandbox', (string) $payload, $signature);
    $assert(empty($accepted['duplicate']), 'Valid signed webhook was not accepted.');
    $duplicate = $commerce->handleWebhook('sandbox', (string) $payload, $signature);
    $assert(!empty($duplicate['duplicate']), 'Repeated provider event was processed twice.');

    $unknownPayload = json_encode([
        'event_id' => 'evt-security-unknown',
        'type' => 'order.paid',
        'order_number' => 'VVX-NOT-FOUND',
    ], JSON_UNESCAPED_SLASHES);
    $unknownSig = hash_hmac('sha256', (string) $unknownPayload, str_repeat('b', 64));
    $unknown = $commerce->handleWebhook('sandbox', (string) $unknownPayload, $unknownSig);
    $assert((string) ($unknown['event']['status'] ?? '') === 'ignored', 'Unknown-order webhook was not retained as ignored.');

    $stored = $store->webhookEvent('sandbox', 'evt-security-paid');
    $assert(is_array($stored) && (string) ($stored['status'] ?? '') === 'processed', 'Processed webhook was not recorded for audit/idempotency.');
    $chargebackBody = json_encode(['event_id' => 'evt-security-chargeback', 'type' => 'order.chargeback', 'order_number' => $order['orderNumber']], JSON_UNESCAPED_SLASHES);
    $commerce->handleWebhook('sandbox', $chargebackBody, hash_hmac('sha256', $chargebackBody, str_repeat('b', 64)));
    $assert($store->order($order['id'])['status'] === 'chargeback' && $store->activeEntitlement($member['id'], 'gallery', 'demo-gallery-lounge') === null, 'Chargeback did not revoke purchased gallery access.');
    $latePaid = json_encode(['event_id' => 'evt-security-late-paid', 'type' => 'order.paid', 'order_number' => $order['orderNumber']], JSON_UNESCAPED_SLASHES);
    $lateEvent = $commerce->handleWebhook('sandbox', $latePaid, hash_hmac('sha256', $latePaid, str_repeat('b', 64)));
    $assert($lateEvent['event']['status'] === 'ignored' && $store->order($order['id'])['status'] === 'chargeback' && $store->activeEntitlement($member['id'], 'gallery', 'demo-gallery-lounge') === null, 'A late payment event revived access after chargeback.');
} catch (Throwable $e) {
    $failures[] = $e::class . ': ' . $e->getMessage();
} finally {
    velvet_test_cleanup($env);
}

velvet_finish_test('Payment webhook security test', $failures);
