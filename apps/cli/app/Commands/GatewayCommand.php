<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\EmitsCanonicalEnvelopes;
use App\Commands\Concerns\ResolvesNodeTransportPreference;
use App\Exceptions\GatewayApiException;
use App\Services\GatewayApiClient;
use LaravelZero\Framework\Commands\Command;

abstract class GatewayCommand extends Command
{
    use EmitsCanonicalEnvelopes;
    use ResolvesNodeTransportPreference;

    protected function gateway(): GatewayApiClient
    {
        $client = app(GatewayApiClient::class);
        $preference = $this->nodeTransportPreference();

        if ($preference === null) {
            return $client;
        }

        return $client->withNodeTransportPreference($preference);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function gatewayGet(string $path, array $query = []): array
    {
        return $this->gateway()->get($path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function gatewayPost(string $path, array $payload = []): array
    {
        return $this->gateway()->post($path, $payload);
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
