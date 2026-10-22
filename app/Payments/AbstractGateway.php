<?php

namespace App\Payments;

use App\Payments\Contracts\PaymentGateway;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Convenience base class: receives this gateway's config block from
 * config/payment.php (API keys, secrets, mode...) and offers small helpers.
 */
abstract class AbstractGateway implements PaymentGateway
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config = [])
    {
    }

    public function rules(): array
    {
        return [];
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }

    protected function newTransactionId(string $prefix): string
    {
        return $prefix.'_'.Str::lower(Str::random(16));
    }
}
