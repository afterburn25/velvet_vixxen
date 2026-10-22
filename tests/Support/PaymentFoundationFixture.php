<?php
declare(strict_types=1);

/** Local fixture only. No URLs, sockets, credentials or production adapter calls. */
final class PaymentFoundationFakeProvider implements \App\Payments\BoundPaymentProviderInterface
{
    public string $chargeMode = 'success';
    public string $refundMode = 'success';
    public ?Closure $afterAccepted = null;
    public ?Closure $alterResult = null;
    public ?Closure $observeContext = null;
    public ?Closure $afterContext = null;
    public ?Closure $beforeCharge = null;
    public static array $contextObservations = [];

    public function __construct(private readonly string $ledgerPath) {}
    public function key(): string { return 'fixture-pay'; }
    public function label(): string { return 'Owned local payment fixture'; }
    public function supportsRecurring(): bool { return true; }
    public function accountContext(array $configuration): array
    {
        $active = $this->observeContext ? ($this->observeContext)() : null;
        self::$contextObservations[] = $active;
        if ($active !== false) throw new RuntimeException('Fixture account callback ran without an observed outside-commit boundary.');
        $context = ['account' => (string) ($configuration['settings']['account'] ?? hash('sha256', 'fixture-account')), 'environment' => (string) ($configuration['settings']['environment'] ?? 'test')];
        if ($this->afterContext) ($this->afterContext)();
        return $context;
    }
    public function charge(array $order, array $paymentInput): array { throw new RuntimeException('Unbound fixture charge API must never be used.'); }
    public function refund(array $transaction, float $amount, string $reason = ''): array { throw new RuntimeException('Unbound fixture refund API must never be used.'); }
    public function chargeBound(array $operation, array $order, array $paymentInput): array
    {
        if ($this->beforeCharge) ($this->beforeCharge)($operation, $order);
        return $this->attempt($operation, $this->chargeMode);
    }
    public function refundBound(array $operation, array $transaction, string $reason = ''): array { return $this->attempt($operation, $this->refundMode); }

    public function ledger(): array
    {
        return is_file($this->ledgerPath) ? json_decode((string) file_get_contents($this->ledgerPath), true, 64, JSON_THROW_ON_ERROR) : ['attempts' => [], 'lookups' => [], 'results' => []];
    }

    public function seedResult(array $operation, array $result): void
    {
        $ledger = $this->ledger(); $ledger['results'][$operation['operationId']] = $result; $this->save($ledger);
    }

    private function save(array $ledger): void
    {
        $temp = $this->ledgerPath . '.tmp';
        $handle = fopen($temp, 'wb'); chmod($temp, 0600);
        fwrite($handle, json_encode($ledger, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); fflush($handle); fsync($handle); fclose($handle);
        if (!rename($temp, $this->ledgerPath)) throw new RuntimeException('Fixture provider ledger could not be persisted.');
    }

    private function attempt(array $operation, string $mode): array
    {
        $ledger = $this->ledger(); $id = $operation['operationId'];
        $ledger['attempts'][] = ['operationId' => $id, 'kind' => $operation['kind']];
        $result = ['schema' => 1, 'binding' => $operation, 'outcome' => match ($mode) { 'decline' => 'declined', 'pending' => 'pending', default => 'succeeded' },
            'transactionId' => $mode === 'pending' ? null : 'fixture_' . $operation['kind'] . '_' . substr($id, 4), 'observedAt' => time()];
        $ledger['results'][$id] = $result; $this->save($ledger);
        if ($this->afterAccepted) ($this->afterAccepted)($operation, $result);
        if ($mode === 'kill') { posix_kill(getmypid(), SIGKILL); exit(98); }
        if ($mode === 'timeout') throw new RuntimeException('LOCAL FIXTURE accepted the operation before losing its response.');
        if ($this->alterResult) $result = ($this->alterResult)($result);
        return $result;
    }

    public function lookupOperation(array $operation): array
    {
        $ledger = $this->ledger(); $ledger['lookups'][] = $operation['operationId']; $this->save($ledger);
        return $ledger['results'][$operation['operationId']] ?? ['schema' => 1, 'binding' => $operation, 'outcome' => 'unknown', 'transactionId' => null, 'observedAt' => time()];
    }

    public function parseWebhook(string $rawBody, string $signature, string $secret): array
    {
        return ['valid' => strlen($signature) === 64 && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature),
            'event' => json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR)];
    }
}

function payment_fixture_service(\App\Core\Config $config): array
{
    $store = new \App\Core\Store($config); $registry = new \App\Payments\PaymentProviderRegistry();
    $provider = new PaymentFoundationFakeProvider(dirname($config->path(), 2) . '/provider-ledger.json'); $registry->register($provider);
    $provider->observeContext = static fn(): bool => (new ReflectionProperty(\App\Core\Store::class, 'paymentAtomicActive'))->getValue($store);
    return [$store, new \App\Core\CommerceService($store, $config, new \App\Core\MailService($config, $store), $registry), $provider, $registry];
}

/** Real prepared order/journal; no provider attempt or invented result. */
function payment_fixture_prepared_charge(\App\Core\Store $store, \App\Core\Config $config, \App\Payments\PaymentProviderRegistry $registry, array $member, string $label, float $amount, string $videoId): array
{
    $payments = new \App\Core\PaymentOperationService($store, $config, $registry);
    return $store->withCommerceLock(static function () use ($store, $payments, $member, $label, $amount, $videoId): array {
        $scope = $payments->captureScope('fixture-pay');
        return $store->paymentAtomic(static function () use ($store, $payments, $member, $label, $amount, $videoId, $scope): array {
            $payments->assertCurrentScope($scope);
            $order = $store->createOrder(['memberId'=>$member['id'], 'status'=>'processing', 'subtotal'=>$amount, 'grandTotal'=>$amount,
                'currency'=>'USD', 'providerKey'=>'fixture-pay', 'idempotencyKey'=>'fixture:prepared:' . $label,
                'billingEmail'=>$member['email'], 'metadata'=>['private'=>'PRIVATE-PREPARED-' . $label]],
                [['itemType'=>'video', 'itemId'=>$videoId, 'title'=>'Owned prepared video', 'unitPrice'=>$amount, 'lineTotal'=>$amount, 'quantity'=>1, 'metadata'=>['purchaseMode'=>'purchase']]]);
            return [$order, $payments->prepare($payments->bindingInScope($order, $scope['scope'], 'charge', \App\Payments\PaymentContract::minor($amount)), 'checkout')];
        }, (string) $member['id']);
    });
}

function payment_fixture_addon(\App\Core\Store $store, string $root): void
{
    if (!is_file($root . '/.payment-foundation-fixture')) throw new RuntimeException('Owned payment fixture required.');
    $path = $root . '/addons/installed/local.payment.fixture'; mkdir($path, 0700, true);
    $manifest = ['id' => 'local.payment.fixture', 'name' => 'Owned payment listener', 'vendor' => 'Acceptance fixture', 'version' => '1.0.0',
        'minimum_core_version' => '0.9.0', 'minimum_php_version' => '8.2.0', 'permissions' => ['events.subscribe'], 'bootstrap' => 'bootstrap.php'];
    file_put_contents($path . '/addon.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    copy(__DIR__ . '/payment_foundation_addon.php', $path . '/bootstrap.php');
    $store->saveAddon(['addonKey' => $manifest['id'], 'status' => 'active', 'manifest' => $manifest]);
}
