<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Database;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Database\DatabaseConnectionResultResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveDatabaseConnectionRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $connection,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/database-connections/'.rawurlencode($this->connection);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['force' => true];
    }

    public function createDtoFromResponse(Response $response): DatabaseConnectionResultResponse
    {
        $data = $this->unwrapData($response);

        return new DatabaseConnectionResultResponse(
            result: $this->stringKeyedArray($data['result'] ?? []),
        );
    }
}
