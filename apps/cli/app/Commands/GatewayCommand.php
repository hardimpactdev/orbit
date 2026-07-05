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

    private const string NODE_TRANSPORT_OPTION = 'node-transport';

    private const array NODE_TRANSPORT_PREFERENCES = [
        'agent-push',
        'auto',
        'transitional-ssh-fallback',
    ];

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

    protected function nodeTransportPreference(): ?string
    {
        if (! $this->input->hasOption(self::NODE_TRANSPORT_OPTION)) {
            return null;
        }

        $preference = $this->option(self::NODE_TRANSPORT_OPTION);

        if (! is_string($preference) || trim($preference) === '') {
            return null;
        }

        $preference = trim($preference);

        if (! in_array($preference, self::NODE_TRANSPORT_PREFERENCES, strict: true)) {
            throw new GatewayApiException(
                "Invalid node transport preference [{$preference}].",
                statusCode: 422,
                body: json_encode([
                    'error' => [
                        'code' => 'validation_failed',
                        'message' => "Invalid node transport preference [{$preference}].",
                        'meta' => [
                            'field' => self::NODE_TRANSPORT_OPTION,
                            'allowed' => self::NODE_TRANSPORT_PREFERENCES,
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            );
        }

        return $preference;
    }
}
