<?php
declare(strict_types=1);

namespace App\Payments;

use RuntimeException;

final class PaymentProviderRegistry
{
    /** @var array<string,PaymentProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new SandboxPaymentProvider());
    }

    public function register(PaymentProviderInterface $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): PaymentProviderInterface
    {
        if (!isset($this->providers[$key])) {
            throw new RuntimeException('The selected payment provider is not installed.');
        }
        return $this->providers[$key];
    }

    /** @return list<PaymentProviderInterface> */
    public function all(): array
    {
        return array_values($this->providers);
    }
}
