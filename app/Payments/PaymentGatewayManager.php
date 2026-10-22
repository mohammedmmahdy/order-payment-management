<?php

namespace App\Payments;

use App\Exceptions\UnsupportedPaymentMethodException;
use App\Payments\Contracts\PaymentGateway;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Registry/factory for payment gateways.
 *
 * Gateways are declared in config/payment.php as
 *   '<payment_method>' => ['driver' => GatewayClass::class, 'enabled' => true, ...credentials]
 * so adding a gateway never requires touching this class, the services
 * or the controllers.
 */
class PaymentGatewayManager
{
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {}

    /**
     * Payment methods that are currently enabled.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return array_keys($this->definitions());
    }

    public function supports(string $method): bool
    {
        return array_key_exists($method, $this->definitions());
    }

    /**
     * Build the gateway for a payment method.
     *
     * @throws UnsupportedPaymentMethodException
     */
    public function gateway(string $method): PaymentGateway
    {
        $definition = $this->definitions()[$method] ?? throw new UnsupportedPaymentMethodException($method);

        $driver = $definition['driver'] ?? null;

        if (! is_string($driver) || ! class_exists($driver)) {
            throw new InvalidArgumentException("Payment gateway '{$method}' has no valid driver class configured.");
        }

        $gateway = $this->container->make($driver, [
            'config' => Arr::except($definition, ['driver', 'enabled']),
        ]);

        if (! $gateway instanceof PaymentGateway) {
            throw new InvalidArgumentException("[{$driver}] must implement ".PaymentGateway::class.'.');
        }

        return $gateway;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        return array_filter(
            (array) $this->config->get('payment.gateways', []),
            fn ($definition) => (bool) ($definition['enabled'] ?? true),
        );
    }
}
