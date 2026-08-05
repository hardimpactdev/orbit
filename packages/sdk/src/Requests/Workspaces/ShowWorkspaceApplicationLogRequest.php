<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\ApplicationLogs\ApplicationLogResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowWorkspaceApplicationLogRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $workspace,
        public readonly string $instance,
        public readonly int $lines = 100,
        public readonly ?string $node = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/workspaces/'.rawurlencode($this->workspace).'/log';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter(
            [
                'instance' => $this->instance,
                'lines' => $this->lines,
                'node' => $this->node,
            ],
            fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    public function createDtoFromResponse(Response $response): ApplicationLogResponse
    {
        return ApplicationLogResponse::fromResponse($response);
    }
}
