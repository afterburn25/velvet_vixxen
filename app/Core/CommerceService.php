<?php
declare(strict_types=1);

namespace App\Core;

use App\Payments\PaymentProviderRegistry;
use RuntimeException;
use Throwable;

final class CommerceService
{
    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
        private readonly MailService $mailer,
        private readonly PaymentProviderRegistry $providers,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $cartItems
     * @param array<string,mixed>|null $member
     * @return array<string,mixed>
     */
    public function quote(array $cartItems, ?array $member, string $couponCode = ''): array
    {
        if ($cartItems === []) {
            throw new RuntimeException('Your cart is empty.');
        }
        $memberId = $member !== null ? (string) ($member['id'] ?? '') : null;
        $rows = [];
        $currency = null;
        foreach ($cartItems as $cartItem) {
            if (!is_array($cartItem)) continue;
            $row = $this->resolveCartItem($cartItem, $member);
            if ($currency === null) {
                $currency = (string) $row['currency'];
            } elseif ($currency !== (string) $row['currency']) {
                throw new RuntimeException('All cart items must use the same currency.');
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            throw new RuntimeException('Your cart does not contain any purchasable items.');
        }
        $merchandise = array_filter($rows, static fn(array $row): bool => $row['itemType'] === 'merchandise');
        if ($merchandise !== [] && count($merchandise) !== count($rows)) {
            throw new RuntimeException('Merchandise must be checked out separately from memberships and PPV content.');
        }
        if ($merchandise === [] && count($rows) !== 1) {
            throw new RuntimeException('Choose one membership or content release for direct checkout.');
        }
        $keys = [];
        foreach ($rows as &$row) {
            if (isset($keys[$row['key']])) throw new RuntimeException('Duplicate checkout items are not allowed.');
            $keys[$row['key']] = true;
            $row['quantity'] = (int) ($row['quantity'] ?? 1);
            $row['lineSubtotal'] = round((float) $row['unitPrice'] * $row['quantity'], 2);
        }
        unset($row);

        $subtotal = round(array_sum(array_column($rows, 'lineSubtotal')), 2);
        $coupon = null;
        $discountTotal = 0.0;
        $couponCode = strtoupper(trim($couponCode));
        if ($couponCode !== '') {
            $coupon = $this->validateCoupon($couponCode, $memberId, $subtotal, $rows);
            $couponScope = (string) ($coupon['scope'] ?? 'all');
            foreach ($rows as &$couponRow) {
                $couponRow['couponEligible'] = $couponScope === 'all' || (string) ($couponRow['scope'] ?? '') === $couponScope;
            }
            unset($couponRow);
            $eligibleSubtotal = round(array_sum(array_map(static fn(array $row): float => !empty($row['couponEligible']) ? (float) $row['lineSubtotal'] : 0.0, $rows)), 2);
            if ($coupon['discountType'] === 'percent') {
                $discountTotal = round($eligibleSubtotal * ((float) $coupon['value'] / 100), 2);
            } else {
                $discountTotal = min($eligibleSubtotal, round((float) $coupon['value'], 2));
            }
            if ($coupon['maximumDiscount'] !== null) {
                $discountTotal = min($discountTotal, (float) $coupon['maximumDiscount']);
            }
        }

        $remainingDiscount = $discountTotal;
        $eligibleIndices = [];
        $eligibleTotal = 0.0;
        foreach ($rows as $index => $row) {
            if (!empty($row['couponEligible'])) {
                $eligibleIndices[] = $index;
                $eligibleTotal += (float) $row['lineSubtotal'];
            }
        }
        $lastEligibleIndex = $eligibleIndices !== [] ? $eligibleIndices[array_key_last($eligibleIndices)] : null;
        foreach ($rows as $index => &$row) {
            $lineDiscount = 0.0;
            if ($discountTotal > 0 && !empty($row['couponEligible']) && $eligibleTotal > 0) {
                $lineDiscount = $index === $lastEligibleIndex
                    ? $remainingDiscount
                    : round($discountTotal * ((float) $row['lineSubtotal'] / $eligibleTotal), 2);
                $lineDiscount = min((float) $row['lineSubtotal'], max(0.0, $lineDiscount));
                $remainingDiscount = round($remainingDiscount - $lineDiscount, 2);
            }
            $row['discountTotal'] = $lineDiscount;
            $row['lineTotal'] = round((float) $row['lineSubtotal'] - $lineDiscount, 2);
        }
        unset($row);

        $commerceSettings = (array) $this->store->setting('commerce', []);
        $taxRate = max(0, min(100, (float) ($commerceSettings['tax_rate_percent'] ?? 0)));
        $taxable = max(0, round($subtotal - $discountTotal, 2));
        $taxTotal = round($taxable * ($taxRate / 100), 2);
        $shippingTotal = round(array_sum(array_map(static fn(array $row): float => !empty($row['metadata']['requiresShipping']) ? (float) $row['metadata']['shippingPrice'] * $row['quantity'] : 0.0, $rows)), 2);
        $requiresShipping = (bool) array_filter($rows, static fn(array $row): bool => !empty($row['metadata']['requiresShipping']));
        $grandTotal = round($taxable + $taxTotal + $shippingTotal, 2);

        return [
            'items' => $rows,
            'subtotal' => $subtotal,
            'discountTotal' => $discountTotal,
            'taxRate' => $taxRate,
            'taxTotal' => $taxTotal,
            'grandTotal' => $grandTotal,
            'shippingTotal' => $shippingTotal,
            'requiresShipping' => $requiresShipping,
            'checkoutFlow' => $merchandise !== [] ? 'merchandise' : ($rows[0]['itemType'] === 'plan' ? 'membership' : 'content'),
            'currency' => $currency ?? strtoupper((string) $this->config->get('currency', 'USD')),
            'coupon' => $coupon,
            'couponCode' => $coupon['code'] ?? null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $cartItems
     * @param array<string,mixed> $paymentInput
     * @return array{order:array<string,mixed>,transaction:array<string,mixed>,quote:array<string,mixed>,message:string}
     */
    public function checkout(string|int $memberId, array $cartItems, string $couponCode, string $providerKey, array $paymentInput, string $idempotencyKey): array
    {
        return $this->store->withCommerceLock(fn(): array => $this->checkoutLocked($memberId, $cartItems, $couponCode, $providerKey, $paymentInput, $idempotencyKey));
    }

    private function checkoutLocked(string|int $memberId, array $cartItems, string $couponCode, string $providerKey, array $paymentInput, string $idempotencyKey): array
    {
        $member = $this->store->member($memberId);
        if (!$member || (string) ($member['status'] ?? '') !== 'active' || empty($member['emailVerifiedAt'])) {
            throw new RuntimeException('An active, verified member account is required for checkout.');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new RuntimeException('Checkout session is missing. Refresh the page and try again.');
        }
        $existing = $this->store->order($idempotencyKey);
        if ($existing) {
            if ((string) $existing['memberId'] !== (string) $member['id']) throw new RuntimeException('Checkout session does not belong to this member.');
            $transactions = $this->store->paymentTransactions((string) $existing['id']);
            return [
                'order' => $existing,
                'transaction' => $transactions[0] ?? [],
                'quote' => [
                    'items' => $existing['items'] ?? [], 'subtotal' => $existing['subtotal'], 'discountTotal' => $existing['discountTotal'],
                    'taxTotal' => $existing['taxTotal'], 'grandTotal' => $existing['grandTotal'], 'currency' => $existing['currency'],
                    'couponCode' => $existing['couponCode'],
                ],
                'message' => 'This checkout was already processed.',
            ];
        }

        $providerConfig = $this->store->paymentProvider($providerKey);
        if (!$providerConfig || empty($providerConfig['enabled'])) {
            throw new RuntimeException('The selected payment provider is not enabled.');
        }
        $provider = $this->providers->get($providerKey);
        $quote = $this->quote($cartItems, $member, $couponCode);
        $shippingAddress = $quote['requiresShipping'] ? MerchandiseService::shippingAddress((array) ($paymentInput['shipping'] ?? [])) : [];
        foreach ($quote['items'] as $row) {
            if ((string) ($row['itemType'] ?? '') === 'plan' && !$provider->supportsRecurring()) {
                throw new RuntimeException('The selected payment provider does not support recurring memberships.');
            }
        }

        $orderItems = array_map(static fn(array $row): array => [
            'itemType' => $row['itemType'], 'itemId' => $row['itemId'], 'title' => $row['title'], 'quantity' => $row['quantity'],
            'unitPrice' => $row['unitPrice'], 'discountTotal' => $row['discountTotal'], 'lineTotal' => $row['lineTotal'],
            'metadata' => $row['metadata'], 'fulfillmentStatus' => 'pending',
        ], $quote['items']);
        $attribution = MarketingAttributionBridge::order($this->config, $member, (string) $quote['couponCode']);
        $order = $this->store->createOrder([
            'memberId' => (string) $member['id'],
            'status' => 'pending',
            'subtotal' => $quote['subtotal'], 'discountTotal' => $quote['discountTotal'], 'taxTotal' => $quote['taxTotal'],
            'grandTotal' => $quote['grandTotal'], 'currency' => $quote['currency'], 'couponCode' => $quote['couponCode'],
            'providerKey' => $providerKey, 'idempotencyKey' => $idempotencyKey, 'billingEmail' => (string) $member['email'],
            'metadata' => ['taxRate' => $quote['taxRate'], 'checkoutVersion' => \VELVET_VIXXEN_VERSION, 'checkoutFlow' => $quote['checkoutFlow'], 'shippingTotal' => $quote['shippingTotal'], 'requiresShipping' => $quote['requiresShipping'], 'shippingAddress' => $shippingAddress, 'shippingStatus' => 'pending']
                + ($attribution === null ? [] : ['attribution' => $attribution]),
        ], $orderItems);
        if ($attribution !== null) MarketingAttributionBridge::project($this->config, $this->store, 'checkout', (string) $order['id']);

        try {
            $this->store->reserveMerchandiseStock($order);
            $charge = $quote['grandTotal'] <= 0
                ? ['ok'=>true,'status'=>'paid','providerTransactionId'=>'free_'.bin2hex(random_bytes(8)),'message'=>'No payment was required.','last4'=>null,'brand'=>'No charge','payload'=>['free'=>true]]
                : $provider->charge($order, $paymentInput);
        } catch (Throwable $error) {
            $this->store->settleMerchandiseStock($order, true);
            $this->store->updateOrder((string) $order['id'], ['status' => 'failed']);
            throw $error;
        }
        $transaction = $this->store->savePaymentTransaction([
            'orderId' => (string) $order['id'], 'providerKey' => $providerKey,
            'providerTransactionId' => $charge['providerTransactionId'] ?? null, 'transactionType' => 'charge',
            'status' => (string) ($charge['status'] ?? ($charge['ok'] ? 'paid' : 'failed')),
            'amount' => (float) $quote['grandTotal'], 'currency' => (string) $quote['currency'],
            'last4' => $charge['last4'] ?? null, 'brand' => $charge['brand'] ?? null, 'payload' => (array) ($charge['payload'] ?? []),
        ]);

        if (!empty($charge['ok'])) {
            $order = $this->store->updateOrder((string) $order['id'], ['status'=>'paid','paidAt'=>now_iso(),'providerCheckoutId'=>$charge['providerTransactionId']??null]);
            $order = $this->fulfillOrder($order);
            if ($quote['coupon'] !== null && $quote['discountTotal'] > 0) {
                $this->store->recordCouponRedemption((string) $quote['coupon']['id'], (string) $member['id'], (string) $order['id'], (float) $quote['discountTotal']);
            }
            $this->sendReceipt($member, $order);
            $this->store->recordMemberEvent((string) $member['id'], 'commerce.order.paid', ['orderId'=>(string)$order['id'],'orderNumber'=>$order['orderNumber'],'amount'=>$order['grandTotal']]);
        } else {
            $status = (string) ($charge['status'] ?? 'failed');
            $order = $this->store->updateOrder((string) $order['id'], ['status'=>$status === 'processing' ? 'processing' : 'failed','providerCheckoutId'=>$charge['providerTransactionId']??null]);
            if ($status !== 'processing') $this->store->settleMerchandiseStock($order, true);
            $this->store->recordMemberEvent((string) $member['id'], 'commerce.order.failed', ['orderId'=>(string)$order['id'],'message'=>$charge['message']??'Payment failed.']);
        }

        if ($attribution !== null) MarketingAttributionBridge::project($this->config, $this->store, 'checkout', (string) $order['id']);
        return ['order'=>$order,'transaction'=>$transaction,'quote'=>$quote,'message'=>(string)($charge['message']??'Checkout complete.')];
    }

    /** @return array<string,mixed> */
    public function fulfillOrder(array $order): array
    {
        // A delayed paid event may update retained finances, but cannot reopen erased access.
        if ($this->store->privacyMemberErased((string) ($order['memberId'] ?? ''))) return $order;
        if ((string) ($order['status'] ?? '') !== 'paid') {
            return $order;
        }
        $fulfilledItems = [];
        $startedSubscriptions = [];
        foreach ((array) ($order['items'] ?? $this->store->orderItems((string) $order['id'])) as $item) {
            if ((string) ($item['fulfillmentStatus'] ?? '') === 'fulfilled') continue;
            $metadata = (array) ($item['metadata'] ?? []);
            $type = (string) ($item['itemType'] ?? '');
            $itemId = (string) ($item['itemId'] ?? '');
            $fulfillment = [];
            if ($type === 'merchandise') {
                if (empty($metadata['inventoryCommitted'])) {
                    $this->store->settleMerchandiseStock($order);
                    $this->store->updateOrderItem((string) $order['id'], (string) $item['id'], ['metadata' => ['inventoryCommitted' => true]]);
                    $fulfilledItems[] = ['itemType' => $type, 'itemId' => $itemId, 'fulfillment' => ['inventoryCommitted' => true]];
                }
                continue;
            }
            if ($type === 'plan') {
                $plan = $this->store->membershipPlan($itemId);
                if (!$plan) throw new RuntimeException('Membership plan no longer exists.');
                $trialDays = max(0, (int) ($plan['trialDays'] ?? 0));
                $start = now_iso();
                $end = $trialDays > 0
                    ? gmdate('c', time() + $trialDays * 86400)
                    : $this->nextPeriodEnd($start, (string) $plan['billingInterval'], (int) $plan['intervalCount']);
                $subscription = $this->store->saveSubscription([
                    'memberId' => (string) $order['memberId'], 'planId' => $itemId,
                    'status' => $trialDays > 0 ? 'trialing' : 'active', 'providerKey' => (string) ($order['providerKey'] ?? 'sandbox'),
                    'providerSubscriptionId' => 'sub_' . bin2hex(random_bytes(10)), 'currentPeriodStart' => $start, 'currentPeriodEnd' => $end,
                    'cancelAtPeriodEnd' => false,
                ]);
                $fulfillment = ['subscriptionId'=>(string)$subscription['id'],'currentPeriodEnd'=>$end];
                $startedSubscriptions[] = $subscription;
            } elseif (in_array($type, ['gallery','video'], true)) {
                $mode = (string) ($metadata['purchaseMode'] ?? 'purchase');
                $expiresAt = null;
                $source = 'purchase';
                if ($mode === 'rental') {
                    $hours = max(1, (int) ($metadata['rentalHours'] ?? 48));
                    $expiresAt = gmdate('c', time() + $hours * 3600);
                    $source = 'rental';
                }
                $entitlement = $this->store->grantEntitlement((string) $order['memberId'], $type, $itemId, $source, (string) $order['id'], $expiresAt, ['orderNumber'=>$order['orderNumber'],'purchaseMode'=>$mode]);
                $fulfillment = ['entitlementId'=>(string)$entitlement['id'],'expiresAt'=>$expiresAt];
            } elseif ($type === 'bundle') {
                $bundle = $this->store->bundle($itemId);
                if (!$bundle) throw new RuntimeException('Bundle no longer exists.');
                $ids = [];
                foreach ((array) ($bundle['items'] ?? []) as $bundleItem) {
                    $contentType = (string) ($bundleItem['itemType'] ?? '');
                    $contentId = (string) ($bundleItem['itemId'] ?? '');
                    if (!in_array($contentType, ['gallery','video'], true) || $contentId === '') continue;
                    $entitlement = $this->store->grantEntitlement((string) $order['memberId'], $contentType, $contentId, 'bundle', (string) $order['id'], null, ['bundleId'=>$itemId,'orderNumber'=>$order['orderNumber']]);
                    $ids[] = (string) $entitlement['id'];
                }
                $fulfillment = ['entitlementIds'=>$ids,'bundleId'=>$itemId];
            }
            $this->store->updateOrderItem((string) $order['id'], (string) $item['id'], ['fulfillmentStatus'=>'fulfilled','metadata'=>$fulfillment]);
            $fulfilledItems[] = ['itemType' => $type, 'itemId' => $itemId, 'fulfillment' => $fulfillment];
        }
        $resolved = $this->store->order((string) $order['id']) ?? $order;
        if ($fulfilledItems !== []) {
            $this->dispatchAddonEvent('order.completed', ['order' => $resolved, 'items' => $fulfilledItems]);
            foreach ($startedSubscriptions as $subscription) {
                $this->dispatchAddonEvent('subscription.started', ['subscription' => $subscription, 'order' => $resolved]);
            }
        }
        return $resolved;
    }

    /** @return array<string,mixed> */
    public function refundOrder(string|int $orderId, string $reason = ''): array
    {
        return $this->store->withCommerceLock(fn(): array => $this->refundOrderLocked($orderId, $reason));
    }

    private function refundOrderLocked(string|int $orderId, string $reason): array
    {
        $order = $this->store->order($orderId);
        if (!$order || !in_array((string) ($order['status'] ?? ''), ['paid','partially_refunded'], true)) {
            throw new RuntimeException('Only a paid order can be refunded.');
        }
        $charge = null;
        foreach ($this->store->paymentTransactions((string) $order['id']) as $transaction) {
            if ((string) ($transaction['transactionType'] ?? '') === 'charge' && (string) ($transaction['status'] ?? '') === 'paid') {
                $charge = $transaction;
                break;
            }
        }
        if (!$charge) throw new RuntimeException('The original successful charge could not be found.');
        $provider = $this->providers->get((string) ($charge['providerKey'] ?? 'sandbox'));
        $amount = max(0, round((float) $order['grandTotal'] - (float) ($order['refundedTotal'] ?? 0), 2));
        $result = $provider->refund($charge, $amount, $reason);
        if (empty($result['ok'])) throw new RuntimeException((string) ($result['message'] ?? 'Refund failed.'));
        $this->store->savePaymentTransaction([
            'orderId'=>(string)$order['id'],'providerKey'=>(string)$charge['providerKey'],'providerTransactionId'=>$result['providerTransactionId']??null,
            'transactionType'=>'refund','status'=>'refunded','amount'=>$amount,'currency'=>(string)$order['currency'],'payload'=>(array)($result['payload']??[]),
        ]);
        $order = $this->store->updateOrder((string)$order['id'], ['status'=>'refunded','refundedTotal'=>(float)$order['grandTotal'],'metadata'=>['refundReason'=>$reason,'refundedAt'=>now_iso()]]);
        $this->store->settleMerchandiseStock($order, true);
        $this->store->revokeEntitlementsBySource('purchase', (string) $order['id']);
        $this->store->revokeEntitlementsBySource('rental', (string) $order['id']);
        $this->store->revokeEntitlementsBySource('bundle', (string) $order['id']);
        foreach ((array) $order['items'] as $item) {
            $subscriptionId = (string) (($item['metadata']['subscriptionId'] ?? ''));
            if ($subscriptionId === '') continue;
            foreach ($this->store->subscriptions((string) $order['memberId']) as $subscription) {
                if ((string) ($subscription['id'] ?? '') !== $subscriptionId) continue;
                $this->store->saveSubscription(array_merge($subscription, ['status'=>'cancelled','cancelledAt'=>now_iso(),'cancelAtPeriodEnd'=>false]));
                break;
            }
        }
        $this->store->recordMemberEvent((string)$order['memberId'],'commerce.order.refunded',['orderId'=>(string)$order['id'],'amount'=>$amount]);
        $this->dispatchAddonEvent('order.refunded', ['order' => $order, 'amount' => $amount, 'reason' => $reason, 'source' => 'admin']);
        return $order;
    }

    public function cancelMerchandiseOrder(string|int $orderId): array
    {
        return $this->store->withCommerceLock(function () use ($orderId): array {
            $order = $this->store->order($orderId);
            if (!$order || ($order['metadata']['checkoutFlow'] ?? '') !== 'merchandise' || !in_array((string) $order['status'], ['pending', 'processing'], true) || ($order['providerKey'] ?? '') !== 'sandbox') {
                throw new RuntimeException('Only a pending sandbox merchandise order can be cancelled. Refund a paid order instead.');
            }
            $this->store->settleMerchandiseStock($order, true);
            return $this->store->updateOrder((string) $order['id'], ['status' => 'cancelled', 'metadata' => ['cancelledAt' => now_iso()]]);
        });
    }

    public function cancelSubscription(string|int $memberId, string|int $subscriptionId, bool $atPeriodEnd = true): array
    {
        foreach ($this->store->subscriptions((string) $memberId) as $subscription) {
            if ((string) ($subscription['id'] ?? '') !== (string) $subscriptionId) continue;
            $changes = $atPeriodEnd
                ? ['cancelAtPeriodEnd'=>true]
                : ['status'=>'cancelled','cancelAtPeriodEnd'=>false,'cancelledAt'=>now_iso(),'currentPeriodEnd'=>now_iso()];
            $saved = $this->store->saveSubscription(array_merge($subscription, $changes));
            $this->store->recordMemberEvent((string)$memberId,'member.subscription.cancel_requested',['subscriptionId'=>(string)$subscriptionId,'atPeriodEnd'=>$atPeriodEnd]);
            $this->dispatchAddonEvent($atPeriodEnd ? 'subscription.cancel_requested' : 'subscription.cancelled', ['subscription' => $saved, 'memberId' => (string) $memberId]);
            return $saved;
        }
        throw new RuntimeException('Subscription not found.');
    }

    public function resumeSubscription(string|int $memberId, string|int $subscriptionId): array
    {
        foreach ($this->store->subscriptions((string) $memberId) as $subscription) {
            if ((string) ($subscription['id'] ?? '') !== (string) $subscriptionId) continue;
            if (!in_array((string)($subscription['status']??''),['active','trialing'],true)) throw new RuntimeException('Only an active subscription can be resumed.');
            $saved=$this->store->saveSubscription(array_merge($subscription,['cancelAtPeriodEnd'=>false,'cancelledAt'=>null]));
            $this->store->recordMemberEvent((string)$memberId,'member.subscription.resumed',['subscriptionId'=>(string)$subscriptionId]);
            return $saved;
        }
        throw new RuntimeException('Subscription not found.');
    }

    /** @return array{processed:int,renewed:int,expired:int,failed:int,deferred:int} */
    public function processDueSubscriptions(): array
    {
        $stats = ['processed' => 0, 'renewed' => 0, 'expired' => 0, 'failed' => 0, 'deferred' => 0];

        foreach ($this->store->subscriptions(null) as $subscription) {
            $periodEnd = strtotime((string) ($subscription['currentPeriodEnd'] ?? '')) ?: 0;
            if ($periodEnd === 0 || $periodEnd > time()) {
                continue;
            }
            if (!in_array((string) ($subscription['status'] ?? ''), ['active', 'trialing', 'past_due'], true)) {
                continue;
            }

            $stats['processed']++;
            if (!empty($subscription['cancelAtPeriodEnd'])) {
                $this->store->saveSubscription(array_merge($subscription, [
                    'status' => 'expired',
                    'cancelledAt' => now_iso(),
                    'cancelAtPeriodEnd' => false,
                ]));
                $stats['expired']++;
                continue;
            }

            $providerKey = (string) ($subscription['providerKey'] ?? 'sandbox');
            if ($providerKey !== 'sandbox') {
                // A live provider must charge or renew externally and confirm the
                // result through its signed webhook. Never fabricate a paid renewal.
                $this->store->recordMemberEvent((string) $subscription['memberId'], 'commerce.subscription.renewal_deferred', [
                    'subscriptionId' => (string) $subscription['id'],
                    'providerKey' => $providerKey,
                    'reason' => 'Awaiting payment-provider renewal webhook.',
                ]);
                $stats['deferred']++;
                continue;
            }

            try {
                $plan = $this->store->membershipPlan((string) $subscription['planId']);
                if (!$plan) {
                    throw new RuntimeException('Plan missing.');
                }
                $member = $this->store->member((string) $subscription['memberId']);
                if (!$member) {
                    throw new RuntimeException('Member missing.');
                }

                $periodStart = now_iso();
                $newPeriodEnd = $this->nextPeriodEnd(
                    $periodStart,
                    (string) $plan['billingInterval'],
                    (int) $plan['intervalCount']
                );
                $idempotencyKey = 'renewal:' . (string) $subscription['id'] . ':' . gmdate('Ymd', $periodEnd);
                $existing = $this->store->order($idempotencyKey);
                if ($existing && (string) ($existing['status'] ?? '') === 'paid') {
                    $this->store->saveSubscription(array_merge($subscription, [
                        'status' => 'active',
                        'currentPeriodStart' => $periodStart,
                        'currentPeriodEnd' => $newPeriodEnd,
                        'graceEndsAt' => null,
                    ]));
                    $stats['renewed']++;
                    continue;
                }

                $order = $this->store->createOrder([
                    'memberId' => (string) $member['id'],
                    'status' => 'paid',
                    'subtotal' => (float) $plan['price'],
                    'discountTotal' => 0,
                    'taxTotal' => 0,
                    'grandTotal' => (float) $plan['price'],
                    'currency' => (string) $plan['currency'],
                    'providerKey' => 'sandbox',
                    'idempotencyKey' => $idempotencyKey,
                    'billingEmail' => (string) $member['email'],
                    'paidAt' => now_iso(),
                    'metadata' => ['renewal' => true, 'subscriptionId' => (string) $subscription['id']],
                ], [[
                    'itemType' => 'plan_renewal',
                    'itemId' => (string) $plan['id'],
                    'title' => (string) $plan['name'] . ' renewal',
                    'unitPrice' => (float) $plan['price'],
                    'lineTotal' => (float) $plan['price'],
                    'discountTotal' => 0,
                    'quantity' => 1,
                    'metadata' => ['subscriptionId' => (string) $subscription['id']],
                    'fulfillmentStatus' => 'fulfilled',
                ]]);
                $this->store->savePaymentTransaction([
                    'orderId' => (string) $order['id'],
                    'subscriptionId' => (string) $subscription['id'],
                    'providerKey' => 'sandbox',
                    'providerTransactionId' => 'renew_' . bin2hex(random_bytes(10)),
                    'transactionType' => 'renewal',
                    'status' => 'paid',
                    'amount' => (float) $plan['price'],
                    'currency' => (string) $plan['currency'],
                    'payload' => ['automated' => true, 'mode' => 'sandbox'],
                ]);
                $this->store->saveSubscription(array_merge($subscription, [
                    'status' => 'active',
                    'currentPeriodStart' => $periodStart,
                    'currentPeriodEnd' => $newPeriodEnd,
                    'graceEndsAt' => null,
                ]));
                $stats['renewed']++;
            } catch (Throwable $e) {
                $graceEndsAt = gmdate('c', time() + 7 * 86400);
                $this->store->saveSubscription(array_merge($subscription, [
                    'status' => 'past_due',
                    'graceEndsAt' => $graceEndsAt,
                ]));
                $this->store->recordMemberEvent((string) $subscription['memberId'], 'commerce.subscription.renewal_failed', [
                    'subscriptionId' => (string) $subscription['id'],
                    'message' => $e->getMessage(),
                    'graceEndsAt' => $graceEndsAt,
                ]);
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /** @return array<string,mixed> */
    public function handleWebhook(string $providerKey, string $rawBody, string $signature): array
    {
        return $this->store->withCommerceLock(fn(): array => $this->handleWebhookLocked($providerKey, $rawBody, $signature));
    }

    private function handleWebhookLocked(string $providerKey, string $rawBody, string $signature): array
    {
        $providerConfig = $this->store->paymentProvider($providerKey);
        if (!$providerConfig || empty($providerConfig['enabled'])) {
            throw new RuntimeException('Payment provider is not enabled.');
        }

        $secret = (string) ($providerConfig['settings']['webhookSecret'] ?? '');
        if ($secret === '') {
            $secret = (string) $this->config->get('security.signing_key', '');
        }
        if ($secret === '') {
            throw new RuntimeException('Webhook signing is not configured.');
        }

        $parsed = $this->providers->get($providerKey)->parseWebhook($rawBody, $signature, $secret);
        if (empty($parsed['valid'])) {
            throw new RuntimeException((string) $parsed['message']);
        }
        $eventId = trim((string) $parsed['eventId']);
        if ($eventId === '') {
            throw new RuntimeException('Webhook event ID is required.');
        }
        $existing = $this->store->webhookEvent($providerKey, $eventId);
        if ($existing) {
            return ['duplicate' => true, 'event' => $existing];
        }

        $payload = (array) $parsed['payload'];
        $type = (string) $parsed['eventType'];
        $orderNumber = trim((string) ($payload['order_number'] ?? ''));
        $order = $orderNumber !== '' ? $this->store->order($orderNumber) : null;
        $eventStatus = 'processed';

        if ($order === null && str_starts_with($type, 'order.')) {
            $eventStatus = 'ignored';
        } elseif ($type === 'order.paid' && $order !== null) {
            if (in_array((string) $order['status'], ['refunded', 'partially_refunded', 'chargeback', 'cancelled'], true)) {
                return ['duplicate' => false, 'event' => $this->store->saveWebhookEvent($providerKey, $eventId, $type, $payload, 'ignored')];
            }
            $this->store->reserveMerchandiseStock($order);
            if ((string) ($order['status'] ?? '') !== 'paid') {
                // Confirm the existing charge as well as the order. Otherwise an
                // asynchronously paid order cannot use the normal refund flow.
                $charge = null;
                foreach ($this->store->paymentTransactions((string) $order['id']) as $candidate) {
                    if (($candidate['transactionType'] ?? '') === 'charge' && ($candidate['providerKey'] ?? '') === $providerKey) { $charge = $candidate; break; }
                }
                $this->store->savePaymentTransaction(array_replace($charge ?? [], [
                    'orderId' => (string) $order['id'], 'providerKey' => $providerKey,
                    'providerTransactionId' => $payload['transaction_id'] ?? ($charge['providerTransactionId'] ?? $order['providerCheckoutId'] ?? null),
                    'transactionType' => 'charge', 'status' => 'paid', 'amount' => (float) $order['grandTotal'],
                    'currency' => (string) $order['currency'],
                    'payload' => array_replace((array) ($charge['payload'] ?? []), ['confirmedByWebhook' => $eventId]),
                ]));
                $order = $this->store->updateOrder((string) $order['id'], [
                    'status' => 'paid',
                    'paidAt' => now_iso(),
                    'providerCheckoutId' => $payload['transaction_id'] ?? ($order['providerCheckoutId'] ?? null),
                ]);
            }
            $this->fulfillOrder($order);
        } elseif ($type === 'order.refunded' && $order !== null) {
            $amount = isset($payload['amount'])
                ? max(0.0, round((float) $payload['amount'], 2))
                : max(0.0, round((float) $order['grandTotal'] - (float) ($order['refundedTotal'] ?? 0), 2));
            $this->applyExternalRefund(
                $order,
                $providerKey,
                isset($payload['transaction_id']) ? (string) $payload['transaction_id'] : null,
                $amount,
                'Provider webhook refund',
                $payload,
            );
        } elseif ($type === 'order.chargeback' && $order !== null) {
            $this->store->updateOrder((string) $order['id'], [
                'status' => 'chargeback',
                'metadata' => ['chargebackAt' => now_iso(), 'chargebackPayload' => $payload],
            ]);
            $this->revokeOrderAccess($order, 'chargeback');
            $this->dispatchAddonEvent('order.chargeback', ['order' => $order, 'payload' => $payload]);
        } elseif (!in_array($type, ['order.paid', 'order.refunded', 'order.chargeback'], true)) {
            $eventStatus = 'ignored';
        }

        $event = $this->store->saveWebhookEvent($providerKey, $eventId, $type, $payload, $eventStatus);
        return ['duplicate' => false, 'event' => $event];
    }

    /**
     * Record a refund that the payment provider has already completed. This must not
     * call the provider refund API again because webhook notifications are reports
     * of an external action, not requests to perform the action a second time.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyExternalRefund(array $order, string $providerKey, ?string $transactionId, float $amount, string $reason, array $payload): array
    {
        $remaining = max(0.0, round((float) $order['grandTotal'] - (float) ($order['refundedTotal'] ?? 0), 2));
        $amount = min($remaining, max(0.0, round($amount, 2)));
        if ($amount <= 0.0) {
            return $order;
        }

        $newRefundedTotal = round((float) ($order['refundedTotal'] ?? 0) + $amount, 2);
        $fullyRefunded = $newRefundedTotal >= round((float) $order['grandTotal'], 2);
        $this->store->savePaymentTransaction([
            'orderId' => (string) $order['id'],
            'providerKey' => $providerKey,
            'providerTransactionId' => $transactionId,
            'transactionType' => 'refund',
            'status' => 'refunded',
            'amount' => $amount,
            'currency' => (string) $order['currency'],
            'payload' => $payload,
        ]);
        $order = $this->store->updateOrder((string) $order['id'], [
            'status' => $fullyRefunded ? 'refunded' : 'partially_refunded',
            'refundedTotal' => $newRefundedTotal,
            'metadata' => [
                'refundReason' => $reason,
                'refundedAt' => now_iso(),
                'refundSource' => 'webhook',
            ],
        ]);
        if ($fullyRefunded) {
            $this->revokeOrderAccess($order, 'refund');
        }
        $this->store->recordMemberEvent((string) $order['memberId'], 'commerce.order.refunded', [
            'orderId' => (string) $order['id'],
            'amount' => $amount,
            'fullRefund' => $fullyRefunded,
        ]);
        $this->dispatchAddonEvent('order.refunded', ['order' => $order, 'amount' => $amount, 'reason' => $reason, 'source' => 'webhook', 'fullRefund' => $fullyRefunded]);
        return $order;
    }

    /** @param array<string,mixed> $order */
    private function revokeOrderAccess(array $order, string $reason): void
    {
        $orderId = (string) $order['id'];
        $this->store->revokeEntitlementsBySource('purchase', $orderId);
        $this->store->revokeEntitlementsBySource('rental', $orderId);
        $this->store->revokeEntitlementsBySource('bundle', $orderId);
        $this->store->settleMerchandiseStock($order, true);
        if ($this->store->privacyMemberErased((string) ($order['memberId'] ?? ''))) return;

        foreach ((array) ($order['items'] ?? $this->store->orderItems($orderId)) as $item) {
            $subscriptionId = (string) (($item['metadata']['subscriptionId'] ?? ''));
            if ($subscriptionId === '') {
                continue;
            }
            foreach ($this->store->subscriptions((string) $order['memberId']) as $subscription) {
                if ((string) ($subscription['id'] ?? '') !== $subscriptionId) {
                    continue;
                }
                $cancelled = $this->store->saveSubscription(array_merge($subscription, [
                    'status' => 'cancelled',
                    'cancelledAt' => now_iso(),
                    'cancelAtPeriodEnd' => false,
                    'graceEndsAt' => null,
                ]));
                $this->dispatchAddonEvent('subscription.cancelled', ['subscription' => $cancelled, 'order' => $order, 'reason' => $reason]);
                break;
            }
        }
        $this->store->recordMemberEvent((string) $order['memberId'], 'commerce.order.access_revoked', [
            'orderId' => $orderId,
            'reason' => $reason,
        ]);
    }

    /** @param array<string,mixed> $cartItem @param array<string,mixed>|null $member */
    private function resolveCartItem(array $cartItem, ?array $member): array
    {
        if (($cartItem['type'] ?? '') === 'merchandise') return (new MerchandiseService($this->store))->quoteItem($cartItem);
        $type=(string)($cartItem['type']??'');$id=(string)($cartItem['id']??'');$mode=(string)($cartItem['mode']??($type==='plan'?'subscribe':'purchase'));$activeSubscription=$member!==null&&$this->store->activeSubscriptions((string)$member['id'])!==[];$discountPercent=$member!==null?$this->memberPpvDiscount((string)$member['id']):0.0;
        if($type==='plan'){$plan=$this->store->membershipPlan($id);if(!$plan||empty($plan['isActive']))throw new RuntimeException('The selected membership plan is not available.');return ['key'=>'plan:'.$id.':subscribe','itemType'=>'plan','itemId'=>$id,'mode'=>'subscribe','title'=>(string)$plan['name'],'unitPrice'=>(float)$plan['price'],'currency'=>(string)$plan['currency'],'couponEligible'=>true,'scope'=>'subscription','metadata'=>['purchaseMode'=>'subscribe','billingInterval'=>$plan['billingInterval'],'intervalCount'=>$plan['intervalCount'],'trialDays'=>$plan['trialDays']]];}
        if($type==='gallery'){$content=$this->store->gallery($id);}elseif($type==='video'){$content=$this->store->video($id);}elseif($type==='bundle'){$content=$this->store->bundle($id);}else{$content=null;}if(!$content)throw new RuntimeException('A cart item is no longer available.');
        if($type==='bundle'){$price=$activeSubscription&&$content['subscriberPrice']!==null?(float)$content['subscriberPrice']:(float)$content['price'];return ['key'=>'bundle:'.$id.':purchase','itemType'=>'bundle','itemId'=>$id,'mode'=>'purchase','title'=>(string)$content['title'],'unitPrice'=>$price,'currency'=>(string)$content['currency'],'couponEligible'=>true,'scope'=>'bundle','metadata'=>['purchaseMode'=>'purchase']];}
        if(!in_array((string)($content['status']??''),['published','unlisted'],true))throw new RuntimeException('A cart item is not published.');$standard=$content['standardPrice']??null;$subscriber=$content['subscriberPrice']??null;$rental=$content['rentalPrice']??null;$currency=(string)($content['currency']??$this->config->get('currency','USD'));
        if($mode==='rental'){$price=$rental!==null?(float)$rental:0.0;if($price<=0)throw new RuntimeException('This release is not available to rent.');return ['key'=>$type.':'.$id.':rental','itemType'=>$type,'itemId'=>$id,'mode'=>'rental','title'=>(string)$content['title'].' — rental','unitPrice'=>$price,'currency'=>$currency,'couponEligible'=>true,'scope'=>'rental','metadata'=>['purchaseMode'=>'rental','rentalHours'=>max(1,(int)($content['rentalHours']??48))]];}
        $price=$standard!==null?(float)$standard:0.0;if($activeSubscription&&$subscriber!==null)$price=(float)$subscriber;elseif($activeSubscription&&$discountPercent>0)$price=round($price*(1-$discountPercent/100),2);if($price<=0)throw new RuntimeException('This release does not have a purchase price.');return ['key'=>$type.':'.$id.':purchase','itemType'=>$type,'itemId'=>$id,'mode'=>'purchase','title'=>(string)$content['title'],'unitPrice'=>$price,'currency'=>$currency,'couponEligible'=>true,'scope'=>'ppv','metadata'=>['purchaseMode'=>'purchase','subscriberPricing'=>$activeSubscription,'ppvDiscountPercent'=>$discountPercent]];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function validateCoupon(string $code, ?string $memberId, float $subtotal, array $rows): array
    {
        $coupon=$this->store->coupon($code);if(!$coupon||empty($coupon['isActive']))throw new RuntimeException('That coupon is not active.');$now=time();$starts=strtotime((string)($coupon['startsAt']??''))?:0;$ends=strtotime((string)($coupon['endsAt']??''))?:0;if($starts>0&&$starts>$now)throw new RuntimeException('That coupon is not active yet.');if($ends>0&&$ends<=$now)throw new RuntimeException('That coupon has expired.');if((float)$coupon['minimumSubtotal']>$subtotal)throw new RuntimeException('This coupon requires a minimum subtotal of '.number_format((float)$coupon['minimumSubtotal'],2).'.');if((int)$coupon['usageLimit']>0&&(int)$coupon['redemptionCount']>=(int)$coupon['usageLimit'])throw new RuntimeException('That coupon has reached its usage limit.');if($memberId!==null&&(int)$coupon['perMemberLimit']>0&&$this->store->couponMemberRedemptionCount((string)$coupon['id'],$memberId)>=(int)$coupon['perMemberLimit'])throw new RuntimeException('You have already used that coupon the maximum number of times.');$scope=(string)$coupon['scope'];$eligible=false;foreach($rows as $row){if($scope==='all'||(string)($row['scope']??'')===$scope){$eligible=true;break;}}if(!$eligible)throw new RuntimeException('That coupon does not apply to the items in your cart.');return $coupon;
    }

    private function memberPpvDiscount(string|int $memberId): float
    {
        $max=0.0;foreach($this->store->activeSubscriptions($memberId) as $subscription){$plan=$this->store->membershipPlan((string)$subscription['planId']);$max=max($max,(float)($plan['ppvDiscountPercent']??0));}return min(100,$max);
    }

    private function nextPeriodEnd(string $start, string $interval, int $count): string
    {
        $time=strtotime($start)?:time();$count=max(1,$count);return match($interval){'year'=>gmdate('c',strtotime('+'.$count.' years',$time)),'one_time'=>gmdate('c',strtotime('+100 years',$time)),default=>gmdate('c',strtotime('+'.$count.' months',$time))};
    }

    /** @param array<string,mixed> $member @param array<string,mixed> $order */
    private function sendReceipt(array $member, array $order): void
    {
        $lines = [];
        foreach ((array) $order['items'] as $item) {
            $lines[] = '- ' . (string) $item['title'] . ' — ' . (string) $order['currency'] . ' ' . number_format((float) $item['lineTotal'], 2);
        }
        $commerceSettings = (array) $this->store->setting('commerce', []);
        $footer = trim((string) ($commerceSettings['receipt_footer'] ?? 'Thank you for supporting Nikki Wilder.'));
        $body = "Hi " . (string) $member['displayName'] . ",\n\n"
            . "Thank you for your order " . (string) $order['orderNumber'] . ".\n\n"
            . implode("\n", $lines)
            . "\n\nTotal: " . (string) $order['currency'] . ' ' . number_format((float) $order['grandTotal'], 2)
            . (($order['metadata']['checkoutFlow'] ?? '') === 'merchandise' ? "\n\nYour merchandise order is awaiting fulfillment. Track it in Billing History." : "\n\nYour purchases are available in My Library.")
            . ($footer !== '' ? "\n\n" . $footer : '');
        $this->mailer->send(
            (string) $member['id'],
            (string) $member['email'],
            'Your Velvet Vixxen receipt — ' . (string) $order['orderNumber'],
            'commerce_receipt',
            $body,
            ['orderNumber' => $order['orderNumber'], 'orderId' => $order['id']]
        );
    }


    /** @param array<string,mixed> $payload */
    private function dispatchAddonEvent(string $event, array $payload): void
    {
        if (!function_exists('addon_event') || !function_exists('is_installed') || !is_installed()) return;
        try {
            addon_event($event, $payload);
        } catch (Throwable $e) {
            // Optional extensions must never make core commerce fail.
            $this->store->logAddonActivity('', 'core.event.dispatch.failed', ['event' => $event, 'error' => $e->getMessage()], 'error');
        }
    }

}
