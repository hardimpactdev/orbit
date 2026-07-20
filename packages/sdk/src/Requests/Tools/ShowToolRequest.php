<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Tools;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Tools\ToolShowResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowToolRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $tool,
        public readonly ?string $instance = null,
        public readonly ?string $node = null,
        public readonly bool $live = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/tools/'.rawurlencode($this->tool);
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
                'live' => $this->live ? '1' : null,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): ToolShowResponse
    {
        $data = $this->unwrapData($response);
        $tool = $data['tool'] ?? [];

        return new ToolShowResponse(
            tool: $this->stringKeyedArray($tool),
        );
    }
}
