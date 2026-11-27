<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tools\AptRepositoryResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowAptRepositoryRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $repositoryId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/apt-repositories/{$this->repositoryId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AptRepositoryResponse
    {
        return AptRepositoryResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
