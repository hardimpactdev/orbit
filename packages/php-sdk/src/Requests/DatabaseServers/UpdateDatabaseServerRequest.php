<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseServers;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateDatabaseServerRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly string $slug,
        private readonly bool $requirePassword,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-servers/'.rawurlencode($this->slug);
    }

    /** @return array{require_password: bool} */
    protected function defaultBody(): array
    {
        return ['require_password' => $this->requirePassword];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
