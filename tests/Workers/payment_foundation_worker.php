<?php
declare(strict_types=1);

$source = dirname(__DIR__, 2); $root = $argv[1] ?? ''; $action = $argv[2] ?? '';
if (!str_starts_with($root, sys_get_temp_dir() . '/vvx-payment-foundation-') || is_link($root) || !is_file($root . '/.payment-foundation-fixture')) throw new RuntimeException('An owned payment fixture is required.');
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void { if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php'; });
require $source . '/includes/version.php'; require $source . '/includes/installation_recovery.php'; require $source . '/app/Support/functions.php'; require $source . '/tests/Support/PaymentFoundationFixture.php';
$config = new \App\Core\Config($root . '/config/app.php'); [$store, $commerce, $provider, $registry] = payment_fixture_service($config);
$input = json_decode((string) file_get_contents($root . '/worker-input.json'), true, 32, JSON_THROW_ON_ERROR);
if ($action === 'charge-kill') {
    $provider->chargeMode = 'kill'; $commerce->checkout($input['memberId'], $input['items'], '', 'fixture-pay', [], $input['idempotencyKey']);
} elseif ($action === 'refund-kill') {
    $provider->refundMode = 'kill'; $commerce->refundOrder($input['orderId'], 'Owned interruption fixture');
} elseif ($action === 'atomic-kill') {
    $order = $store->order($input['orderId']);
    $store->withCommerceLock(fn() => $store->paymentAtomic(function () use ($store, $order, $input, $root): void {
        $store->updateOrder($order['id'], ['status' => 'paid']);
        $store->savePaymentTransaction(['orderId' => $order['id'], 'providerKey' => 'fixture-pay', 'providerTransactionId' => 'interrupted_' . $order['id'], 'status' => 'paid', 'amount' => $order['grandTotal'], 'currency' => $order['currency']]);
        $store->grantEntitlement($order['memberId'], 'video', $input['videoId'], 'purchase', $order['id']);
        file_put_contents($root . '/atomic-effects-reached', 'Money, transaction and access mutations reached before commit.');
        posix_kill(getmypid(), SIGKILL); exit(98);
    }, $order['memberId']));
} elseif ($action === 'effects') {
    $commerce->retryPaymentEffects($input['orderId']);
    file_put_contents($root . '/effects-worker-complete', 'complete');
    exit(0);
} elseif ($action === 'effect-kill') {
    $provider->chargeMode = 'success';
    $commerce->checkout($input['memberId'], $input['items'], '', 'fixture-pay', [], $input['idempotencyKey']);
} elseif ($action === 'guarded-reconcile') {
    file_put_contents($root . '/guard-worker-ready', 'ready');
    try {
        $commerce->reconcilePaymentOperation($input['operationId'], static function () use ($root): void {
            if (trim((string) file_get_contents($root . '/owner-allowed')) !== 'yes') throw new RuntimeException('Owner authority changed.', 403);
        });
    } catch (\App\Core\PaymentRecoveryAuthorizationException $error) {
        file_put_contents($root . '/guard-worker-result', (string) $error->getCode()); exit(0);
    }
    throw new RuntimeException('Revoked owner recovery unexpectedly succeeded.');
} elseif ($action === 'guarded-refund') {
    session_save_path($root . '/sessions'); session_start();
    $_SERVER['HTTP_USER_AGENT'] = 'Owned payment authorization worker'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $auth = new \App\Core\Auth($store);
    if (!$auth->attempt('payment-owner@example.test', 'Payment-Owner-Fixture-123!')) throw new RuntimeException('Owned owner sign-in failed.');
    if ($input['denial'] === 428) $_SESSION['admin']['authenticated_at'] = time() - 899;
    $auth->requireRecentOwner();
    file_put_contents($root . '/refund-worker-ready', json_encode(['authenticatedAt'=>$_SESSION['admin']['authenticated_at'], 'role'=>$auth->user()['role']], JSON_THROW_ON_ERROR));
    try {
        $commerce->refundOrder($input['orderId'], 'Actual waiting owner fixture', static fn() => $auth->requireRecentOwner());
    } catch (\App\Core\PaymentRecoveryAuthorizationException $error) {
        file_put_contents($root . '/refund-worker-result', (string) $error->getCode()); session_write_close(); exit(0);
    }
    throw new RuntimeException('A stale owner refund unexpectedly succeeded.');
} elseif ($action === 'privacy-freeze') {
    file_put_contents($root . '/freeze-worker-ready', 'ready');
    try {
        $store->privacyFreeze($input['memberId'], $input['requestId'], $input['revision'], static function (): void {});
        file_put_contents($root . '/freeze-worker-result', '0');
    } catch (RuntimeException $error) {
        file_put_contents($root . '/freeze-worker-result', (string) $error->getCode());
    }
    exit(0);
} elseif (in_array($action, ['first-charge-claim', 'first-charge-dispatch', 'first-charge-transport'], true)) {
    $payments = new \App\Core\PaymentOperationService($store, $config, $registry);
    $operation = $store->paymentJournal($input['operationId']); $subject = $store->order($operation['orderId']);
    $pause = static function () use ($root): void {
        file_put_contents($root . '/charge-worker-ready', 'paused'); $deadline = microtime(true) + 10;
        while (!is_file($root . '/charge-worker-release') && microtime(true) < $deadline) usleep(10000);
        if (!is_file($root . '/charge-worker-release')) throw new RuntimeException('Owned first-charge pause exceeded its deadline.');
    };
    $authorize = null;
    if ($action === 'first-charge-claim') $provider->afterContext = $pause;
    elseif ($action === 'first-charge-transport') $provider->beforeCharge = static function (array $binding, array $freshOrder) use ($store, $root, $pause): void {
        $atomic = (new ReflectionProperty(\App\Core\Store::class, 'paymentAtomicActive'))->getValue($store);
        $commerce = (new ReflectionProperty(\App\Core\Store::class, 'commerceLockHeld'))->getValue($store);
        $transaction = $store->mode() === 'mysql' && (new ReflectionMethod(\App\Core\Store::class, 'pdo'))->invoke($store)->inTransaction();
        if ($atomic || $transaction || !$commerce) throw new RuntimeException('Provider dispatch did not own the nontransactional commerce fence.');
        file_put_contents($root . '/charge-fresh-subject', hash('sha256', json_encode($freshOrder, JSON_THROW_ON_ERROR)));
        $pause();
    };
    else $authorize = static function () use ($store, $operation, $pause): void { if ($store->paymentJournal($operation['id'])['state'] === 'attempting') $pause(); };
    file_put_contents($root . '/charge-worker-lock-ready', 'ready');
    $received = $store->withCommerceLock(fn(): array => $payments->execute($operation, $subject, [], '', $authorize));
    file_put_contents($root . '/charge-worker-result', json_encode(['state'=>$received['operation']['state'], 'issue'=>$received['operation']['data']['issue']], JSON_THROW_ON_ERROR));
    exit(0);
} else throw new RuntimeException('Unknown owned worker action.');
throw new RuntimeException('An interruption worker unexpectedly returned.');
