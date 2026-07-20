<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Tools;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Tools\ToolListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListToolsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $instance = null,
        public readonly ?string $node = null,
        public readonly bool $self = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/tools';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter(
            [
                'instance' => $this->instance,
                'node' => $this->node,
                'self' => $this->self ? true : null,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): ToolListResponse
    {
        $data = $this->unwrapData($response);
        $tools = $data['tools'] ?? [];

        return new ToolListResponse(
            tools: $this->listOfStringKeyedArrays($tools),
        );
    }
}
