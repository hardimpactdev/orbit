<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\EmitsCanonicalEnvelopes;
use App\Exceptions\GatewayApiException;
use App\Services\GatewayApiClient;
use LaravelZero\Framework\Commands\Command;

abstract class GatewayCommand extends Command
{
    use EmitsCanonicalEnvelopes;

    protected function gateway(): GatewayApiClient
    {
        return app(GatewayApiClient::class);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function gatewayGet(string $path, array $query = [], array $headers = []): array
    {
        return $this->gateway()->get($path, $query, $headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function gatewayPost(string $path, array $payload = [], array $headers = []): array
    {
        return $this->gateway()->post($path, $payload, $headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function gatewayPostWithIdleTicks(string $path, array $payload = []): array
    {
        return $this->gateway()->postWithIdleTicks($path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function gatewayPut(string $path, array $payload = []): array
    {
        return $this->gateway()->put($path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function gatewayPatch(string $path, array $payload = []): array
    {
        return $this->gateway()->patch($path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function gatewayDelete(string $path, array $payload = []): array
    {
        return $this->gateway()->delete($path, $payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function associativeArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                return null;
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    protected function renderGatewayFailure(GatewayApiException $exception): int
    {
        if ($exception->hasGatewayError()) {
            return $this->renderFailure(
                $exception->gatewayErrorCode() ?? $exception->cliFailureCode(),
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                $exception->gatewayErrorMeta(),
                $exception->gatewayErrorData(),
            );
        }

        return $this->renderFailure($exception->cliFailureCode(), $exception->getMessage());
    }
}
