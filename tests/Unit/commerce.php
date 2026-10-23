<?php
declare(strict_types=1);

require dirname(__DIR__) . '/TestEnvironment.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$assertNear = static function (float $actual, float $expected, string $message) use (&$failures): void {
    if (abs($actual - $expected) > 0.011) {
        $failures[] = $message . " (expected {$expected}, got {$actual})";
    }
};

$env = velvet_test_environment('commerce');
try {
    $store = $env['store'];
    $commerce = $env['commerce'];
    $resolver = $env['resolver'];

    $member = $store->createMember([
        'displayName' => 'Commerce Member',
        'email' => 'commerce@example.test',
        'password' => 'VelvetCommerce12!',
        'status' => 'active',
        'emailVerifiedAt' => now_iso(),
    ]);
    $memberId = (string) $member['id'];

    $quote = $commerce->quote([
        ['type' => 'gallery', 'id' => 'demo-gallery-lounge', 'mode' => 'purchase'],
    ], $member, 'WELCOME10');
    $assertNear((float) $quote['subtotal'], 14.99, 'PPV gallery subtotal was incorrect.');
    $assertNear((float) $quote['discountTotal'], 1.50, 'WELCOME10 discount was incorrect.');
    $assertNear((float) $quote['grandTotal'], 13.49, 'Discounted PPV total was incorrect.');

    $purchase = $commerce->checkout(
        $memberId,
        [['type' => 'gallery', 'id' => 'demo-gallery-lounge', 'mode' => 'purchase']],
        'WELCOME10',
        'sandbox',
        ['sandboxResult' => 'success', 'cardNumber' => '4242 4242 4242 4242'],
        'checkout:test:gallery'
    );
    $order = $purchase['order'];
    $assert((string) ($order['status'] ?? '') === 'paid', 'Successful sandbox checkout did not mark the order paid.');
    $assert((string) ($purchase['transaction']['last4'] ?? '') === '4242', 'Only the test-card last four digits were not retained.');
    $transactionPayload = json_encode($purchase['transaction'], JSON_UNESCAPED_SLASHES);
    $assert(!str_contains((string) $transactionPayload, '4242 4242 4242 4242'), 'Raw card number leaked into the transaction record.');
    $gallery = $store->gallery('demo-gallery-lounge');
    $decision = $resolver->resolve('gallery', $gallery ?? [], $store->member($memberId));
    $assert(!empty($decision['allowed']) && ($decision['source'] ?? '') === 'purchase', 'Paid gallery order did not create purchase access.');
    $assert($store->couponMemberRedemptionCount('coupon-welcome10', $memberId) === 1, 'Coupon redemption was not recorded once.');

    $duplicate = $commerce->checkout(
        $memberId,
        [['type' => 'gallery', 'id' => 'demo-gallery-lounge', 'mode' => 'purchase']],
        'WELCOME10',
        'sandbox',
        ['sandboxResult' => 'success'],
        'checkout:test:gallery'
    );
    $assert((string) ($duplicate['order']['id'] ?? '') === (string) ($order['id'] ?? ''), 'Checkout idempotency created a duplicate order.');
    $assert(count($store->orders($memberId)) === 1, 'Idempotent checkout created more than one order.');

    try {
        $commerce->quote([
            ['type' => 'video', 'id' => 'demo-video-ppv', 'mode' => 'purchase'],
        ], $store->member($memberId), 'WELCOME10');
        $failures[] = 'Per-member coupon limit was not enforced.';
    } catch (Throwable $e) {
        $assert(str_contains(strtolower($e->getMessage()), 'maximum'), 'Unexpected coupon-limit error message.');
    }

    $rental = $commerce->checkout(
        $memberId,
        [['type' => 'video', 'id' => 'demo-video-ppv', 'mode' => 'rental']],
        '',
        'sandbox',
        ['sandboxResult' => 'success'],
        'checkout:test:rental'
    );
    $assert((string) ($rental['order']['status'] ?? '') === 'paid', 'Rental order was not paid.');
    $rentalEntitlement = $store->activeEntitlement($memberId, 'video', 'demo-video-ppv');
    $assert(is_array($rentalEntitlement) && (string) ($rentalEntitlement['sourceType'] ?? '') === 'rental', 'Rental entitlement was not created.');
    $assert((strtotime((string) ($rentalEntitlement['expiresAt'] ?? '')) ?: 0) > time(), 'Rental entitlement did not receive a future expiration.');

    $subscriptionOrder = $commerce->checkout(
        $memberId,
        [['type' => 'plan', 'id' => 'plan-vip', 'mode' => 'subscribe']],
        '',
        'sandbox',
        ['sandboxResult' => 'success'],
        'checkout:test:subscription'
    );
    $assert((string) ($subscriptionOrder['order']['status'] ?? '') === 'paid', 'Subscription checkout was not paid.');
    $subscriptions = $store->activeSubscriptions($memberId);
    $assert(count($subscriptions) === 1 && (string) ($subscriptions[0]['planId'] ?? '') === 'plan-vip', 'Subscription fulfillment did not create the selected plan.');

    $subscriberQuote = $commerce->quote([
        ['type' => 'video', 'id' => 'demo-video-ppv', 'mode' => 'purchase'],
    ], $store->member($memberId), '');
    $assertNear((float) $subscriberQuote['subtotal'], 13.49, 'Subscriber PPV price was not applied.');
    $assert(!empty($subscriberQuote['items'][0]['metadata']['subscriberPricing']), 'Subscriber pricing metadata was missing.');

    $bundle = $commerce->checkout(
        $memberId,
        [['type' => 'bundle', 'id' => 'bundle-neon-night', 'mode' => 'purchase']],
        '',
        'sandbox',
        ['sandboxResult' => 'success'],
        'checkout:test:bundle'
    );
    $assertNear((float) $bundle['order']['grandTotal'], 19.99, 'Subscriber bundle price was not applied.');
    $bundleGallery = $store->activeEntitlement($memberId, 'gallery', 'demo-gallery-lounge');
    $bundleVideo = $store->activeEntitlement($memberId, 'video', 'demo-video-ppv');
    $assert(is_array($bundleGallery), 'Bundle did not preserve or create gallery access.');
    $assert(is_array($bundleVideo), 'Bundle did not create video access.');

    $refunded = $commerce->refundOrder((string) $bundle['order']['id'], 'Automated test refund');
    $assert((string) ($refunded['status'] ?? '') === 'refunded', 'Full refund did not mark the order refunded.');
    $assertNear((float) ($refunded['refundedTotal'] ?? 0), 19.99, 'Refunded total was incorrect.');
    $bundleEntitlements = array_values(array_filter(
        $store->entitlements($memberId, false),
        static fn(array $row): bool => (string) ($row['sourceType'] ?? '') === 'bundle'
            && (string) ($row['sourceId'] ?? '') === (string) $bundle['order']['id']
    ));
    $assert($bundleEntitlements !== [] && count(array_filter($bundleEntitlements, static fn(array $row): bool => ($row['status'] ?? '') === 'active')) === 0, 'Refund did not revoke bundle entitlements.');

    $declined = $commerce->checkout(
        $memberId,
        [['type' => 'video', 'id' => 'demo-video-ppv', 'mode' => 'purchase']],
        '',
        'sandbox',
        ['sandboxResult' => 'decline'],
        'checkout:test:decline'
    );
    $assert((string) ($declined['order']['status'] ?? '') === 'failed', 'Declined payment did not mark the order failed.');

    $processing = $commerce->checkout(
        $memberId,
        [['type' => 'video', 'id' => 'demo-video-ppv', 'mode' => 'purchase']],
        '',
        'sandbox',
        ['sandboxResult' => 'processing'],
        'checkout:test:processing'
    );
    $processingOrder = $processing['order'];
    $assert((string) ($processingOrder['status'] ?? '') === 'processing', 'Processing payment did not retain processing status.');
    $webhookPayload = json_encode([
        'event_id' => 'evt-paid-1',
        'type' => 'order.paid',
        'order_number' => $processingOrder['orderNumber'],
        'transaction_id' => 'sbx_webhook_paid',
    ], JSON_UNESCAPED_SLASHES);
    $signature = hash_hmac('sha256', (string) $webhookPayload, str_repeat('b', 64));
    $webhook = $commerce->handleWebhook('sandbox', (string) $webhookPayload, $signature);
    $assert(empty($webhook['duplicate']), 'First webhook was incorrectly marked duplicate.');
    $paidAfterWebhook = $store->order((string) $processingOrder['id']);
    $assert((string) ($paidAfterWebhook['status'] ?? '') === 'paid', 'Paid webhook did not complete a processing order.');
    $assert(is_array($store->activeEntitlement($memberId, 'video', 'demo-video-ppv')), 'Paid webhook did not fulfill PPV access.');
    $duplicateWebhook = $commerce->handleWebhook('sandbox', (string) $webhookPayload, $signature);
    $assert(!empty($duplicateWebhook['duplicate']), 'Duplicate webhook event was not ignored idempotently.');

    $refundPayload = json_encode([
        'event_id' => 'evt-refund-1',
        'type' => 'order.refunded',
        'order_number' => $processingOrder['orderNumber'],
        'transaction_id' => 'sbx_webhook_refund',
        'amount' => $processingOrder['grandTotal'],
    ], JSON_UNESCAPED_SLASHES);
    $refundSignature = hash_hmac('sha256', (string) $refundPayload, str_repeat('b', 64));
    $commerce->handleWebhook('sandbox', (string) $refundPayload, $refundSignature);
    $webhookRefundedOrder = $store->order((string) $processingOrder['id']);
    $assert((string) ($webhookRefundedOrder['status'] ?? '') === 'refunded', 'Refund webhook did not mark the order refunded.');
    $refundTransactions = array_values(array_filter(
        $store->paymentTransactions((string) $processingOrder['id']),
        static fn(array $row): bool => (string) ($row['transactionType'] ?? '') === 'refund'
    ));
    $assert(count($refundTransactions) === 1, 'Refund webhook created an unexpected number of refund transactions.');

    try {
        $commerce->handleWebhook('sandbox', (string) $webhookPayload, 'invalid');
        $failures[] = 'Invalid webhook signature was accepted.';
    } catch (Throwable) {
    }

    $subscription = $store->activeSubscriptions($memberId)[0] ?? null;
    $assert(is_array($subscription), 'Active subscription disappeared before cancellation test.');
    if (is_array($subscription)) {
        $cancelled = $commerce->cancelSubscription($memberId, (string) $subscription['id'], true);
        $assert(!empty($cancelled['cancelAtPeriodEnd']), 'Cancel-at-period-end flag was not saved.');
        $resumed = $commerce->resumeSubscription($memberId, (string) $subscription['id']);
        $assert(empty($resumed['cancelAtPeriodEnd']), 'Subscription renewal was not resumed.');
    }

    // The local billing runner may simulate sandbox renewals, but it must never
    // fabricate a successful live-provider charge. Live renewals are deferred
    // until a signed provider webhook reports the outcome.
    if (is_array($subscription)) {
        $dueSandbox = $store->saveSubscription(array_merge($subscription, [
            'providerKey' => 'sandbox',
            'status' => 'active',
            'cancelAtPeriodEnd' => false,
            'currentPeriodEnd' => gmdate('c', time() - 60),
        ]));
        // This owned fixture simulates elapsed authoritative time, not a manual
        // access-date edit (which must never authorize an early recurring charge).
        $agreement=$store->recurringAgreementForSubscription((string)$subscription['id']);
        if($agreement){
            $agreement['periodEnd']=time()-60;$agreement['periodStart']=$agreement['periodEnd']-30*86400;
            $store->mutateFileStore(static function(array &$all)use($agreement):void{
                $all['recurringAgreements'][$agreement['id']]=$agreement;
                $all['subscriptions'][$agreement['subscriptionId']]['currentPeriodStart']=gmdate('c',$agreement['periodStart']);
            });
        }
        $renewalStats = $commerce->processDueSubscriptions();
        $assert(($renewalStats['renewed'] ?? 0) >= 1, 'Due sandbox subscription was not renewed.');
        $renewedSubscription = null;
        foreach ($store->subscriptions($memberId) as $candidate) {
            if ((string) ($candidate['id'] ?? '') === (string) ($dueSandbox['id'] ?? '')) {
                $renewedSubscription = $candidate;
                break;
            }
        }
        $assert(is_array($renewedSubscription) && (strtotime((string) ($renewedSubscription['currentPeriodEnd'] ?? '')) ?: 0) > time(), 'Sandbox renewal did not advance the billing period.');
    }

    $liveDeferred = $store->saveSubscription([
        'memberId' => $memberId,
        'planId' => 'plan-all',
        'status' => 'active',
        'providerKey' => 'live-placeholder',
        'providerSubscriptionId' => 'external-test-subscription',
        'currentPeriodStart' => gmdate('c', time() - 35 * 86400),
        'currentPeriodEnd' => gmdate('c', time() - 60),
        'cancelAtPeriodEnd' => false,
    ]);
    $ordersBeforeDeferred = count($store->orders($memberId));
    $deferredStats = $commerce->processDueSubscriptions();
    $assert(($deferredStats['deferred'] ?? 0) >= 1, 'A due live-provider subscription was not deferred to its webhook.');
    $assert(count($store->orders($memberId)) === $ordersBeforeDeferred, 'The billing runner fabricated a paid order for a live-provider renewal.');
    $deferredAfter = null;
    foreach ($store->subscriptions($memberId) as $candidate) {
        if ((string) ($candidate['id'] ?? '') === (string) ($liveDeferred['id'] ?? '')) {
            $deferredAfter = $candidate;
            break;
        }
    }
    $assert(is_array($deferredAfter) && (string) ($deferredAfter['status'] ?? '') === 'active', 'Deferred live-provider renewal unexpectedly changed subscription status.');

    $outbox = $store->mailOutbox();
    $assert(count(array_filter($outbox, static fn(array $mail): bool => ($mail['template'] ?? '') === 'commerce_receipt')) >= 4, 'Paid checkouts did not queue receipt messages.');
} catch (Throwable $e) {
    $failures[] = $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
} finally {
    velvet_test_cleanup($env);
}

velvet_finish_test('Subscriptions & PPV commerce test', $failures);
