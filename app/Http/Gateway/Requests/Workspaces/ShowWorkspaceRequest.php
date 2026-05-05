<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Workspaces;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceShowResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowWorkspaceRequest extends GatewayRequest
{
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $name,
        public readonly ?string $app = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/workspaces/{$this->name}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): WorkspaceShowResponse
    {
        $data = $this->unwrapData($response);
        $workspace = $data['workspace'] ?? [];

        return new WorkspaceShowResponse(
            workspace: is_array($workspace) ? $workspace : [],
        );
    }
}
