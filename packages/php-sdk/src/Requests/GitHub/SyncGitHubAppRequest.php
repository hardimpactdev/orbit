<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\GitHub;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Support\GatewayRequestId;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class SyncGitHubAppRequest extends GatewayRequest
{
    protected Method $method = Method::POST;

    private readonly string $operationKey;

    public function __construct(?string $operationKey = null)
    {
        $this->operationKey = $operationKey ?? GatewayRequestId::generate();
    }

    protected function defaultHeaders(): array
    {
        return ['Idempotency-Key' => $this->operationKey];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/github/app/sync';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
