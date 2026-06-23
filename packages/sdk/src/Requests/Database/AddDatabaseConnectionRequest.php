<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Database;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Database\DatabaseConnectionResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AddDatabaseConnectionRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/database-connections';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->payload;
    }

    public function createDtoFromResponse(Response $response): DatabaseConnectionResponse
    {
        $data = $this->unwrapData($response);

        return new DatabaseConnectionResponse(
            connection: is_array($data['connection'] ?? null) ? $data['connection'] : [],
        );
    }
}
