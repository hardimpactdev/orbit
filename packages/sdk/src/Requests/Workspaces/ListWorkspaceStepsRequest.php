<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Workspaces;

use Orbit\Sdk\Laravel\Responses\Workspaces\WorkspaceStepListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListWorkspaceStepsRequest extends WorkspaceStepRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $phase,
        public readonly ?string $instance = null,
        public readonly ?string $path = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/workspaces/steps/{$this->phase}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter(
            [
                'instance' => $this->instance,
                'path' => $this->path,
            ],
            fn (mixed $value): bool => is_scalar($value) && $value !== '',
        );
    }

    public function createDtoFromResponse(Response $response): WorkspaceStepListResponse
    {
        $data = $this->unwrapData($response);
        $steps = $data['steps'] ?? [];

        return new WorkspaceStepListResponse(
            steps: $this->workspaceSteps($steps),
        );
    }
}
