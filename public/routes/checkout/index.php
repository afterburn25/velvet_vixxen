<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
require ROOT_PATH . '/app/bootstrap.php';
if (!is_installed()) redirect(base_url('install/'));

$intentService = new App\Core\CheckoutIntent();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start') {
    try {
        if (!verify_csrf()) throw new RuntimeException('Your session expired. Refresh the content page before choosing Buy Now.');
        $type = (string) ($_POST['type'] ?? '');
        $id = (string) ($_POST['id'] ?? '');
        $mode = (string) ($_POST['mode'] ?? 'purchase');
        if (!in_array($type, ['plan', 'gallery', 'video', 'bundle'], true)) throw new RuntimeException('Choose a membership or content release.');
        $mode = $type === 'plan' ? 'subscribe' : ($type === 'bundle' ? 'purchase' : $mode);
        commerce_service()->quote([['type' => $type, 'id' => $id, 'mode' => $mode]], current_member());
        $token = $intentService->start($type, $id, $mode);
        redirect(base_url('checkout/?intent=' . $token));
    } catch (Throwable $exception) {
        http_response_code(422);
        $pageTitle = 'Checkout'; $activeNav = 'shop';
        require root_path('app/Views/public/partials/header.php');
        echo '<div class="commerce-main"><div class="member-alert error" role="alert">' . e($exception->getMessage()) . '</div><a class="button button--ghost" href="' . e(base_url('shop/')) . '">Return to the Store</a></div>';
        require root_path('app/Views/public/partials/footer.php');
        exit;
    }
}
$member = require_member();
$commerceSettings = (array) app_store()->setting('commerce', []);
if (array_key_exists('store_enabled', $commerceSettings) && empty($commerceSettings['store_enabled'])) {
    flash('cart_error', 'Checkout is temporarily unavailable.');
    redirect(base_url('cart/'));
}
$cart = cart_service();
$intentToken = (string) ($_GET['intent'] ?? '');
$isDirect = $intentToken !== '';
$contextKey = $isDirect ? 'intent:' . $intentToken : 'cart';
$checkoutUrl = base_url('checkout/' . ($isDirect ? '?intent=' . rawurlencode($intentToken) : ''));
$error = '';
$items = [];
$couponCode = '';
$quote = null;
$processing = false;
try {
    $intent = $isDirect ? $intentService->get($intentToken) : null;
    $items = $intent ? $intent['items'] : $cart->items();
    if ($items === []) redirect(base_url('cart/'));
    $couponCode = (string) ($intent['coupon'] ?? $_SESSION['cart_coupon'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'coupon') {
        if (!verify_csrf()) throw new RuntimeException('Your checkout session expired. Refresh and try again.');
        $code = strtoupper(trim((string) ($_POST['coupon'] ?? '')));
        commerce_service()->quote($items, $member, $code);
        if ($isDirect) $intentService->coupon($intentToken, $code); else $_SESSION['cart_coupon'] = $code;
        redirect($checkoutUrl);
    }
    $quote = commerce_service()->quote($items, $member, $couponCode);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
if (!is_array($_SESSION['checkout_idempotency'] ?? null)) $_SESSION['checkout_idempotency'] = [];
$fingerprint = hash('sha256', json_encode([(string) $member['id'], $items, $couponCode]));
$checkoutState = $_SESSION['checkout_idempotency'][$contextKey] ?? null;
if (!is_array($checkoutState) || ($checkoutState['fingerprint'] ?? '') !== $fingerprint) {
    $_SESSION['checkout_idempotency'][$contextKey] = ['fingerprint' => $fingerprint, 'key' => 'checkout:' . $member['id'] . ':' . bin2hex(random_bytes(16))];
}
$idempotencyKey = $_SESSION['checkout_idempotency'][$contextKey]['key'];
$existingOrder = app_store()->order($idempotencyKey);
if ($existingOrder && ($existingOrder['status'] ?? '') === 'processing') {
    $processing = true;
    $error = 'Payment is awaiting sandbox confirmation. Check Billing History for order ' . $existingOrder['orderNumber'] . '.';
}
$shipping = (array) ($_POST['shipping'] ?? []);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay' && $quote !== null && !$processing) {
    try {
        if (!verify_csrf()) throw new RuntimeException('Your checkout session expired. Refresh and try again.');
        if (($_POST['confirmation'] ?? '') !== '1') throw new RuntimeException('Confirm the test transaction before paying.');
        $result = commerce_service()->checkout((string) $member['id'], $items, $couponCode, (string) ($_POST['provider'] ?? 'sandbox'), [
            'sandboxResult' => (string) ($_POST['sandbox_result'] ?? 'success'),
            'cardNumber' => (string) ($_POST['card_number'] ?? ''), 'shipping' => $shipping,
        ], $idempotencyKey, (string) ($_POST['purchase_terms_revision'] ?? ''));
        $status = (string) ($result['order']['status'] ?? '');
        if ($status === 'paid') {
            if ($isDirect) $intentService->finish($intentToken); else { $cart->clear(); unset($_SESSION['cart_coupon']); }
            unset($_SESSION['checkout_idempotency'][$contextKey]);
            redirect(base_url('checkout/success.php?order=' . rawurlencode((string) $result['order']['orderNumber'])));
        }
        $processing = $status === 'processing';
        $error = (string) $result['message'];
        if (!$processing) unset($_SESSION['checkout_idempotency'][$contextKey]);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        // A validation failure did not create an order. A retry keeps its identity.
    }
}
$providers = app_store()->paymentProviders(true);
$flow = (string) ($quote['checkoutFlow'] ?? ($isDirect ? 'content' : 'merchandise'));
$pageTitle = $flow === 'membership' ? 'Membership Checkout' : ($flow === 'content' ? 'Direct Content Checkout' : 'Merchandise Checkout');
$activeNav = $flow === 'membership' ? 'membership' : 'shop';
require root_path('app/Views/public/partials/header.php');
?>
<div class="commerce-main">
  <section class="commerce-head"><span class="eyebrow pink"><?= e($pageTitle) ?></span><h1><?= $flow === 'membership' ? 'Start your membership.' : ($flow === 'content' ? 'Unlock this release.' : 'Complete your merchandise order.') ?></h1><p>This is a sandbox transaction. Raw card numbers are never stored.</p></section>
  <?php if ($error): ?><div class="member-alert <?= $processing ? 'success' : 'error' ?>" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($processing): ?><a class="button button--primary" href="<?= e(base_url('account/billing.php')) ?>">View Billing History</a><?php endif; ?>
  <?php if ($quote !== null && !$processing): ?>
  <div class="commerce-layout checkout-layout">
    <form method="post" action="<?= e($checkoutUrl) ?>" class="checkout-card">
      <?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="purchase_terms_revision" value="<?= e(App\Core\PurchasedTerms::quoteRevision($quote)) ?>">
      <?php if ($quote['requiresShipping']): ?>
      <fieldset class="shipping-address"><legend>Shipping address</legend>
        <?php foreach (['name' => ['Recipient name', 'name'], 'address1' => ['Address line 1', 'address-line1'], 'address2' => ['Address line 2 (optional)', 'address-line2'], 'city' => ['City', 'address-level2'], 'region' => ['State / province / region', 'address-level1'], 'postalCode' => ['Postal code', 'postal-code']] as $field => [$label, $autocomplete]): ?>
        <label><?= e($label) ?><input name="shipping[<?= e($field) ?>]" value="<?= e((string) ($shipping[$field] ?? '')) ?>" autocomplete="shipping <?= e($autocomplete) ?>" maxlength="190" <?= $field !== 'address2' ? 'required' : '' ?>></label>
        <?php endforeach; ?>
        <label>Country code<input name="shipping[country]" value="<?= e((string) ($shipping['country'] ?? 'US')) ?>" autocomplete="shipping country" minlength="2" maxlength="2" pattern="[A-Za-z]{2}" placeholder="US" required><small>Two-letter country code, for example US, CA or GB.</small></label>
      </fieldset>
      <?php endif; ?>
      <span class="eyebrow pink">Payment</span><h2>Sandbox checkout</h2>
      <div class="sandbox-banner"><strong>Testing mode is active.</strong><span>No real payment is collected. This bundled provider only simulates a transaction.</span></div>
      <label>Payment provider<select name="provider"><?php foreach ($providers as $provider): ?><option value="<?= e((string) $provider['key']) ?>"><?= e((string) $provider['name']) ?> · Test Mode</option><?php endforeach; ?></select></label>
      <label>Test card number<input name="card_number" inputmode="numeric" value="4242 4242 4242 4242" maxlength="23" autocomplete="off"></label>
      <label>Sandbox result<select name="sandbox_result"><option value="success">Approve payment</option><option value="decline">Decline payment</option><option value="processing">Leave payment processing</option></select></label>
      <label class="checkout-confirm"><input type="checkbox" name="confirmation" value="1" required><span>I authorize this test transaction and agree to the site terms.</span></label>
      <button class="button button--primary full">Pay <?= e(money((float) $quote['grandTotal'], (string) $quote['currency'])) ?></button>
    </form>
    <aside class="order-summary checkout-summary"><span class="eyebrow pink">Order</span><h2><?= e((string) count($quote['items'])) ?> item<?= count($quote['items']) === 1 ? '' : 's' ?></h2>
      <div class="checkout-items"><?php foreach ($quote['items'] as $item): ?><div><span><?= e((string) $item['title']) ?><small><?= e((string) $item['quantity']) ?> × <?= e(money((float) $item['unitPrice'], (string) $item['currency'])) ?> · <?= e(ucfirst((string) $item['mode'])) ?></small></span><strong><?= e(money((float) $item['lineTotal'], (string) $item['currency'])) ?></strong></div><?php endforeach; ?></div>
      <?php require root_path('app/Views/public/partials/order-totals.php'); ?>
      <?php require root_path('app/Views/public/partials/checkout-terms.php'); ?>
      <form method="post" action="<?= e($checkoutUrl) ?>" class="coupon-form"><?= csrf_field() ?><input type="hidden" name="action" value="coupon"><label>Coupon code<input name="coupon" value="<?= e($couponCode) ?>" maxlength="100"></label><button class="button button--ghost button--small">Apply</button></form>
      <?php if (!$isDirect): ?><a class="button button--ghost full" href="<?= e(base_url('cart/')) ?>">Return to Cart</a><?php else: ?><a class="button button--ghost full" href="<?= e(base_url($flow === 'membership' ? 'membership/' : 'photos/')) ?>">Continue browsing</a><?php endif; ?>
    </aside>
  </div>
  <?php endif; ?>
</div>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
