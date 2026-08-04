<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Processes;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Processes\ProcessListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListProcessesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $node = null,
        public readonly ?string $instance = null,
        public readonly ?string $workspace = null,
        public readonly ?string $app = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/processes';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter(
            [
                'app' => $this->app,
                'node' => $this->node,
                'instance' => $this->instance,
                'workspace' => $this->workspace,
            ],
            fn (?string $value): bool => $value !== null && $value !== '',
        );
    }

    public function createDtoFromResponse(Response $response): ProcessListResponse
    {
        $data = $this->unwrapData($response);
        $context = $this->stringKeyedArray($data['context'] ?? []);
        $processes = $data['processes'] ?? [];

        return new ProcessListResponse(
            context: [
                'node' => is_string($context['node'] ?? null) ? $context['node'] : null,
                'instance' => is_string($context['instance'] ?? null) ? $context['instance'] : null,
                'workspace' => is_string($context['workspace'] ?? null) ? $context['workspace'] : null,
            ],
            processes: $this->listOfStringKeyedArrays($processes),
        );
    }
}
