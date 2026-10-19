<?php
declare(strict_types=1);

// Included only after the admin controller's authentication and CSRF checks.
if (!defined('ROOT_PATH') || !app_auth()->check() || !verify_csrf()) throw new RuntimeException('Owner authentication and a valid form session are required.');
if (in_array($action, ['save_merchandise_product', 'delete_merchandise_product', 'update_merchandise_fulfillment', 'cancel_merchandise_order'], true) && (app_auth()->user()['role'] ?? '') !== 'owner') throw new RuntimeException('Owner access is required to manage merchandise.');
if ($action === 'save_merchandise_product') {
    $record = (new App\Core\MerchandiseService($store))->saveFromForm($_POST, $_FILES);
    $store->audit(app_auth()->id(), 'merchandise.product.saved', ['productId' => (string) $record['id']]);
    flash('success', 'Merchandise product saved.');
    redirect(base_url('admin/?page=merchandise&edit=' . rawurlencode((string) $record['id'])));
}
if ($action === 'delete_merchandise_product') {
    $store->deleteMerchandiseProduct((string) ($_POST['product_id'] ?? ''));
    $store->audit(app_auth()->id(), 'merchandise.product.deleted', ['productId' => (string) ($_POST['product_id'] ?? '')]);
    flash('success', 'Product removed from the catalog. Existing order records are preserved.');
    redirect(base_url('admin/?page=merchandise'));
}
if ($action === 'update_merchandise_fulfillment') {
    $order = $store->updateMerchandiseFulfillment((string) ($_POST['order_id'] ?? ''), (string) ($_POST['shipping_status'] ?? ''), (string) ($_POST['tracking_reference'] ?? ''));
    $store->audit(app_auth()->id(), 'merchandise.fulfillment.updated', ['orderId' => (string) $order['id'], 'status' => (string) $order['metadata']['shippingStatus']]);
    flash('success', 'Merchandise fulfillment updated.');
    redirect(base_url('admin/?page=orders&order=' . rawurlencode((string) $order['id'])));
}
if ($action === 'cancel_merchandise_order') {
    $order = commerce_service()->cancelMerchandiseOrder((string) ($_POST['order_id'] ?? ''));
    $store->audit(app_auth()->id(), 'merchandise.order.cancelled', ['orderId' => (string) $order['id']]);
    flash('success', 'Pending sandbox order cancelled and reserved stock released.');
    redirect(base_url('admin/?page=orders&order=' . rawurlencode((string) $order['id'])));
}
