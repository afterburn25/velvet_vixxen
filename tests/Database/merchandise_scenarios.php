<?php
declare(strict_types=1);

// Invoked by migrations_mysql.php while its guarded disposable schema is live.
// No bootstrap: the application checkout's /install directory is never retired.
require_once $root . '/includes/installation_recovery.php';
require_once $root . '/app/Support/functions.php';
$temporaryConfig = tempnam(sys_get_temp_dir(), 'vvx-db-commerce-');
if ($temporaryConfig === false) throw new RuntimeException('Cannot create a disposable database-test configuration.');
try {
    $values = ['storage_mode' => 'mysql', 'installed' => false, 'currency' => 'USD', 'mail' => ['mode' => 'disabled'], 'db' => ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'password' => $password, 'charset' => 'utf8mb4']];
    $values['installation_id'] = 'owned-database-commerce-' . bin2hex(random_bytes(16));
    file_put_contents($temporaryConfig, "<?php\nreturn " . var_export($values, true) . ';');
    chmod($temporaryConfig, 0600);
    $configuration = new App\Core\Config($temporaryConfig);
    $databaseStore = new App\Core\Store($configuration);
    $databaseStore->savePaymentProvider(['key' => 'sandbox', 'name' => 'Disposable Sandbox', 'enabled' => true, 'testMode' => true]);
    $member = $databaseStore->createMember(['displayName' => 'Database Fixture', 'email' => 'database-merchandise@example.test', 'password' => 'DatabaseFixture12!', 'status' => 'active', 'emailVerifiedAt' => gmdate('Y-m-d H:i:s')]);
    $product = $databaseStore->saveMerchandiseProduct(['title' => 'Database merchandise', 'price' => '12.50', 'status' => 'active', 'requiresShipping' => true, 'shippingPrice' => '2.00', 'variants' => [['label' => 'Medium', 'sku' => 'DB-FIXTURE-M', 'options' => ['Size' => 'M'], 'stock' => 3]]]);
    $variantId = (string) $product['variants'][0]['id'];
    $ownerSnapshot = array_replace($product, ['revision' => App\Core\MerchandiseService::revision($product)]);
    $line = ['type' => 'merchandise', 'id' => (string) $product['id'], 'variantId' => $variantId, 'quantity' => 2];
    $payment = ['sandboxResult' => 'success', 'shipping' => ['name' => 'Database Fixture', 'address1' => '1 Fixture Road', 'city' => 'Test', 'region' => 'CA', 'postalCode' => '90210', 'country' => 'US']];
    $service = new App\Core\CommerceService($databaseStore, $configuration, new App\Core\MailService($configuration, $databaseStore), new App\Payments\PaymentProviderRegistry());
    $paid = $service->checkout($member['id'], [$line], '', 'sandbox', $payment, 'database:merchandise:paid');
    if ($paid['order']['status'] !== 'paid' || (float) $paid['order']['grandTotal'] !== 29.0 || $paid['order']['items'][0]['quantity'] !== 2) throw new RuntimeException('MySQL merchandise checkout totals or quantity failed.');
    if ($databaseStore->merchandiseProduct($product['id'])['variants'][0]['stock'] !== 1) throw new RuntimeException('MySQL stock was not decremented.');
    $staleRejected = false;
    try { $databaseStore->saveMerchandiseProduct($ownerSnapshot); } catch (RuntimeException $error) { $staleRejected = str_contains($error->getMessage(), 'stock changed'); }
    if (!$staleRejected || $databaseStore->merchandiseProduct($product['id'])['variants'][0]['stock'] !== 1) throw new RuntimeException('MySQL stale owner edit restored sold inventory.');
    $service->checkout($member['id'], [$line], '', 'sandbox', $payment, 'database:merchandise:paid');
    if ($databaseStore->merchandiseProduct($product['id'])['variants'][0]['stock'] !== 1) throw new RuntimeException('MySQL idempotent retry changed stock.');
    $variantSnapshot = $paid['order']['items'][0]['metadata'];
    if ($variantSnapshot['sku'] !== 'DB-FIXTURE-M' || $paid['order']['metadata']['shippingAddress']['country'] !== 'US') throw new RuntimeException('MySQL option/shipping snapshot failed.');
    $service->refundOrder($paid['order']['id'], 'Disposable database refund');
    if ($databaseStore->merchandiseProduct($product['id'])['variants'][0]['stock'] !== 3) throw new RuntimeException('MySQL refund did not release stock.');
    $product['title'] = 'Renamed database merchandise';
    $product['variants'][0]['stock'] = 5;
    $updated = $databaseStore->saveMerchandiseProduct($product);
    if ($updated['title'] !== $product['title'] || $updated['variants'][0]['stock'] !== 5) throw new RuntimeException('MySQL catalog edit failed.');
    $databaseStore->deleteMerchandiseProduct($product['id']);
    if ($databaseStore->merchandiseProducts(true) !== []) throw new RuntimeException('MySQL product deletion did not hide the catalog item.');
    if ($databaseStore->order($paid['order']['id'])['items'][0]['metadata']['sku'] !== 'DB-FIXTURE-M') throw new RuntimeException('MySQL product deletion changed order history.');
    echo "MySQL merchandise CRUD, quantity/shipping checkout, inventory, retry, refund and order snapshots passed.\n";
} finally {
    @unlink($temporaryConfig);
}
