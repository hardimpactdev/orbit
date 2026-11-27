<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tools\AptRepositoriesResponse;
use Orbit\Sdk\Responses\Tools\AptRepositoryResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListAptRepositoriesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $nodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/apt-repositories';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AptRepositoriesResponse
    {
        $requestId = $this->successRequestId($response);

        return new AptRepositoriesResponse(array_map(
            static fn (array $item): AptRepositoryResponse => AptRepositoryResponse::fromGatewayData($item, $requestId),
            $this->unwrapDataList($response, rejectMalformed: true),
        ), $requestId);
    }

    protected function defaultQuery(): array
    {
        return ['node_id' => $this->nodeId];
    }
}
