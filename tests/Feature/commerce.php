<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$required = [
    'public/routes/shop/index.php','public/routes/cart/index.php','public/routes/checkout/index.php','public/routes/checkout/success.php','public/routes/receipt/index.php','public/routes/account/billing.php',
    'public/routes/api/payment-webhook.php','app/Console/run_billing.php','app/Core/CartService.php','app/Core/CommerceService.php',
    'app/Core/CommerceStoreTrait.php','app/Payments/PaymentProviderInterface.php','app/Payments/SandboxPaymentProvider.php',
    'app/Payments/PaymentProviderRegistry.php','app/Views/admin/commerce.php','public/assets/css/commerce.css',
    'database/migrations/202610180008_subscriptions_and_ppv_commerce.php',
];
$missing = array_values(array_filter($required, static fn(string $path): bool => !is_file($root . '/' . $path)));
if ($missing !== []) {
    fwrite(STDERR, "Commerce files missing:\n- " . implode("\n- ", $missing) . "\n");
    exit(1);
}

$assertContains = static function (string $path, array $needles) use ($root): void {
    $content = (string) file_get_contents($root . '/' . $path);
    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            fwrite(STDERR, "{$path} is missing required behavior: {$needle}\n");
            exit(1);
        }
    }
};
$assertContains('app/Core/CommerceService.php', [
    'function quote(', 'function checkout(', 'function fulfillOrder(', 'function refundOrder(',
    'function cancelSubscription(', 'function processDueSubscriptions(', 'function handleWebhook(',
    'applyExternalRefund', 'revokeOrderAccess', 'couponMemberRedemptionCount',
]);
$assertContains('app/Core/CommerceStoreTrait.php', [
    'function paymentProviders(', 'function coupons(', 'function bundles(', 'function createOrder(',
    'function paymentTransactions(', 'function saveWebhookEvent(', 'function revokeEntitlementsBySource(',
]);
$assertContains('public/routes/checkout/index.php', ['checkout_idempotency', 'sandbox_result', 'Raw card numbers are never stored']);
$assertContains('public/routes/account/billing.php', ['cancel_subscription', 'resume_subscription', 'Orders & receipts']);
$assertContains('admin/index.php', [
    "'commerce' => ['$', 'Commerce']", "'orders' => ['▤', 'Orders']", "'coupons' => ['%', 'Coupons']", "'bundles' => ['✦', 'Bundles']", "'payments' => ['¤', 'Payments']",
    'save_coupon', 'save_bundle', 'save_commerce_settings', 'save_payment_provider', 'refund_order',
]);
$assertContains('app/Views/admin/commerce.php', ['Subscriptions and PPV', 'Orders & Transactions', 'Sandbox Gateway']);
$assertContains('public/routes/api/payment-webhook.php', ['HTTP_X_VELVET_SIGNATURE', 'handleWebhook']);
$assertContains('database/migrations/202610180008_subscriptions_and_ppv_commerce.php', [
    'payment_providers', 'coupons', 'commerce_bundles', 'commerce_bundle_items', 'coupon_redemptions',
    'payment_webhook_events', "'processing'", 'idempotency_key', 'fulfillment_status',
]);

$defaults = json_decode((string) file_get_contents($root . '/storage/defaults/data.json'), true);
if (!is_array($defaults)) {
    fwrite(STDERR, "Default data is invalid JSON.\n");
    exit(1);
}
if (empty($defaults['paymentProviders']['sandbox']['enabled'])) {
    fwrite(STDERR, "Sandbox payment provider is not enabled in default data.\n");
    exit(1);
}
if (empty($defaults['coupons']['coupon-welcome10']) || empty($defaults['bundles']['bundle-neon-night'])) {
    fwrite(STDERR, "Default coupon or commerce bundle is missing.\n");
    exit(1);
}
$video = $defaults['mediaCenter']['videos']['demo-video-ppv'] ?? null;
$gallery = $defaults['mediaCenter']['galleries']['demo-gallery-lounge'] ?? null;
if (!is_array($video) || (float) ($video['standardPrice'] ?? 0) <= 0 || (float) ($video['rentalPrice'] ?? 0) <= 0) {
    fwrite(STDERR, "Default PPV video does not demonstrate purchase and rental pricing.\n");
    exit(1);
}
if (!is_array($gallery) || (float) ($gallery['standardPrice'] ?? 0) <= 0) {
    fwrite(STDERR, "Default PPV gallery is missing its purchase price.\n");
    exit(1);
}

$header = (string) file_get_contents($root . '/app/Views/public/partials/navigation.php');
foreach (["base_url('cart/')", 'cart_service()->count()'] as $needle) {
    if (!str_contains($header, $needle)) {
        fwrite(STDERR, "Public header is missing commerce navigation behavior: {$needle}\n");
        exit(1);
    }
}
require_once $root . '/app/Core/MenuService.php';
$shopLinks = array_values(array_filter(App\Core\MenuService::defaults('header'),
    static fn(array $item): bool => $item['label'] === 'Store' && $item['url'] === 'shop/'));
if (count($shopLinks) !== 1) {
    fwrite(STDERR, "The original public header must retain its Store destination.\n");
    exit(1);
}

echo "Subscriptions & PPV Commerce feature test passed.\n";
