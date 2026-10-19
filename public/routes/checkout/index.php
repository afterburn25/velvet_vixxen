<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require ROOT_PATH . '/app/bootstrap.php';
if (!is_installed()) redirect(base_url('install/'));
$member = require_member();
$commerceSettings = (array) app_store()->setting('commerce', []);
if (empty($commerceSettings['store_enabled']) && array_key_exists('store_enabled', $commerceSettings)) {
    flash('cart_error', 'Checkout is temporarily unavailable.');
    redirect(base_url('cart/'));
}
$cart = cart_service();
$items = $cart->items();
if ($items === []) redirect(base_url('cart/'));
$couponCode = (string) ($_SESSION['cart_coupon'] ?? '');
$error = '';
try { $quote = commerce_service()->quote($items, $member, $couponCode); } catch (Throwable $e) { $error = $e->getMessage(); $quote = null; }
$providers = app_store()->paymentProviders(true);
if (empty($_SESSION['checkout_idempotency'])) $_SESSION['checkout_idempotency'] = 'checkout:' . (string)$member['id'] . ':' . bin2hex(random_bytes(16));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $quote !== null) {
    try {
        if (!verify_csrf()) throw new RuntimeException('Your checkout session expired. Refresh and try again.');
        if ((string)($_POST['action']??'') !== 'pay') throw new RuntimeException('Invalid checkout action.');
        $providerKey = (string) ($_POST['provider'] ?? 'sandbox');
        $result = commerce_service()->checkout((string)$member['id'], $items, $couponCode, $providerKey, [
            'sandboxResult'=>(string)($_POST['sandbox_result']??'success'),
            'cardNumber'=>(string)($_POST['card_number']??''),
        ], (string)$_SESSION['checkout_idempotency']);
        if ((string)($result['order']['status']??'') === 'paid') {
            $cart->clear(); unset($_SESSION['cart_coupon'], $_SESSION['checkout_idempotency']);
            redirect(base_url('checkout/success.php?order='.rawurlencode((string)$result['order']['orderNumber'])));
        }
        $error = (string) $result['message'];
        $_SESSION['checkout_idempotency'] = 'checkout:' . (string)$member['id'] . ':' . bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $_SESSION['checkout_idempotency'] = 'checkout:' . (string)$member['id'] . ':' . bin2hex(random_bytes(16));
    }
}
$pageTitle='Checkout';$activeNav='shop';require root_path('app/Views/public/partials/header.php');
?>
<div class="commerce-main"><section class="commerce-head"><span class="eyebrow pink">Private member checkout</span><h1>Complete your order.</h1><p>Your payment details are submitted to the selected processor. Raw card numbers are never stored by Velvet Vixxen.</p></section>
<?php if($error):?><div class="member-alert error"><?=e($error)?></div><?php endif;?>
<?php if($quote!==null):?><div class="commerce-layout checkout-layout"><form method="post" class="checkout-card"><?=csrf_field()?><input type="hidden" name="action" value="pay"><span class="eyebrow pink">Payment</span><h2>Sandbox checkout</h2><div class="sandbox-banner"><strong>Testing mode is active.</strong><span>This release includes a working sandbox adapter. A live adult-approved processor requires your provider account and API credentials.</span></div><label>Payment provider<select name="provider"><?php foreach($providers as $provider):?><option value="<?=e((string)$provider['key'])?>"><?=e((string)$provider['name'])?><?=!empty($provider['testMode'])?' · Test Mode':''?></option><?php endforeach;?></select></label><label>Test card number<input name="card_number" inputmode="numeric" autocomplete="cc-number" value="4242 4242 4242 4242" maxlength="23"></label><div class="checkout-row"><label>Expiration<input value="12/30" autocomplete="cc-exp" disabled></label><label>Security code<input value="123" autocomplete="cc-csc" disabled></label></div><label>Sandbox result<select name="sandbox_result"><option value="success">Approve payment</option><option value="decline">Decline payment</option><option value="processing">Leave payment processing</option></select></label><label class="checkout-confirm"><input type="checkbox" required><span>I authorize this test transaction and agree to the site terms.</span></label><button class="button button--primary full">Pay <?=e(money((float)$quote['grandTotal'],(string)$quote['currency']))?></button><small class="checkout-note">No raw card number is written to the database, logs, receipt, or member profile.</small></form>
<aside class="order-summary checkout-summary"><span class="eyebrow pink">Order</span><h2><?=e((string)count($quote['items']))?> item<?=count($quote['items'])===1?'':'s'?></h2><div class="checkout-items"><?php foreach($quote['items'] as $item):?><div><span><?=e((string)$item['title'])?><small><?=e(ucfirst((string)$item['mode']))?></small></span><strong><?=e(money((float)$item['lineTotal'],(string)$item['currency']))?></strong></div><?php endforeach;?></div><dl><div><dt>Subtotal</dt><dd><?=e(money((float)$quote['subtotal'],(string)$quote['currency']))?></dd></div><?php if((float)$quote['discountTotal']>0):?><div class="discount"><dt>Discount</dt><dd>−<?=e(money((float)$quote['discountTotal'],(string)$quote['currency']))?></dd></div><?php endif;?><div><dt>Tax</dt><dd><?=e(money((float)$quote['taxTotal'],(string)$quote['currency']))?></dd></div><div class="total"><dt>Total</dt><dd><?=e(money((float)$quote['grandTotal'],(string)$quote['currency']))?></dd></div></dl><a class="button button--ghost full" href="<?=e(base_url('cart/'))?>">Return to Cart</a></aside></div><?php endif;?></div>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
