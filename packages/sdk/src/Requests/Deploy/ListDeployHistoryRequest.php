<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Deploy;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Deploy\DeployResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListDeployHistoryRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $app,
        public readonly int $limit = 50,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/deploy/history';
    }

    protected function defaultQuery(): array
    {
        return [
            'app' => $this->app,
            'limit' => $this->limit,
        ];
    }

    public function createDtoFromResponse(Response $response): DeployResponse
    {
        return DeployResponseFactory::fromResponse($response);
    }
}
