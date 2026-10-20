<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
require ROOT_PATH . '/app/bootstrap.php';
if (!is_installed()) redirect(base_url('install/'));

$cart = cart_service();
if (!is_array($_SESSION['checkout_idempotency'] ?? null)) $_SESSION['checkout_idempotency'] = [];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf()) throw new RuntimeException('Your session token expired. Refresh and try again.');
        $action = (string) ($_POST['action'] ?? '');
        if (in_array($action, ['add', 'update'], true)) {
            $quantity = filter_var($_POST['quantity'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);
            if ($quantity === false) throw new RuntimeException('Choose a quantity from one to 99.');
            if ($action === 'add') {
                if (($_POST['type'] ?? '') !== 'merchandise') throw new RuntimeException('Only merchandise belongs in the cart. Use Buy Now on a content page or Choose Plan for membership.');
                $candidate = ['type' => 'merchandise', 'id' => (string) ($_POST['id'] ?? ''), 'variantId' => (string) ($_POST['variant_id'] ?? ''), 'mode' => 'purchase', 'quantity' => $quantity];
                foreach ($cart->items() as $item) if ($item['id'] === $candidate['id'] && $item['variantId'] === $candidate['variantId']) $candidate['quantity'] += (int) $item['quantity'];
                commerce_service()->quote([$candidate], current_member());
                $cart->add('merchandise', $candidate['id'], 'purchase', $candidate['variantId'], $quantity);
                flash('cart_success', 'Added to your cart.');
            } else {
                $key = (string) ($_POST['key'] ?? '');
                $candidate = null;
                foreach ($cart->items() as $item) if ($item['key'] === $key) $candidate = array_replace($item, ['quantity' => $quantity]);
                if (!$candidate) throw new RuntimeException('Cart item not found.');
                commerce_service()->quote([$candidate], current_member());
                $cart->update($key, $quantity);
                flash('cart_success', 'Quantity updated.');
            }
        } elseif ($action === 'remove') {
            $cart->remove((string) ($_POST['key'] ?? ''));
            flash('cart_success', 'Item removed.');
        } elseif ($action === 'clear') {
            $cart->clear();
            unset($_SESSION['cart_coupon']);
            flash('cart_success', 'Your cart is empty.');
        } elseif ($action === 'coupon') {
            $code = strtoupper(trim((string) ($_POST['coupon'] ?? '')));
            commerce_service()->quote($cart->items(), current_member(), $code);
            $_SESSION['cart_coupon'] = $code;
            flash('cart_success', $code === '' ? 'Coupon removed.' : 'Coupon applied.');
        } else {
            throw new RuntimeException('Invalid cart action.');
        }
        unset($_SESSION['checkout_idempotency']['cart']);
        redirect(base_url('cart/'));
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}
$items = $cart->items();
$couponCode = (string) ($_SESSION['cart_coupon'] ?? '');
$quote = null;
if ($items !== []) {
    try { $quote = commerce_service()->quote($items, current_member(), $couponCode); }
    catch (Throwable $exception) { $error = $exception->getMessage(); }
}
$pageTitle = 'Shopping Cart';
$activeNav = 'shop';
require root_path('app/Views/public/partials/header.php');
$success = flash('cart_success');
?>
<div class="commerce-main">
  <section class="commerce-head"><span class="eyebrow pink">Velvet merchandise</span><h1>Your Velvet cart.</h1><p>Review product options, quantities and shipping before checkout.</p></section>
  <?php foreach ([$success ? ['success', $success] : null, $error ? ['error', $error] : null, ($cartError = flash('cart_error')) ? ['error', $cartError] : null] as $notice): if ($notice): ?><div class="member-alert <?= e($notice[0]) ?>" role="status"><?= e($notice[1]) ?></div><?php endif; endforeach; ?>
  <?php if ($items === []): ?>
    <section class="commerce-empty"><span>♡</span><h2>Your cart is waiting.</h2><p>Discover merchandise and choose your favorite option.</p><a class="button button--primary" href="<?= e(base_url('shop/')) ?>">Browse the Store</a></section>
  <?php else: ?>
    <div class="commerce-layout"><section class="cart-list">
      <?php foreach ($items as $raw): $line = null; try { $line = (new App\Core\MerchandiseService(app_store()))->quoteItem($raw); } catch (Throwable) {} ?>
        <article class="cart-item" data-cart-line>
          <div class="cart-item-copy"><span><?= e((string) ($line['metadata']['sku'] ?? 'Unavailable option')) ?></span><h2><?= e((string) ($line['title'] ?? 'Product currently unavailable')) ?></h2>
            <?php if ($line): ?><form method="post" class="cart-quantity"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="key" value="<?= e($raw['key']) ?>"><label>Quantity<input type="number" name="quantity" min="1" max="99" value="<?= e((string) $raw['quantity']) ?>" required></label><button class="button button--ghost button--small">Update</button></form><?php endif; ?>
          </div>
          <strong><?= $line ? e(money((float) $line['unitPrice'] * (int) $line['quantity'], $line['currency'])) : '' ?></strong>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="key" value="<?= e($raw['key']) ?>"><button class="cart-remove" aria-label="Remove <?= e((string) ($line['title'] ?? 'unavailable product')) ?>">×</button></form>
        </article>
      <?php endforeach; ?>
    </section><aside class="order-summary"><span class="eyebrow pink">Order summary</span><h2>Review total</h2>
      <?php if ($quote): require root_path('app/Views/public/partials/order-totals.php'); ?>
      <form method="post" class="coupon-form"><?= csrf_field() ?><input type="hidden" name="action" value="coupon"><label>Coupon code<input name="coupon" value="<?= e($couponCode) ?>" maxlength="100"></label><button class="button button--ghost button--small">Apply</button></form>
      <a class="button button--primary full" href="<?= e(base_url('checkout/')) ?>"><?= current_member() ? 'Continue to Checkout' : 'Sign In to Checkout' ?></a>
      <?php else: ?><p>Remove unavailable products or update your selection before paying.</p><?php endif; ?>
      <form method="post" class="clear-cart"><?= csrf_field() ?><input type="hidden" name="action" value="clear"><button>Clear cart</button></form>
    </aside></div>
  <?php endif; ?>
</div>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
