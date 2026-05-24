<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\GatewayApiClient;
use Illuminate\Support\ServiceProvider;

final class GatewayApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GatewayApiClient::class, function (): GatewayApiClient {
            return new GatewayApiClient(
                baseUrl: $this->nullableString(config('orbit.gateway.url')),
                identity: $this->nullableString(config('orbit.gateway.identity')),
                timeout: $this->positiveInteger(config('orbit.gateway.timeout'), 30),
            );
        });
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function positiveInteger(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        $value = (int) $value;

        return $value > 0 ? $value : $default;
    }
}
