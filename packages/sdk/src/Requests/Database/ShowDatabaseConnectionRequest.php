<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Database;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Database\DatabaseConnectionResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowDatabaseConnectionRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $connection,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/database-connections/'.rawurlencode($this->connection);
    }

    public function createDtoFromResponse(Response $response): DatabaseConnectionResponse
    {
        $data = $this->unwrapData($response);

        return new DatabaseConnectionResponse(
            connection: is_array($data['connection'] ?? null) ? $data['connection'] : [],
        );
    }
}
