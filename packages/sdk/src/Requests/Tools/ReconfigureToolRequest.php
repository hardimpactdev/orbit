<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Tools;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Tools\ToolReconfigureResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class ReconfigureToolRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $tool,
        public readonly ?string $instance = null,
        public readonly ?string $node = null,
        public readonly array $toolConfig = [],
        public readonly ?string $password = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/tools/'.rawurlencode($this->tool).'/reconfigure';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'instance' => $this->instance,
                'node' => $this->node,
                'config' => $this->toolConfig === [] ? null : $this->toolConfig,
                'password' => $this->password,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): ToolReconfigureResponse
    {
        $data = $this->unwrapData($response);
        $tool = $data['tool'] ?? [];

        return new ToolReconfigureResponse(
            tool: $this->stringKeyedArray($tool),
        );
    }
}
