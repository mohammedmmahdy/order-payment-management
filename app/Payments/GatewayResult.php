<?php

namespace App\Payments;

/**
 * Immutable outcome of a gateway charge attempt.
 */
final class GatewayResult
{
    /**
     * @param  array<string, mixed>  $response  safe-to-store gateway response (never card data)
     */
    private function __construct(
        public readonly bool $successful,
        public readonly ?string $transactionId,
        public readonly ?string $message,
        public readonly array $response = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public static function success(string $transactionId, array $response = [], ?string $message = null): self
    {
        return new self(true, $transactionId, $message, $response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public static function failure(string $message, array $response = []): self
    {
        return new self(false, null, $message, $response);
    }
}
