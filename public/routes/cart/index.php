<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require ROOT_PATH . '/app/bootstrap.php';
if (!is_installed()) redirect(base_url('install/'));

$cart = cart_service();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf()) throw new RuntimeException('Your session token expired. Refresh and try again.');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add') {
            $cart->add((string) ($_POST['type'] ?? ''), (string) ($_POST['id'] ?? ''), (string) ($_POST['mode'] ?? 'purchase'));
            flash('cart_success', 'Added to your cart.');
        } elseif ($action === 'remove') {
            $cart->remove((string) ($_POST['key'] ?? ''));
            flash('cart_success', 'Item removed.');
        } elseif ($action === 'clear') {
            $cart->clear();
            unset($_SESSION['cart_coupon']);
            flash('cart_success', 'Your cart is empty.');
        } elseif ($action === 'coupon') {
            $_SESSION['cart_coupon'] = strtoupper(trim((string) ($_POST['coupon'] ?? '')));
            flash('cart_success', $_SESSION['cart_coupon'] === '' ? 'Coupon removed.' : 'Coupon added for review.');
        }
        $return = member_return_path((string) ($_POST['return'] ?? '/cart/'), '/cart/');
        redirect(base_url(ltrim($return, '/')));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$items = $cart->items();
$couponCode = (string) ($_SESSION['cart_coupon'] ?? '');
$quote = null;
if ($items !== []) {
    try {
        $quote = commerce_service()->quote($items, current_member(), $couponCode);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($couponCode !== '') {
            unset($_SESSION['cart_coupon']);
            $couponCode = '';
            try { $quote = commerce_service()->quote($items, current_member(), ''); } catch (Throwable) { $quote = null; }
        }
    }
}
$pageTitle = 'Shopping Cart';
$activeNav = 'shop';
require root_path('app/Views/public/partials/header.php');
$success = flash('cart_success');
$cartError = flash('cart_error');
?>
<div class="commerce-main"><section class="commerce-head"><span class="eyebrow pink">Secure checkout</span><h1>Your Velvet cart.</h1><p>Subscriptions, PPV galleries, PPV videos, rentals, and bundles are managed from one private library.</p></section>
<?php if ($success): ?><div class="member-alert success"><?= e($success) ?></div><?php endif; ?>
<?php if ($cartError): ?><div class="member-alert error"><?= e($cartError) ?></div><?php endif; ?>
<?php if ($error): ?><div class="member-alert error"><?= e($error) ?></div><?php endif; ?>
<?php if ($items === [] || $quote === null): ?>
<section class="commerce-empty"><span>♡</span><h2>Your cart is waiting.</h2><p>Browse PPV releases, bundles, and membership plans.</p><a class="button button--primary" href="<?= e(base_url('shop/')) ?>">Browse the Store</a></section>
<?php else: ?>
<div class="commerce-layout"><section class="cart-list"><?php foreach ($quote['items'] as $item): ?><article class="cart-item"><div class="cart-item-icon"><?= match((string)$item['itemType']){'plan'=>'♛','gallery'=>'◇','video'=>'▶','bundle'=>'✦',default=>'◆'} ?></div><div class="cart-item-copy"><span><?= e(ucfirst((string)$item['scope'])) ?> · <?= e(ucfirst((string)$item['mode'])) ?></span><h2><?= e((string)$item['title']) ?></h2><?php if (!empty($item['metadata']['subscriberPricing'])): ?><small>Subscriber pricing applied</small><?php endif; ?></div><strong><?= e(money((float)$item['unitPrice'], (string)$item['currency'])) ?></strong><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="key" value="<?= e((string)$item['key']) ?>"><button class="cart-remove" aria-label="Remove <?= e((string)$item['title']) ?>">×</button></form></article><?php endforeach; ?></section>
<aside class="order-summary"><span class="eyebrow pink">Order summary</span><h2>Review total</h2><dl><div><dt>Subtotal</dt><dd><?= e(money((float)$quote['subtotal'], (string)$quote['currency'])) ?></dd></div><?php if ((float)$quote['discountTotal'] > 0): ?><div class="discount"><dt>Discount<?= $quote['couponCode'] ? ' · '.e((string)$quote['couponCode']) : '' ?></dt><dd>−<?= e(money((float)$quote['discountTotal'], (string)$quote['currency'])) ?></dd></div><?php endif; ?><div><dt>Configured tax</dt><dd><?= e(money((float)$quote['taxTotal'], (string)$quote['currency'])) ?></dd></div><div class="total"><dt>Total</dt><dd><?= e(money((float)$quote['grandTotal'], (string)$quote['currency'])) ?></dd></div></dl>
<form method="post" class="coupon-form"><?= csrf_field() ?><input type="hidden" name="action" value="coupon"><label>Coupon code<input name="coupon" value="<?= e($couponCode) ?>" placeholder="WELCOME10"></label><button class="button button--ghost button--small">Apply</button></form>
<?php if (current_member()): ?><a class="button button--primary full" href="<?= e(base_url('checkout/')) ?>">Continue to Checkout</a><?php else: ?><a class="button button--primary full" href="<?= e(base_url('account/login.php?return='.rawurlencode('/checkout/'))) ?>">Sign In to Checkout</a><small class="checkout-note">Purchases are tied to your member account so they remain in My Library.</small><?php endif; ?>
<form method="post" class="clear-cart"><?= csrf_field() ?><input type="hidden" name="action" value="clear"><button>Clear cart</button></form></aside></div>
<?php endif; ?></div>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
