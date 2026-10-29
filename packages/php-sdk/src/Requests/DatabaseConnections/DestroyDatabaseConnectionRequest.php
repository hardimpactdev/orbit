<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseConnections;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DestroyedDatabaseConnectionResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DestroyDatabaseConnectionRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly string $slug,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-connections/'.rawurlencode($this->slug);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DestroyedDatabaseConnectionResponse
    {
        if ($response->status() === 202) {
            return new DestroyedDatabaseConnectionResponse(OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response)));
        }

        return new DestroyedDatabaseConnectionResponse(DatabaseConnectionResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response)));
    }
}
