<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Instances;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Instances\InstanceListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListInstancesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $app = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/instances';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter(
            ['app' => $this->app],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): InstanceListResponse
    {
        $data = $this->unwrapData($response);

        return new InstanceListResponse(
            instances: $this->listOfStringKeyedArrays($data['instances'] ?? []),
        );
    }
}
