<?php
declare(strict_types=1);

use App\Core\CommerceService;
use App\Core\Config;
use App\Core\MemberPrivacyProjection;
use App\Core\PaymentOperationService;
use App\Core\PaymentRecoveryService;
use App\Core\Store;
use App\Payments\PaymentContract;

$source = dirname(__DIR__, 2); $root = sys_get_temp_dir() . '/vvx-payment-foundation-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true); mkdir($root . '/storage', 0700, true);
file_put_contents($root . '/.payment-foundation-fixture', 'Owned disposable fake-provider acceptance.');
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void { if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php'; });
require $source . '/includes/version.php'; require $source . '/includes/installation_recovery.php'; require $source . '/app/Support/functions.php';
require $source . '/tests/Installer/database_fixture.php'; require $source . '/tests/Support/PaymentFoundationFixture.php';
$mysql = in_array('--mysql', $argv, true); $pdo = null; $owns = false; $checks = 0; $cases = []; $killed = 0; $children = [];
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool { if (!(error_reporting() & $severity)) return false; throw new ErrorException($message, 0, $severity, $file, $line); });
$assert = static function (bool $ok, string $message) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call, string $message) use ($assert): void { try { $call(); } catch (RuntimeException) { $assert(true, $message); return; } $assert(false, $message); };
$case = static function (string $name) use (&$cases, &$checks): void { $cases[$name] = ['complete' => true, 'checks_through' => $checks]; };
$startWorker = static function (string $action, array $input) use ($source, $root, &$children): array {
    file_put_contents($root . '/worker-input.json', json_encode($input, JSON_THROW_ON_ERROR)); chmod($root . '/worker-input.json', 0600);
    $pipes = []; $process = proc_open([PHP_BINARY, $source . '/tests/Workers/payment_foundation_worker.php', $root, $action], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Owned payment worker could not be started.');
    fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $children[] = $process; return [$process, $pipes];
};
$finishWorker = static function (array $worker, bool $expectKill) use ($assert, &$killed): void {
    [$process, $pipes] = $worker; $deadline = microtime(true) + 15;
    do { $status = proc_get_status($process); if (!$status['running']) break; usleep(20000); } while (microtime(true) < $deadline);
    if ($status['running']) { proc_terminate($process, 9); throw new RuntimeException('Owned payment worker exceeded its deadline.'); }
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    $assert($error === '' && $output === '', 'Worker emitted unexpected diagnostics: ' . $error . $output);
    if ($expectKill) { $assert($status['signaled'] && $status['termsig'] === SIGKILL, 'Worker did not reach its real SIGKILL checkpoint.'); ++$killed; }
    else $assert($status['exitcode'] === 0, 'Recovery worker failed.');
};
try {
    $assert(function_exists('posix_kill'), 'Actual worker interruption requires POSIX signals.');
    $config = new Config($root . '/config/app.php');
    $values = ['storage_mode' => $mysql ? 'mysql' : 'file', 'file_store_path' => 'storage/data.json', 'installed' => true,
        'installation_id' => 'payment-fixture-' . bin2hex(random_bytes(12)), 'base_url' => 'http://127.0.0.1/payment-fixture', 'currency' => 'USD',
        'mail' => ['mode' => 'disabled'], 'security' => ['app_key' => bin2hex(random_bytes(32)), 'signing_key' => bin2hex(random_bytes(32))]];
    if ($mysql) { [$db, $pdo] = vvx_installer_fixture_database(); vvx_installer_fixture_empty($pdo); $owns = true; $values['db'] = $db; }
    $config->write($values);
    file_put_contents($root . '/storage/install.lock', json_encode(['installed' => true, 'installation_id' => $values['installation_id']], JSON_THROW_ON_ERROR));
    if ($mysql) (new App\Core\MigrationRunner($pdo, $source . '/database/migrations'))->migrate(false, true);
    else file_put_contents($root . '/storage/data.json', '{}');
    [$store, $commerce, $provider, $registry] = payment_fixture_service($config);
    payment_fixture_addon($store, $root);
    $fixtureWebhookSecret = bin2hex(random_bytes(32));
    $store->savePaymentProvider(['key' => 'fixture-pay', 'enabled' => true, 'settings' => ['webhookSecret' => $fixtureWebhookSecret]]);
    $store->savePaymentProvider(['key' => 'sandbox', 'enabled' => true, 'supportsRecurring' => true]);
    $member = $store->createMember(['displayName' => 'Payment fixture member', 'email' => 'payment-fixture@example.test', 'password' => 'Payment-Fixture-123!', 'status' => 'active', 'emailVerifiedAt' => gmdate('c', time() - 10)]);
    $other = $store->createMember(['displayName' => 'Other payment member', 'email' => 'payment-other@example.test', 'password' => 'Other-Payment-Fixture-123!', 'status' => 'active', 'emailVerifiedAt' => gmdate('c', time() - 10)]);
    $product = $store->saveMerchandiseProduct(['title' => 'Owned fixture product', 'price' => '17.25', 'currency' => 'USD', 'status' => 'active', 'requiresShipping' => false,
        'variants' => [['label' => 'Fixture variant', 'sku' => 'PAYMENT-FIXTURE', 'stock' => 100]]]);
    $items = [['type' => 'merchandise', 'id' => (string) $product['id'], 'variantId' => (string) $product['variants'][0]['id'], 'quantity' => 1]];
    $video = $store->saveVideo(['title' => 'Owned digital fixture', 'status' => 'published', 'accessMode' => 'purchase', 'standardPrice' => 9.50, 'currency' => 'USD']);
    $digital = [['type' => 'video', 'id' => (string) $video['id'], 'mode' => 'purchase']];
    $stock = static fn(): int => (int) $store->merchandiseProduct((string) $product['id'])['variants'][0]['stock'];
    $attempts = static fn(string $kind): int => count(array_filter($provider->ledger()['attempts'], static fn(array $row): bool => $row['kind'] === $kind));
    $deliveries = static fn(): array => !is_file($root . '/addon-deliveries.jsonl') ? [] : array_map(static fn(string $line): array => json_decode($line, true, 16, JSON_THROW_ON_ERROR), file($root . '/addon-deliveries.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $send = static function (array $event) use (&$commerce, $fixtureWebhookSecret): array { $body = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); return $commerce->handleWebhook('fixture-pay', $body, hash_hmac('sha256', $body, $fixtureWebhookSecret)); };
    $event = static fn(array $operation, string $eventId, string $type, ?string $transaction = null): array => ['schema' => 1, 'eventId' => $eventId, 'eventType' => $type,
        'binding' => $operation['data']['binding'], 'transactionId' => $transaction ?? $operation['data']['result']['transactionId'], 'occurredAt' => time()];
    $waitCommerce = static function (array $worker, string $marker) use ($mysql, $pdo, $values, $root, $assert): void {
        $deadline=microtime(true)+8; $blocked=false;
        do {
            if ($mysql) {
                foreach ($pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $process) if (($process['db']??'')===$values['db']['name'] && str_starts_with((string)($process['Info']??''),'SELECT GET_LOCK(')) { $blocked=true; break; }
            } else {
                $path='/proc/' . proc_get_status($worker[0])['pid'] . '/wchan';
                $blocked=is_file($path) && str_contains(trim((string)file_get_contents($path)),'locks_lock');
            }
            if ($blocked && is_file($root . '/' . $marker)) break; usleep(10000);
        } while (microtime(true)<$deadline);
        $assert($blocked && is_file($root . '/' . $marker),'Worker did not reach its actual waiting commerce fence: ' . $marker);
    };

    $provider->chargeMode = 'timeout'; $before = $stock();
    $a = $commerce->checkout($member['id'], $items, '', 'fixture-pay', ['token' => 'NEVER-PERSIST-THIS-TOKEN'], 'fixture:timeout');
    $assert($a['order']['status'] === 'processing' && $a['paymentOperation']['state'] === 'unknown', 'Accepted-then-timeout was represented as a confirmed failure/success.');
    $assert($stock() === $before - 1 && $a['transaction'] === [], 'Unknown charge released stock or invented a financial transaction.');
    $calls = $attempts('charge'); $same = $commerce->checkout($member['id'], $items, '', 'fixture-pay', [], 'fixture:timeout');
    $assert($same['order']['id'] === $a['order']['id'] && $attempts('charge') === $calls, 'Retry repeated an uncertain charge.');
    $reject(fn() => $commerce->checkout($other['id'], $items, '', 'fixture-pay', [], 'fixture:timeout'), 'Another member reused an existing checkout.');
    [, $restarted] = payment_fixture_service($config); $recovered = $restarted->reconcilePaymentOperation($a['paymentOperation']['id']);
    $assert($recovered['operation']['state'] === 'applied' && $recovered['order']['status'] === 'paid' && $stock() === $before - 1, 'Read-only provider lookup did not recover the accepted charge exactly once.');
    $assert($attempts('charge') === $calls && count($store->paymentTransactionsForOrder($a['order']['id'])) === 1, 'Reconciliation repeated a charge or created duplicate transactions.');
    $lookupCount = count($provider->ledger()['lookups']); $restarted->reconcilePaymentOperation($a['paymentOperation']['id']);
    $assert(count($provider->ledger()['lookups']) === $lookupCount, 'A completed operation unnecessarily queried/retried the provider.');
    $assert(!str_contains(json_encode($store->paymentJournalForOrder($a['order']['id'])), 'NEVER-PERSIST'), 'Client payment input escaped into the journal.');
    $case('accepted_then_timeout_charge_recovery');

    $provider->chargeMode = 'pending';
    $b = $commerce->checkout($member['id'], $items, '', 'fixture-pay', [], 'fixture:pending');
    $paidB = $event($b['paymentOperation'], 'fixture-paid-b', 'order.paid', 'fixture-confirmed-b');
    $financialBefore = [$store->order($b['order']['id']), $store->paymentTransactionsForOrder($b['order']['id']), $stock()];
    foreach (['installation' => str_repeat('0',64), 'provider' => 'sandbox', 'account' => str_repeat('1',64), 'environment' => 'live', 'orderId' => $a['order']['id'],
        'orderNumber' => $a['order']['orderNumber'], 'amountMinor' => 1, 'currency' => 'EUR', 'operationId' => 'pay_' . str_repeat('2',32)] as $field => $wrong) {
        $bad = $paidB; $bad['eventId'] = 'bad-' . $field; $bad['binding'][$field] = $wrong;
        $reject(fn() => $send($bad), 'Signed event with wrong ' . $field . ' was accepted.');
        $assert([$store->order($b['order']['id']), $store->paymentTransactionsForOrder($b['order']['id']), $stock()] === $financialBefore, 'Wrong event binding changed financial facts.');
    }
    $cross = $paidB; $cross['eventId'] = 'cross-order-reference'; $cross['transactionId'] = $recovered['operation']['data']['result']['transactionId'];
    $reject(fn() => $send($cross), 'A different order claimed another order\'s transaction.');
    $assert($store->order($b['order']['id'])['status'] === 'processing', 'Cross-order reference granted payment/access.');
    $accepted = $send($paidB); $duplicate = $send($paidB);
    $assert(!$accepted['duplicate'] && $duplicate['duplicate'] && $store->order($b['order']['id'])['status'] === 'paid', 'Bound paid event or exact duplicate semantics failed.');
    $conflict = $paidB; $conflict['transactionId'] = 'different-transaction'; $reject(fn() => $send($conflict), 'Conflicting content reused an accepted event ID.');
    $again = $paidB; $again['eventId'] = 'same-operation-new-event'; $send($again);
    $assert(count($store->paymentTransactionsForOrder($b['order']['id'])) === 1, 'A second event ID repeated the same operation effect.');
    foreach ([['schema' => '1'], ['occurredAt' => (string) time()], ['occurredAt' => time() + 3600], ['transactionId' => ''], ['unexpected' => true]] as $fields) {
        $bad = array_replace($paidB, $fields); $bad['eventId'] = 'bad-shape-' . bin2hex(random_bytes(3)); $reject(fn() => $send($bad), 'Malformed normalized event was accepted.');
    }
    $case('strict_binding_duplicate_conflict_and_cross_order_events');

    $provider->refundMode = 'timeout'; $refundCalls = $attempts('refund'); $stockBeforeRefund = $stock();
    $reject(fn() => $commerce->refundOrder($a['order']['id'], 'fixture refund'), 'Unknown refund reported completion.');
    $refundOperations = array_values(array_filter($store->paymentJournalForOrder($a['order']['id']), static fn(array $row): bool => $row['kind'] === 'operation' && $row['data']['binding']['kind'] === 'refund'));
    $refund = $refundOperations[0];
    $assert(count($refundOperations) === 1 && $refund['state'] === 'unknown' && PaymentContract::minor($store->order($a['order']['id'])['refundedTotal']) === 0 && $stock() === $stockBeforeRefund, 'Uncertain refund changed totals/stock or lost durable intent.');
    $reject(fn() => $commerce->refundOrder($a['order']['id'], 'repeat'), 'An unknown refund was retried.');
    $assert($attempts('refund') === $refundCalls + 1, 'Provider refund was repeated after acceptance.');
    $refunded = $restarted->reconcilePaymentOperation($refund['id']);
    $assert($refunded['order']['status'] === 'refunded' && $refunded['order']['refundedTotal'] === 17.25 && $stock() === $stockBeforeRefund + 1, 'Refund lookup did not atomically recover totals and stock.');
    $refundEvent = $event($refunded['operation'], 'confirmed-refund-callback', 'order.refunded'); $send($refundEvent); $send($refundEvent);
    $assert(count($store->paymentTransactionsForOrder($a['order']['id'])) === 2 && $stock() === $stockBeforeRefund + 1 && $attempts('refund') === $refundCalls + 1, 'Refund callback/replay duplicated money or stock.');
    $late = $event($store->paymentJournal($a['paymentOperation']['id']), 'paid-after-refund', 'order.paid');
    $assert($send($late)['event']['status'] === 'ignored' && $store->order($a['order']['id'])['status'] === 'refunded', 'Late paid event revived a refunded order.');
    $case('accepted_then_timeout_refund_and_callback_deduplication');

    $payments = new PaymentOperationService($store, $config, $registry); $bNow = $store->order($b['order']['id']);
    $originalB = $store->paymentTransactionsForOrder($b['order']['id'])[0]['providerTransactionId'];
    $partialBinding = $payments->binding($bNow, 'refund', 500, $originalB);
    $partialEvent = ['schema'=>1,'eventId'=>'partial-one','eventType'=>'order.refunded','binding'=>$partialBinding,'transactionId'=>'fixture-partial-one','occurredAt'=>time()];
    $send($partialEvent); $partialEvent['eventId'] = 'partial-one-new-delivery'; $send($partialEvent);
    $assert(PaymentContract::minor($store->order($b['order']['id'])['refundedTotal']) === 500 && $store->order($b['order']['id'])['status'] === 'partially_refunded', 'Different event IDs applied one partial refund twice.');
    $tooMuch = $partialEvent; $tooMuch['eventId'] = 'too-much-refund'; $tooMuch['binding'] = $payments->binding($bNow, 'refund', 1800, $originalB); $tooMuch['transactionId'] = 'fixture-too-much';
    $reject(fn() => $send($tooMuch), 'Over-refund was silently clamped into another financial amount.');
    $assert(PaymentContract::minor($store->order($b['order']['id'])['refundedTotal']) === 500, 'Over-refund mutated the ledger.');
    $foreign = $partialEvent; $foreign['eventId'] = 'foreign-original'; $foreign['binding'] = $payments->binding($bNow, 'refund', 100, $recovered['operation']['data']['result']['transactionId']); $foreign['transactionId'] = 'foreign-refund';
    $reject(fn() => $send($foreign), 'A refund bound another order\'s original charge.');
    $case('partial_refund_exact_amount_and_original_charge_binding');

    $provider->chargeMode = 'success'; $provider->alterResult = static function (array $result): array { $result['binding']['amountMinor']++; return $result; };
    $invalid = $commerce->checkout($member['id'], $digital, '', 'fixture-pay', [], 'fixture:wrong-result'); $provider->alterResult = null;
    $assert($invalid['paymentOperation']['state'] === 'held' && $invalid['order']['status'] === 'processing' && $store->activeEntitlement($member['id'], 'video', $video['id']) === null, 'Unbound synchronous result granted payment/access.');
    $fixed = $restarted->reconcilePaymentOperation($invalid['paymentOperation']['id']);
    $assert($fixed['operation']['state'] === 'applied' && $store->activeEntitlement($member['id'], 'video', $video['id']) !== null, 'Valid queried evidence did not recover a held result.');
    $baseResult = $provider->ledger()['results'][$invalid['paymentOperation']['id']];
    foreach (['schema','outcome','transactionId','observedAt','binding'] as $field) {
        $bad = $baseResult; unset($bad[$field]); $reject(fn() => PaymentContract::result($bad, $baseResult['binding'], $invalid['paymentOperation']['createdAt']), 'Missing result field was accepted: ' . $field);
    }
    $bad = $baseResult; $bad['outcome'] = true; $reject(fn() => PaymentContract::result($bad, $baseResult['binding'], $invalid['paymentOperation']['createdAt']), 'Boolean success truthiness bypassed normalized outcomes.');
    $bad = $baseResult; $bad['display'] = ['cardNumber' => '4242424242424242']; $reject(fn() => PaymentContract::result($bad, $baseResult['binding'], $invalid['paymentOperation']['createdAt']), 'Raw card payload entered display metadata.');
    $case('strict_synchronous_results_and_query_recovery');

    // Identical financial facts do not establish ownership of a pre-existing
    // transaction. Neither one unbound row nor multiple matching rows may be
    // silently attached to a new operation or duplicated by local application.
    foreach ([1, 2] as $copies) {
        $provider->chargeMode = 'timeout';
        $ambiguous = $commerce->checkout($member['id'], $items, '', 'fixture-pay', [], 'fixture:ambiguous-local-' . $copies);
        $acceptedResult = $provider->ledger()['results'][$ambiguous['paymentOperation']['id']];
        for ($copy = 0; $copy < $copies; ++$copy) $store->savePaymentTransaction([
            'orderId' => $ambiguous['order']['id'], 'providerKey' => 'fixture-pay', 'providerTransactionId' => $acceptedResult['transactionId'],
            'transactionType' => 'charge', 'status' => 'paid', 'amount' => $ambiguous['order']['grandTotal'], 'currency' => $ambiguous['order']['currency'],
        ]);
        $facts = [$store->order($ambiguous['order']['id']), $store->paymentTransactionsForOrder($ambiguous['order']['id']), $stock(), count($deliveries())];
        $calls = $attempts('charge'); $lookupCount = count($provider->ledger()['lookups']);
        $held = $commerce->reconcilePaymentOperation($ambiguous['paymentOperation']['id']);
        $assert($held['operation']['state'] === 'held' && $held['operation']['data']['issue'] === 'malformed_result'
            && $held['operation']['data']['transactionId'] === null, 'Unbound or duplicate same-order transaction rows were silently adopted.');
        $assert([$store->order($ambiguous['order']['id']), $store->paymentTransactionsForOrder($ambiguous['order']['id']), $stock(), count($deliveries())] === $facts
            && $attempts('charge') === $calls && count($provider->ledger()['lookups']) === $lookupCount + 1, 'Ambiguous reference recovery changed financial/access facts or repeated payment.');
        $ambiguousEvent = $event($ambiguous['paymentOperation'], 'ambiguous-local-event-' . $copies, 'order.paid', $acceptedResult['transactionId']);
        $reject(fn() => $send($ambiguousEvent), 'An authenticated callback bypassed ambiguous local transaction binding.');
        $assert([$store->order($ambiguous['order']['id']), $store->paymentTransactionsForOrder($ambiguous['order']['id']), $stock(), count($deliveries())] === $facts, 'Held ambiguous callback created another transaction or fulfilled the order.');
    }
    $case('unbound_and_duplicate_same_order_references_remain_held');

    $payments = new PaymentOperationService($store, $config, $registry);
    $observed = count(PaymentFoundationFakeProvider::$contextObservations);
    $reject(fn() => $store->withCommerceLock(fn() => $store->paymentAtomic(fn() => $payments->scope('fixture-pay'))), 'A provider account callback was allowed inside a financial commit.');
    $assert(count(PaymentFoundationFakeProvider::$contextObservations) === $observed, 'The commit boundary rejected an adapter only after invoking it.');
    $configuration = $store->paymentProvider('fixture-pay'); $calls = $attempts('charge'); $before = $stock();
    $provider->afterContext = static function () use ($store, $configuration, $provider): void {
        $provider->afterContext = null; $changed = $configuration; $changed['settings']['account'] = hash('sha256', 'rotation-during-context'); $store->savePaymentProvider($changed);
    };
    $reject(fn() => $commerce->checkout($member['id'], $items, '', 'fixture-pay', [], 'fixture:context-rotation'), 'Configuration rotation during account resolution was accepted.');
    $assert($store->order('fixture:context-rotation') === null && $attempts('charge') === $calls && $stock() === $before, 'Scope rotation reached an order, stock mutation or provider charge.');
    $store->savePaymentProvider($configuration);
    $case('provider_context_boundary_and_configuration_rotation');

    // A catalog dependency disappears only after provider acceptance. The real
    // fulfillment path must roll back its earlier money/access writes, retaining
    // confirmed evidence for local recovery without another external attempt.
    [$store, $commerce, $provider, $registry] = payment_fixture_service($config);
    $bundle = $store->saveBundle(['title'=>'Recoverable fixture bundle','price'=>9.50,'currency'=>'USD','isActive'=>true,'items'=>[['itemType'=>'video','itemId'=>$video['id']]]]);
    $bundleSql = $bundleItemsSql = null;
    if ($mysql) {
        $stmt = $pdo->prepare('SELECT * FROM commerce_bundles WHERE id=?'); $stmt->execute([$bundle['id']]); $bundleSql = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT * FROM commerce_bundle_items WHERE bundle_id=?'); $stmt->execute([$bundle['id']]); $bundleItemsSql = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $provider->afterAccepted = static function () use ($store, $mysql, $pdo, $bundle): void {
        if ($mysql) $pdo->prepare('DELETE FROM commerce_bundles WHERE id=?')->execute([$bundle['id']]);
        else $store->mutateFileStore(static function (array &$data) use ($bundle): void { unset($data['bundles'][$bundle['id']]); });
    };
    $calls = $attempts('charge'); $deliveriesBefore = count($deliveries());
    $localFailure = $commerce->checkout($member['id'], [['type'=>'bundle','id'=>$bundle['id']]], '', 'fixture-pay', [], 'fixture:local-apply-failure'); $provider->afterAccepted = null;
    $assert($localFailure['paymentOperation']['state'] === 'confirmed' && $localFailure['paymentOperation']['data']['issue'] === 'local_effect_failed' && $localFailure['order']['status'] === 'processing'
        && $store->paymentTransactionsForOrder($localFailure['order']['id']) === [] && $localFailure['order']['items'][0]['fulfillmentStatus'] !== 'fulfilled', 'A real fulfillment failure partially committed financial/access effects or lost confirmed evidence.');
    $claimedReference = $localFailure['paymentOperation']['data']['result']['transactionId'];
    $provider->alterResult = static function (array $result) use ($claimedReference): array { $result['transactionId'] = $claimedReference; return $result; };
    $crossConfirmed = $commerce->checkout($other['id'], $digital, '', 'fixture-pay', [], 'fixture:confirmed-reference-reuse'); $provider->alterResult = null;
    $assert($crossConfirmed['paymentOperation']['state'] === 'held' && $store->paymentTransactionsForOrder($crossConfirmed['order']['id']) === [], 'A different order reused a confirmed reference before its first local transaction existed.');
    if ($mysql) {
        foreach (['commerce_bundles'=>[$bundleSql], 'commerce_bundle_items'=>$bundleItemsSql] as $table=>$rows) foreach ($rows as $row) {
            $sql = 'INSERT INTO ' . vvx_installer_fixture_quote($table) . ' (' . implode(',', array_map(vvx_installer_fixture_quote(...), array_keys($row))) . ') VALUES (' . implode(',', array_fill(0,count($row),'?')) . ')';
            $pdo->prepare($sql)->execute(array_values($row));
        }
    } else $store->mutateFileStore(static function (array &$data) use ($bundle): void { $data['bundles'][$bundle['id']] = $bundle; });
    $lookups = count($provider->ledger()['lookups']); $locallyRecovered = $commerce->reconcilePaymentOperation($localFailure['paymentOperation']['id']);
    $assert($locallyRecovered['operation']['state'] === 'applied' && count($store->paymentTransactionsForOrder($localFailure['order']['id'])) === 1 && $locallyRecovered['order']['items'][0]['fulfillmentStatus'] === 'fulfilled'
        && $attempts('charge') === $calls + 2 && count($provider->ledger()['lookups']) === $lookups, 'Local application recovery repeated provider transport or failed to commit exact effects.');
    $assert(count($deliveries()) === $deliveriesBefore, 'Operator reconciliation dispatched an external obligation instead of leaving it for the separate control.');
    $commerce->retryPaymentEffects($localFailure['order']['id']);
    $assert(count($deliveries()) === $deliveriesBefore + 1, 'Recovered core commit did not retain its separate addon obligation.');
    $case('real_fulfillment_failure_atomic_recovery_and_uncommitted_reference_claim');

    $provider->chargeMode = 'pending'; $scopeOrder = $commerce->checkout($member['id'], $digital, '', 'fixture-pay', [], 'fixture:scope-rotation');
    $configuration = $store->paymentProvider('fixture-pay'); $lookups = count($provider->ledger()['lookups']); $calls = $attempts('refund');
    foreach (['account'=>hash('sha256','another-merchant-account'),'environment'=>'live'] as $field=>$value) {
        $changed = $configuration; $changed['settings'][$field] = $value; $store->savePaymentProvider($changed);
        $held = $commerce->reconcilePaymentOperation($scopeOrder['paymentOperation']['id']);
        $assert($held['operation']['state'] === 'held' && $held['operation']['data']['issue'] === 'scope_changed' && count($provider->ledger()['lookups']) === $lookups, 'Account/environment rotation queried a mismatched operation.');
        $reject(fn() => $commerce->refundOrder($localFailure['order']['id']), 'A different provider account/environment refunded the original charge.');
        $assert($attempts('refund') === $calls, 'Scope mismatch reached refund transport.');
        $store->savePaymentProvider($configuration);
    }
    $case('merchant_and_environment_rotation_fail_closed');

    $provider->chargeMode = 'pending'; $atomic = $commerce->checkout($other['id'], $digital, '', 'fixture-pay', [], 'fixture:atomic');
    $atomicBefore = [$store->order($atomic['order']['id']), $store->paymentTransactionsForOrder($atomic['order']['id']), $store->entitlements($other['id'], false), $store->paymentJournalForOrder($atomic['order']['id'])];
    $finishWorker($startWorker('atomic-kill', ['orderId'=>$atomic['order']['id'],'videoId'=>$video['id']]), true);
    $assert(is_file($root . '/atomic-effects-reached'), 'Interruption did not reach the core money/access writes.');
    $assert([$store->order($atomic['order']['id']), $store->paymentTransactionsForOrder($atomic['order']['id']), $store->entitlements($other['id'], false), $store->paymentJournalForOrder($atomic['order']['id'])] === $atomicBefore, 'Killed core commit left partial money, access or journal effects.');
    $atomicEvent = $event($atomic['paymentOperation'], 'atomic-recovery-paid', 'order.paid', 'fixture-atomic-recovered'); $send($atomicEvent); $send($atomicEvent);
    $assert(count($store->paymentTransactionsForOrder($atomic['order']['id'])) === 1 && count($store->entitlements($other['id'], false)) === 1, 'Recovery after interrupted core effects did not apply exactly once.');
    $case('real_killed_atomic_financial_and_access_commit');

    $calls = $attempts('charge'); $stockBeforeKill = $stock();
    $finishWorker($startWorker('charge-kill', ['memberId'=>$member['id'],'items'=>$items,'idempotencyKey'=>'fixture:kill-charge']), true);
    $killedOrder = $store->order('fixture:kill-charge'); $killOps = array_values(array_filter($store->paymentJournalForOrder($killedOrder['id']), static fn(array $r): bool => $r['kind']==='operation'));
    $assert($killedOrder['status'] === 'processing' && $killOps[0]['state'] === 'attempting' && $stock() === $stockBeforeKill - 1 && $attempts('charge') === $calls + 1, 'Durable intent/stock did not survive a killed accepted provider call.');
    $commerce->checkout($member['id'], $items, '', 'fixture-pay', [], 'fixture:kill-charge');
    $assert($attempts('charge') === $calls + 1 && $store->paymentJournal($killOps[0]['id'])['state'] === 'unknown', 'A killed provider call was retried instead of held.');
    $restarted->reconcilePaymentOperation($killOps[0]['id']);
    $assert($store->order($killedOrder['id'])['status'] === 'paid' && count($store->paymentTransactionsForOrder($killedOrder['id'])) === 1, 'Killed provider response was not reconciled from its durable ledger.');
    $calls = $attempts('refund');
    $finishWorker($startWorker('refund-kill', ['orderId'=>$killedOrder['id']]), true);
    $killRefunds = array_values(array_filter($store->paymentJournalForOrder($killedOrder['id']), static fn(array $r): bool => $r['kind']==='operation' && $r['data']['binding']['kind']==='refund'));
    $reject(fn() => $commerce->refundOrder($killedOrder['id']), 'Killed accepted refund was repeated.');
    $restarted->reconcilePaymentOperation($killRefunds[0]['id']);
    $assert($attempts('refund') === $calls + 1 && $store->order($killedOrder['id'])['refundedTotal'] === 17.25 && $stock() === $stockBeforeKill, 'Killed refund recovery duplicated or lost money/stock.');
    $case('real_killed_charge_and_refund_transport_recovery');

    [$store, $commerce, $provider, $registry] = payment_fixture_service($config);
    $provider->chargeMode = 'success'; $provider->refundMode = 'success';

    // Real addon execution acknowledges only after the listener returns. A
    // listener may already have accepted an effect before throwing or dying.
    file_put_contents($root . '/addon-mode', 'throw'); $deliveredBefore = count($deliveries());
    $listenerFailure = $commerce->checkout($member['id'], $digital, '', 'fixture-pay', [], 'fixture:listener-failure');
    file_put_contents($root . '/addon-mode', 'success');
    $failedEffects = array_values(array_filter($store->paymentJournalForOrder($listenerFailure['order']['id']), static fn(array $r): bool => $r['kind']==='effect' && $r['data']['effect']==='addon'));
    $assert($listenerFailure['order']['status'] === 'paid' && count($failedEffects) === 1 && $failedEffects[0]['state'] === 'held' && count($deliveries()) === $deliveredBefore + 1, 'A swallowed addon listener failure lost its explicit held obligation.');
    $commerce->retryPaymentEffects($listenerFailure['order']['id']);
    $assert(count($deliveries()) === $deliveredBefore + 1, 'An ambiguous addon listener effect was resent.');
    file_put_contents($root . '/addon-mode', 'kill'); $deliveredBefore = count($deliveries()); $calls = $attempts('charge');
    $finishWorker($startWorker('effect-kill', ['memberId'=>$member['id'],'items'=>$digital,'idempotencyKey'=>'fixture:killed-addon']), true);
    file_put_contents($root . '/addon-mode', 'success');
    [$store, $commerce, $provider, $registry] = payment_fixture_service($config);
    $killedAddonOrder = $store->order('fixture:killed-addon');
    $killedEffects = array_values(array_filter($store->paymentJournalForOrder($killedAddonOrder['id']), static fn(array $r): bool => $r['kind']==='effect' && $r['data']['effect']==='addon'));
    $assert($killedAddonOrder['status'] === 'paid' && $killedEffects[0]['state'] === 'attempting' && count($deliveries()) === $deliveredBefore + 1, 'Post-commit interruption lost the paid order or the durable external claim.');
    $commerce->retryPaymentEffects($killedAddonOrder['id']);
    $assert($store->paymentJournal($killedEffects[0]['id'])['state'] === 'held' && count($deliveries()) === $deliveredBefore + 1 && $attempts('charge') === $calls + 1, 'Interrupted post-commit work was silently resent or repeated its charge.');
    $case('real_listener_failure_and_killed_external_effect_hold');

    $store->setAddonsSafeMode(true); $deliveredBefore = count($deliveries());
    $retryableOrder = $commerce->checkout($member['id'], $digital, '', 'fixture-pay', [], 'fixture:safe-effect-retry');
    $retryable = array_values(array_filter($store->paymentJournalForOrder($retryableOrder['order']['id']), static fn(array $r): bool => $r['kind']==='effect' && $r['data']['effect']==='addon'))[0];
    $assert($retryable['state'] === 'retryable' && count($deliveries()) === $deliveredBefore, 'Disabled addon delivery was not recorded as proven safe to retry.');
    $store->setAddonsSafeMode(false); $commerce->retryPaymentEffects($retryableOrder['order']['id']); $commerce->retryPaymentEffects($retryableOrder['order']['id']);
    $assert($store->paymentJournal($retryable['id'])['state'] === 'done' && count($deliveries()) === $deliveredBefore + 1, 'Known-safe obligation retry was lost or duplicated.');
    $lastDelivery = $deliveries()[array_key_last($deliveries())];
    $assert($lastDelivery['operationId'] === $retryable['data']['operationId'] && $lastDelivery['effectId'] === $retryable['id'], 'External listener did not receive stable operation/effect IDs.');
    $case('proven_safe_deferred_obligation_retry');

    if ($mysql) {
        $pausedMember = $store->createMember(['displayName'=>'PRIVATE-PAUSED-NAME','email'=>'private-paused@example.test','password'=>'Paused-Payment-Fixture-123!','status'=>'active','emailVerifiedAt'=>now_iso()]);
        $store->setAddonsSafeMode(true);
        $pausedOrder = $commerce->checkout($pausedMember['id'], $digital, '', 'fixture-pay', [], 'fixture:pause-before-claim');
        $store->setAddonsSafeMode(false);
        $pausedEffect = array_values(array_filter($store->paymentJournalForOrder($pausedOrder['order']['id']), static fn(array $r): bool => $r['kind']==='effect' && $r['data']['effect']==='addon'))[0];
        $deliveredBefore = count($deliveries());
        $request = str_repeat('e',32); $revision = Store::privacyDataRevision($store->privacyCoreData($pausedMember['id']));
        $store->privacyFreeze($pausedMember['id'], $request, $revision, static function (): void {});
        // Freeze acquired commerce before the member fence. Core erasure may
        // then join this owned transaction while a child waits on that member.
        $storePdo = (new ReflectionMethod(Store::class, 'pdo'))->invoke($store);
        $storePdo->beginTransaction();
        try {
            $lock = $storePdo->prepare('SELECT id FROM members WHERE id=? FOR UPDATE'); $lock->execute([$pausedMember['id']]); $lock->fetch();
            $worker = $startWorker('effects', ['orderId'=>$pausedOrder['order']['id']]);
            $waiting = false; $deadline = microtime(true) + 8;
            do {
                foreach ($pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $process) {
                    $sql = (string) ($process['Info'] ?? '');
                    if (($process['db'] ?? '') === $values['db']['name'] && str_contains($sql, 'SELECT id,preferences_json,deleted_at FROM members WHERE id=') && str_ends_with($sql, 'FOR UPDATE')) { $waiting = true; break; }
                }
                if ($waiting) break; usleep(20000);
            } while (microtime(true) < $deadline);
            $assert($waiting, 'Effect runner did not reach the real blocked member claim.');
            $store->privacyEraseCore($pausedMember['id'], $request, static function (): void {});
            $storePdo->commit();
        } finally { if ($storePdo->inTransaction()) $storePdo->rollBack(); }
        $finishWorker($worker, false);
        $resolvedEffect = $store->paymentJournal($pausedEffect['id']);
        $pausedData = $store->privacyCoreData($pausedMember['id']);
        $assert($resolvedEffect['state'] === 'skipped' && $resolvedEffect['data']['issue'] === 'account_closed' && count($deliveries()) === $deliveredBefore, 'A runner paused before its claim invoked an addon with pre-erasure data.');
        $assert($pausedData['mail'] === [] && $pausedData['entitlements'] === [] && !str_contains(json_encode($pausedData), 'PRIVATE-PAUSED-NAME'), 'Paused effects recreated erased private data or access.');
        $case('actual_sql_paused_effect_claim_after_privacy_erasure');
    }

    $erased = $store->createMember(['displayName'=>'PRIVATE-CLOSED-NAME','email'=>'private-closed@example.test','password'=>'Closed-Payment-Fixture-123!','status'=>'active','emailVerifiedAt'=>now_iso()]);
    $provider->afterAccepted = static function () use ($store, $erased): void {
        $request = str_repeat('d',32); $data = $store->privacyCoreData($erased['id']);
        $store->privacyFreeze($erased['id'], $request, Store::privacyDataRevision($data), static function (): void {});
        $store->privacyEraseCore($erased['id'], $request, static function (): void {});
    };
    $closedStock = $stock(); $closed = $commerce->checkout($erased['id'], $items, '', 'fixture-pay', [], 'fixture:closure-after-accept'); $provider->afterAccepted = null;
    $assert($closed['paymentOperation']['state'] === 'applied' && $closed['order']['status'] === 'paid' && $stock() === $closedStock - 1, 'Late confirmed payment could not preserve erased-member financial/reservation facts.');
    $closedData = $store->privacyCoreData($erased['id']);
    $assert($closedData['entitlements'] === [] && $closedData['events'] === [] && $closedData['mail'] === [] && !str_contains(json_encode($closedData), 'PRIVATE-CLOSED-NAME'), 'Late payment recreated closed-member identity/access/communications.');
    $commerce->refundOrder($closed['order']['id'], 'Late financial refund');
    $assert($store->order($closed['order']['id'])['status'] === 'refunded' && $stock() === $closedStock - 1, 'Erased shipping evidence incorrectly manufactured returned stock.');
    $inventoryHolds = array_values(array_filter($store->paymentJournalForOrder($closed['order']['id']), static fn(array $r): bool => $r['kind']==='effect' && $r['data']['effect']==='inventory'));
    $assert(count($inventoryHolds) === 1 && $inventoryHolds[0]['state'] === 'held' && $inventoryHolds[0]['data']['issue'] === 'inventory_review', 'Uncertain erased inventory did not leave an explicit review obligation.');
    $closedData = $store->privacyCoreData($erased['id']); $export = MemberPrivacyProjection::core($closedData);
    $assert(count($closedData['paymentJournal']) === count($store->paymentJournalForOrder($closed['order']['id'])) && count($export['paymentOperations']) === count($closedData['paymentJournal']), 'Own journal export omitted retained financial rows.');
    $assert(!str_contains(json_encode($export['paymentOperations']), 'originalTransactionId') && !str_contains(json_encode($export['paymentOperations']), 'installation') && !str_contains(json_encode($export['paymentOperations']), '"account":'), 'Own journal projection leaked private adapter scope/transaction details.');
    $operationExports = array_values(array_filter($export['paymentOperations'], static fn(array $r): bool => $r['kind']==='operation'));
    $assert(in_array('charge', array_column($operationExports,'operationType'), true) && in_array('refund',array_column($operationExports,'operationType'),true), 'Journal operation kind was lost in the safe export.');
    $case('privacy_erasure_late_finance_and_inventory_hold');

    // Freeze wins the shared commerce fence while a real first-charge worker
    // waits with its prepared order in memory. Dispatch must then refuse it.
    foreach ([['claim',9.50,'erasure'], ['claim',0.0,'erasure'], ['dispatch',9.50,'suspended'], ['dispatch',9.50,'unverified']] as [$boundary,$amount,$change]) {
        // A new request gets a fresh file CAS read context after another worker.
        [$store,$commerce,$provider,$registry]=payment_fixture_service($config);
        $label = $boundary . '-' . $change . '-' . (string) $amount;
        $firstMember = $store->createMember(['displayName'=>'PRIVATE-FIRST-' . $label,'email'=>$label . '@example.test','password'=>'First-Charge-Fixture-123!','status'=>'active','emailVerifiedAt'=>now_iso()]);
        [$firstOrder,$firstOperation] = payment_fixture_prepared_charge($store,$config,$registry,$firstMember,$label,$amount,(string)$video['id']);
        foreach (['charge-worker-ready','charge-worker-release','charge-worker-result','charge-worker-lock-ready'] as $marker) if (is_file($root . '/' . $marker)) unlink($root . '/' . $marker);
        $calls = $attempts('charge'); $deliveredBefore = count($deliveries());
        if ($change==='erasure') {
            $worker=$store->withCommerceLock(function()use($store,$startWorker,$waitCommerce,$firstMember,$firstOperation,$label,$root,$assert):array{
                file_put_contents($root . '/charge-worker-release','release');
                $worker=$startWorker('first-charge-claim',['operationId'=>$firstOperation['id']]);
                $waitCommerce($worker,'charge-worker-lock-ready');
                $assert($store->paymentJournal($firstOperation['id'])['state']==='prepared','A waiting charge bypassed the freeze-first commerce fence.');
                $request=md5('owned-first-charge-' . $label); $revision=Store::privacyDataRevision($store->privacyCoreData($firstMember['id']));
                $store->privacyFreeze($firstMember['id'],$request,$revision,static function():void{});
                $store->privacyEraseCore($firstMember['id'],$request,static function():void{});
                return $worker;
            });
        } else {
            $worker=$startWorker('first-charge-dispatch',['operationId'=>$firstOperation['id']]); $deadline=microtime(true)+8;
            while (!is_file($root . '/charge-worker-ready') && microtime(true)<$deadline) usleep(10000);
            $assert(is_file($root . '/charge-worker-ready') && $store->paymentJournal($firstOperation['id'])['state']==='attempting','First charge did not reach its real pre-dispatch pause.');
            $store->updateMember($firstMember['id'],$change==='suspended'?['status'=>'suspended']:['emailVerifiedAt'=>null]);
        }
        $retainedOrder=$store->order($firstOrder['id']); file_put_contents($root . '/charge-worker-release','release');
        $finishWorker($worker,false); $held=$store->paymentJournal($firstOperation['id']);
        $issue=$change==='erasure'?'account_closed':'account_ineligible';
        $assert($held['state']==='held' && $held['data']['issue']===$issue && $held['data']['result']===null,'Closed/ineligible first charge did not remain an explicit unattempted hold.');
        $assert(json_decode((string)file_get_contents($root . '/charge-worker-result'),true,8,JSON_THROW_ON_ERROR)===['state'=>'held','issue'=>$issue],'Worker returned the wrong first-charge refusal.');
        $assert($attempts('charge')===$calls && count($deliveries())===$deliveredBefore && $store->order($firstOrder['id'])===$retainedOrder
            && $store->paymentTransactionsForOrder($firstOrder['id'])===[] && $store->activeEntitlement($firstMember['id'],'video',$video['id'])===null,
            'A closed/ineligible first charge contacted a provider, altered finances, granted access or sent follow-ups.');
        if ($amount===0.0) {
            $lookups=count($provider->ledger()['lookups']); $freeHeld=$commerce->reconcilePaymentOperation($firstOperation['id']);
            $assert($freeHeld['operation']['state']==='held' && $freeHeld['operation']['data']['issue']==='account_closed'
                && $freeHeld['order']===$retainedOrder && $store->paymentTransactionsForOrder($firstOrder['id'])===[]
                && count($provider->ledger()['lookups'])===$lookups && $attempts('charge')===$calls,
                'Free-operation reconciliation bypassed the first-charge closure fence.');
        }
    }

    // Dispatch wins the same fence. Pause the fake provider after it receives
    // the final fresh subject, before it records any external acceptance.
    [$store,$commerce,$provider,$registry]=payment_fixture_service($config);
    $dispatchMember=$store->createMember(['displayName'=>'PRIVATE-DISPATCH-FENCE','email'=>'dispatch-fence@example.test','password'=>'Dispatch-Fence-Fixture-123!','status'=>'active','emailVerifiedAt'=>now_iso()]);
    [$dispatchOrder,$dispatchOperation]=payment_fixture_prepared_charge($store,$config,$registry,$dispatchMember,'dispatch-fence',9.50,(string)$video['id']);
    foreach (['charge-worker-ready','charge-worker-release','charge-worker-result','charge-fresh-subject','freeze-worker-ready','freeze-worker-result'] as $marker) if (is_file($root . '/' . $marker)) unlink($root . '/' . $marker);
    $calls=$attempts('charge'); $worker=$startWorker('first-charge-transport',['operationId'=>$dispatchOperation['id']]); $deadline=microtime(true)+8;
    while (!is_file($root . '/charge-worker-ready') && microtime(true)<$deadline) usleep(10000);
    $assert(is_file($root . '/charge-worker-ready') && hash_equals(hash('sha256',json_encode($store->order($dispatchOrder['id']),JSON_THROW_ON_ERROR)),trim((string)file_get_contents($root . '/charge-fresh-subject'))),
        'Provider barrier did not receive the current authoritative order after its final fresh read.');
    $dispatchRevision=Store::privacyDataRevision($store->privacyCoreData($dispatchMember['id']));
    $freeze=$startWorker('privacy-freeze',['memberId'=>$dispatchMember['id'],'requestId'=>md5('owned-dispatch-fence'),'revision'=>$dispatchRevision]);
    $waitCommerce($freeze,'freeze-worker-ready');
    $assert(!is_file($root . '/freeze-worker-result') && !$store->privacyMemberErased($dispatchMember['id']) && $attempts('charge')===$calls,
        'Privacy freeze committed between the final charge subject read and provider acceptance.');
    file_put_contents($root . '/charge-worker-release','release'); $finishWorker($worker,false); $finishWorker($freeze,false);
    $assert(trim((string)file_get_contents($root . '/freeze-worker-result'))==='409' && !$store->privacyMemberErased($dispatchMember['id'])
        && $store->paymentJournal($dispatchOperation['id'])['state']==='confirmed' && $attempts('charge')===$calls+1,
        'Waiting freeze failed to recheck the approved inventory after serialized dispatch.');
    $settledDispatch=$commerce->reconcilePaymentOperation($dispatchOperation['id']);
    $assert($settledDispatch['operation']['state']==='applied' && $settledDispatch['order']['status']==='paid' && $attempts('charge')===$calls+1,'Accepted serialized dispatch could not finish financially without another charge.');
    $newRevision=Store::privacyDataRevision($store->privacyCoreData($dispatchMember['id']));
    $freezeNested=static fn()=>$store->privacyFreeze($dispatchMember['id'],md5('owned-dispatch-fence'),$newRevision,static function():void{});
    try {
        $store->withCommerceLock(fn()=>$store->paymentAtomic($freezeNested,(string)$dispatchMember['id'])); $assert(false,'Freeze inverted the commerce/member order inside paymentAtomic.');
    } catch (RuntimeException $error) { $assert($error->getCode()===409 && str_contains($error->getMessage(),'acquire commerce'),'Nested payment freeze failed for an unrelated reason.'); }
    if ($mysql) {
        $storePdo=(new ReflectionMethod(Store::class,'pdo'))->invoke($store); $storePdo->beginTransaction();
        try { $freezeNested(); $assert(false,'Freeze entered commerce while an unrelated SQL transaction was open.'); }
        catch (RuntimeException $error) { $assert($error->getCode()===409 && str_contains($error->getMessage(),'acquire commerce'),'Nested SQL freeze failed for an unrelated reason.'); }
        finally { $storePdo->rollBack(); }
    } else {
        try { $store->mutateFileStore(static function(array &$data)use($freezeNested):void{$freezeNested();}); $assert(false,'Freeze entered commerce inside an existing file mutation.'); }
        catch (RuntimeException $error) { $assert($error->getCode()===409 && str_contains($error->getMessage(),'acquire commerce'),'Nested file freeze failed for an unrelated reason.'); }
    }
    $assert(Store::privacyDataRevision($store->privacyCoreData($dispatchMember['id']))===$newRevision,'Refusing inverted freeze lock order mutated member/financial data.');
    $store->privacyFreeze($dispatchMember['id'],md5('owned-dispatch-fence'),$newRevision,static function():void{});
    $store->privacyEraseCore($dispatchMember['id'],md5('owned-dispatch-fence'),static function():void{});
    $commerce->refundOrder($dispatchOrder['id'],'Retained financial refund after serialized closure');
    $assert($store->order($dispatchOrder['id'])['status']==='refunded' && $store->activeEntitlement($dispatchMember['id'],'video',$video['id'])===null,'Serialized closure prevented a retained refund or recreated access.');
    $case('actual_dispatch_freeze_exclusion_and_post_wait_inventory_review');

    [$freeOrder,$freeOperation]=payment_fixture_prepared_charge($store,$config,$registry,$other,'eligible-free-recovery',0.0,(string)$video['id']);
    $calls=$attempts('charge'); $lookups=count($provider->ledger()['lookups']);
    $freeRecovered=$commerce->reconcilePaymentOperation($freeOperation['id']); $commerce->reconcilePaymentOperation($freeOperation['id']);
    $assert($freeRecovered['operation']['state']==='applied' && $freeRecovered['order']['status']==='paid'
        && count($store->paymentTransactionsForOrder($freeOrder['id']))===1 && $store->activeEntitlement($other['id'],'video',$video['id'])!==null
        && $attempts('charge')===$calls && count($provider->ledger()['lookups'])===$lookups,'Eligible free-operation recovery changed or invoked a provider.');
    [$freeConflictOrder,$freeConflict]=payment_fixture_prepared_charge($store,$config,$registry,$other,'ambiguous-free-recovery',0.0,(string)$video['id']);
    $store->savePaymentTransaction(['orderId'=>$freeConflictOrder['id'],'providerKey'=>'fixture-pay','providerTransactionId'=>'free_' . substr($freeConflict['id'],4),
        'transactionType'=>'charge','status'=>'paid','amount'=>0,'currency'=>'USD']);
    $freeConflictFacts=[$store->order($freeConflictOrder['id']),$store->paymentTransactionsForOrder($freeConflictOrder['id'])];
    $heldFree=$commerce->reconcilePaymentOperation($freeConflict['id']);
    $assert($heldFree['operation']['state']==='held' && $heldFree['operation']['data']['issue']==='malformed_result'
        && [$store->order($freeConflictOrder['id']),$store->paymentTransactionsForOrder($freeConflictOrder['id'])]===$freeConflictFacts
        && $attempts('charge')===$calls && count($provider->ledger()['lookups'])===$lookups,'Free recovery adopted an unbound local reference or failed to retain its hold.');
    $case('paused_first_charge_closure_eligibility_and_free_recovery_fences');

    $recovery = new PaymentRecoveryService($store); $seen = []; $cursor = null;
    do {
        $page = $recovery->page($cursor, 7, false);
        $assert(count($page['rows']) <= 7, 'Owner inspection exceeded its bounded page size.');
        foreach ($page['rows'] as $row) {
            $assert(!isset($seen[$row['id']]), 'Owner keyset pagination repeated a record.'); $seen[$row['id']] = true;
            $assert(array_keys($row) === ['id','kind','state','orderId','orderNumber','operationId','operationType','provider','amountMinor','currency','effect','eventType','reasonCode','createdAt','updatedAt','canReconcile','canRetryEffects'], 'Owner projection exposed unreviewed fields.');
        }
        $cursor = $page['nextCursor'];
    } while ($cursor !== null);
    $expectedIds = $mysql ? $pdo->query('SELECT id FROM payment_journal ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) : array_keys($store->rawFileData()['paymentJournal']);
    sort($expectedIds, SORT_STRING); $actualIds = array_keys($seen); sort($actualIds, SORT_STRING);
    $assert($actualIds === $expectedIds, 'Owner pagination omitted journal records.');
    $orderPage = $recovery->page(null,200,false,$closed['order']['id']);
    $assert(count($orderPage['rows']) === count($store->paymentJournalForOrder($closed['order']['id'])) && array_unique(array_column($orderPage['rows'],'orderId')) === [$closed['order']['id']], 'Owner exact order inspection crossed an order boundary or truncated silently.');
    $inventoryProjection = $recovery->inspect($inventoryHolds[0]['id']);
    $assert(!$inventoryProjection['canRetryEffects'] && !$inventoryProjection['canReconcile'] && $inventoryProjection['reasonCode'] === 'inventory_review', 'Inventory uncertainty was advertised as an automatic external retry.');
    foreach ($recovery->page(null,200,true)['rows'] as $row) $assert(!in_array($row['state'],['applied','declined','ignored','done','skipped'],true), 'Open owner queue included a terminal record.');
    $reject(fn() => $recovery->page('../anything',50), 'Malformed recovery cursor was accepted.');
    $reject(fn() => $recovery->inspect('payment-secret'), 'Unstructured record lookup was accepted.');
    $case('safe_complete_owner_inspection_and_action_flags');

    $heldId = $scopeOrder['paymentOperation']['id']; $beforeGuard = $store->paymentJournal($heldId); $lookups = count($provider->ledger()['lookups']);
    foreach ([403,428] as $denial) {
        try { $commerce->reconcilePaymentOperation($heldId, static function () use ($denial): void { throw new RuntimeException('Trusted owner guard denied.', $denial); }); $assert(false, 'Owner guard was ignored.'); }
        catch (App\Core\PaymentRecoveryAuthorizationException $error) { $assert($error->getCode() === $denial, 'Owner guard reason was converted into a payment outcome.'); }
    }
    $guardCalls = 0;
    try { $commerce->reconcilePaymentOperation($heldId, static function () use (&$guardCalls): void { if (++$guardCalls > 1) throw new RuntimeException('Owner confirmation expired before lookup.',428); }); $assert(false,'Expired confirmation reached provider lookup.'); }
    catch (App\Core\PaymentRecoveryAuthorizationException $error) { $assert($error->getCode() === 428 && $guardCalls === 2, 'Provider lookup did not reauthorize the owner.'); }
    $assert($store->paymentJournal($heldId) === $beforeGuard && count($provider->ledger()['lookups']) === $lookups, 'Denied owner lookup mutated payment state or called a provider.');
    file_put_contents($root . '/owner-allowed', 'yes');
    $worker = $store->withCommerceLock(function () use ($startWorker, $root, $heldId, $mysql, $pdo, $values, $assert): array {
        $worker = $startWorker('guarded-reconcile', ['operationId'=>$heldId]); $deadline = microtime(true) + 8; $blocked = false; $waitChannel = '';
        do {
            if ($mysql) {
                foreach ($pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $process) {
                    if (($process['db'] ?? '') === $values['db']['name'] && str_starts_with((string)($process['Info']??''),'SELECT GET_LOCK(')) { $blocked = true; break; }
                }
            } else {
                $pid = proc_get_status($worker[0])['pid']; $path = '/proc/' . $pid . '/wchan';
                $waitChannel = is_file($path) ? trim((string) file_get_contents($path)) : 'missing';
                $blocked = str_contains($waitChannel, 'locks_lock');
            }
            if ($blocked && is_file($root . '/guard-worker-ready')) break; usleep(20000);
        } while (microtime(true) < $deadline);
        $diagnostic = $blocked ? '' : json_encode(proc_get_status($worker[0]),JSON_THROW_ON_ERROR) . ' ' . stream_get_contents($worker[1][2]);
        $assert($blocked && is_file($root . '/guard-worker-ready'), 'Owner worker did not reach its actual waiting commerce mutex (wait channel: ' . $waitChannel . '). ' . $diagnostic);
        file_put_contents($root . '/owner-allowed', 'no'); return $worker;
    });
    $finishWorker($worker, false);
    $assert(trim((string) file_get_contents($root . '/guard-worker-result')) === '403' && $store->paymentJournal($heldId) === $beforeGuard && count($provider->ledger()['lookups']) === $lookups, 'Stale owner authority survived the commerce wait.');
    $store->setAddonsSafeMode(true); $guardedEffectOrder = $commerce->checkout($member['id'],$digital,'','fixture-pay',[],'fixture:guard-before-effect'); $store->setAddonsSafeMode(false);
    $guardedEffect = array_values(array_filter($store->paymentJournalForOrder($guardedEffectOrder['order']['id']),static fn(array $r):bool=>$r['kind']==='effect' && $r['data']['effect']==='addon'))[0];
    $deliveredBefore = count($deliveries());
    try {
        $commerce->retryPaymentEffects($guardedEffectOrder['order']['id'], static function () use ($store, $guardedEffect): void { if ($store->paymentJournal($guardedEffect['id'])['state'] === 'attempting') throw new RuntimeException('Owner confirmation expired before delivery.',428); });
        $assert(false,'External dispatch did not reauthorize after its claim.');
    } catch (App\Core\PaymentRecoveryAuthorizationException $error) { $assert($error->getCode() === 428,'External owner guard reason was lost.'); }
    $assert(count($deliveries()) === $deliveredBefore && $store->paymentJournal($guardedEffect['id'])['state'] === 'attempting', 'Denied external dispatch called a listener or fabricated completion.');
    $commerce->retryPaymentEffects($guardedEffectOrder['order']['id']);
    $assert(count($deliveries()) === $deliveredBefore && $store->paymentJournal($guardedEffect['id'])['state'] === 'held','Interrupted authorized claim was automatically resent.');
    $case('actual_waiting_owner_revocation_and_recent_auth_boundaries');

    // Use real Auth sessions and the real current saved owner role. The child
    // passes authorization before waiting; demotion/expiry happens while its
    // commerce lock is observably blocked, before any refund intent exists.
    $ownerId=(string)$store->createAdmin('Payment owner','payment-owner@example.test','Payment-Owner-Fixture-123!');
    mkdir($root . '/sessions',0700);
    $provider->chargeMode='success'; $provider->refundMode='success';
    $authorityOrder=$commerce->checkout($member['id'],$items,'','fixture-pay',[],'fixture:refund-authority');
    $refundFacts=static fn():array=>[$store->order($authorityOrder['order']['id']),$store->paymentJournalForOrder($authorityOrder['order']['id']),$store->paymentTransactionsForOrder($authorityOrder['order']['id']),$stock(),count($deliveries())];
    foreach ([403,428] as $denial) {
        foreach (['refund-worker-ready','refund-worker-result'] as $marker) if (is_file($root . '/' . $marker)) unlink($root . '/' . $marker);
        $beforeRefund=$refundFacts(); $calls=$attempts('refund');
        $worker=$store->withCommerceLock(function()use($startWorker,$root,$authorityOrder,$denial,$mysql,$pdo,$values,$assert,$ownerId,$store):array{
            $worker=$startWorker('guarded-refund',['orderId'=>$authorityOrder['order']['id'],'denial'=>$denial]); $deadline=microtime(true)+8; $blocked=false;
            do {
                if ($mysql) {
                    foreach ($pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $process) if (($process['db']??'')===$values['db']['name'] && str_starts_with((string)($process['Info']??''),'SELECT GET_LOCK(')) { $blocked=true; break; }
                } else {
                    $path='/proc/' . proc_get_status($worker[0])['pid'] . '/wchan';
                    $blocked=is_file($path) && str_contains(trim((string)file_get_contents($path)),'locks_lock');
                }
                if ($blocked && is_file($root . '/refund-worker-ready')) break; usleep(10000);
            } while (microtime(true)<$deadline);
            $assert($blocked && is_file($root . '/refund-worker-ready'),'Refund owner did not reach the real blocked commerce mutex.');
            $ready=json_decode((string)file_get_contents($root . '/refund-worker-ready'),true,8,JSON_THROW_ON_ERROR);
            $assert($ready['role']==='owner','Refund worker did not begin as a real authorized owner.');
            if ($denial===403) {
                if ($mysql) $pdo->prepare("UPDATE admins SET role='editor' WHERE id=?")->execute([$ownerId]);
                else { $data=$store->rawFileData(); $data['admins'][$ownerId]['role']='editor'; $store->replaceFileData($data); }
            } else {
                $deadline=microtime(true)+4; while (time()<=$ready['authenticatedAt']+900 && microtime(true)<$deadline) usleep(20000);
                $assert(time()>$ready['authenticatedAt']+900,'Real recent-owner confirmation did not expire while waiting.');
            }
            return $worker;
        });
        $finishWorker($worker,false);
        $assert(trim((string)file_get_contents($root . '/refund-worker-result'))===(string)$denial && $refundFacts()===$beforeRefund && $attempts('refund')===$calls,
            'Stale refund authority survived the commerce wait, mutated intent/finances or reached the provider.');
        if ($denial===403) {
            if ($mysql) $pdo->prepare("UPDATE admins SET role='owner' WHERE id=?")->execute([$ownerId]);
            else { $data=$store->rawFileData(); $data['admins'][$ownerId]['role']='owner'; $store->replaceFileData($data); }
        }
    }
    $beforeRefund=$refundFacts(); $calls=$attempts('refund');
    try {
        $commerce->refundOrder($authorityOrder['order']['id'],'Denied inside intent commit',static function()use($store):void{
            if ((new ReflectionProperty(Store::class,'paymentAtomicActive'))->getValue($store)) throw new RuntimeException('Confirmation expired while the commit waited.',428);
        }); $assert(false,'Refund intent commit ignored the trusted owner guard.');
    } catch (App\Core\PaymentRecoveryAuthorizationException $error) { $assert($error->getCode()===428,'Refund commit lost the trusted denial reason.'); }
    $assert($refundFacts()===$beforeRefund && $attempts('refund')===$calls,'Denied refund intent committed partial facts.');
    try {
        $commerce->refundOrder($authorityOrder['order']['id'],'Denied before transport',static function()use($store,$authorityOrder):void{
            foreach ($store->paymentJournalForOrder($authorityOrder['order']['id']) as $row) if ($row['kind']==='operation' && $row['data']['binding']['kind']==='refund' && $row['state']==='attempting') throw new RuntimeException('Confirmation expired before refund dispatch.',428);
        }); $assert(false,'Refund dispatch ignored the trusted owner guard.');
    } catch (App\Core\PaymentRecoveryAuthorizationException $error) { $assert($error->getCode()===428,'Refund dispatch lost the trusted denial reason.'); }
    $refundRows=array_values(array_filter($store->paymentJournalForOrder($authorityOrder['order']['id']),static fn(array$r):bool=>$r['kind']==='operation' && $r['data']['binding']['kind']==='refund'));
    $assert(count($refundRows)===1 && $refundRows[0]['state']==='attempting' && $refundRows[0]['data']['result']===null && $attempts('refund')===$calls
        && $store->order($authorityOrder['order']['id'])['status']==='paid' && $store->paymentTransactionsForOrder($authorityOrder['order']['id'])===$beforeRefund[2]
        && $stock()===$beforeRefund[3] && count($deliveries())===$beforeRefund[4],'Denied dispatch sent a refund or fabricated a final outcome.');
    $reject(fn()=>$commerce->refundOrder($authorityOrder['order']['id'],'No second attempt'),'An unresolved denied refund was automatically resent.');
    $assert($attempts('refund')===$calls,'A second refund was sent after the interrupted claim.');
    $acceptedAuthority=$commerce->checkout($member['id'],$digital,'','fixture-pay',[],'fixture:refund-accepted-authority'); $expired=false;
    $provider->afterAccepted=static function(array$binding)use(&$expired):void{if($binding['kind']==='refund')$expired=true;};
    $calls=$attempts('refund');
    try {
        $commerce->refundOrder($acceptedAuthority['order']['id'],'Accepted before confirmation expiry',static function()use(&$expired):void{if($expired)throw new RuntimeException('Confirmation expired after the external response.',428);});
        $assert(false,'Expired post-response authority completed a refund commit.');
    } catch (App\Core\PaymentRecoveryAuthorizationException $error) { $assert($error->getCode()===428,'Post-response authorization was converted into provider failure.'); }
    finally { $provider->afterAccepted=null; }
    $acceptedRefund=array_values(array_filter($store->paymentJournalForOrder($acceptedAuthority['order']['id']),static fn(array$r):bool=>$r['kind']==='operation' && $r['data']['binding']['kind']==='refund'))[0];
    $assert($acceptedRefund['state']==='attempting' && $attempts('refund')===$calls+1 && $store->order($acceptedAuthority['order']['id'])['status']==='paid','Accepted refund lost its durable uncertain boundary after auth expiry.');
    $resolved=$commerce->reconcilePaymentOperation($acceptedRefund['id']);
    $assert($resolved['operation']['state']==='applied' && $resolved['order']['status']==='refunded' && $attempts('refund')===$calls+1,'Lookup-only recovery failed or repeated the already accepted refund.');
    $case('actual_owner_demotion_expiry_and_refund_dispatch_recovery');

    $plan = $store->saveMembershipPlan(['name'=>'Fixture recurring plan','price'=>12,'isActive'=>true]); $calls = $attempts('charge');
    $reject(fn() => $commerce->checkout($member['id'], [['type'=>'plan','id'=>$plan['id']]], '', 'fixture-pay', [], 'fixture:uncertified-recurring'), 'A non-sandbox provider fabricated a recurring agreement.');
    $assert($attempts('charge') === $calls && $store->order('fixture:uncertified-recurring') === null, 'Uncertified recurring attempt reached a provider/order.');
    $sandbox = $commerce->checkout($member['id'], [['type'=>'plan','id'=>$plan['id']]], '', 'sandbox', ['sandboxResult'=>'success'], 'fixture:sandbox-recurring');
    $assert($sandbox['order']['status'] === 'paid' && count($store->activeSubscriptions($member['id'])) === 1, 'Existing sandbox recurring behavior changed.');
    $case('explicit_recurring_adapter_boundary_and_sandbox_compatibility');

    if ($mysql) {
        $beforeUpgrade = [$store->order($a['order']['id']),$store->paymentJournalForOrder($a['order']['id']),$stock()];
        $assert((new App\Core\MigrationRunner($pdo,$source.'/database/migrations'))->migrate(false,true) === [], 'Verified migration resume reapplied payment schema work.');
        $assert([$store->order($a['order']['id']),$store->paymentJournalForOrder($a['order']['id']),$stock()] === $beforeUpgrade, 'Migration resume changed existing financial facts.');
        $case('migration_resume_preserves_financial_records');
    }

    $assert(PaymentFoundationFakeProvider::$contextObservations !== [] && array_unique(PaymentFoundationFakeProvider::$contextObservations) === [false], 'An account-context callback entered a financial commit.');
    $report = ['ok'=>true,'assertions'=>$checks,'mode'=>$mysql?'mysql':'file','mysql_version'=>$pdo?->query('SELECT VERSION()')->fetchColumn(),
        'php'=>PHP_VERSION,'real_provider_requests'=>0,'smtp_connections'=>0,'killed_workers'=>$killed,'cases'=>$cases,
        'account_context_callbacks'=>count(PaymentFoundationFakeProvider::$contextObservations),'account_context_callbacks_in_financial_commit'=>count(array_filter(PaymentFoundationFakeProvider::$contextObservations)),
        'provider_charge_attempts'=>$attempts('charge'),'provider_refund_attempts'=>$attempts('refund'),'provider_read_only_lookups'=>count($provider->ledger()['lookups'])];
} finally {
    foreach ($children as $process) if (is_resource($process)) { $state=proc_get_status($process); if ($state['running']) proc_terminate($process,9); proc_close($process); }
    if ($owns && $pdo) { if ($pdo->inTransaction()) $pdo->rollBack(); vvx_installer_fixture_reset_database($pdo, true); vvx_installer_fixture_empty($pdo); }
    vvx_installer_fixture_remove($root);
}
$report['owned_schema_cleaned'] = $mysql; $report['owned_files_cleaned'] = !file_exists($root);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
