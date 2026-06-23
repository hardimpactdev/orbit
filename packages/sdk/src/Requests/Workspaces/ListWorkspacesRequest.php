<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Workspaces\WorkspaceListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListWorkspacesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $app = null,
        public readonly ?string $node = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/workspaces';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'node' => $this->node,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): WorkspaceListResponse
    {
        $data = $this->unwrapData($response);
        $workspaces = $data['workspaces'] ?? [];

        return new WorkspaceListResponse(
            workspaces: is_array($workspaces) ? array_values($workspaces) : [],
        );
    }
}
