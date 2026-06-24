<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Tools;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Tools\ToolShowResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveToolRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $tool,
        public readonly ?string $app = null,
        public readonly ?string $node = null,
        public readonly string $destructiveConsentSource = 'force',
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/tools/'.rawurlencode($this->tool);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'app' => $this->app,
                'node' => $this->node,
                'destructive_consent' => true,
                'destructive_consent_source' => $this->destructiveConsentSource,
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
