<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Workspaces;

use Orbit\Sdk\Laravel\Responses\Workspaces\WorkspaceStepMutationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AddWorkspaceStepRequest extends WorkspaceStepRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $phase,
        public readonly string $command,
        public readonly int $timeout,
        public readonly ?string $instance = null,
        public readonly ?string $path = null,
        public readonly ?int $before = null,
        public readonly ?int $after = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/workspaces/steps/{$this->phase}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'instance' => $this->instance,
                'path' => $this->path,
                'command' => $this->command,
                'timeout' => $this->timeout,
                'before' => $this->before,
                'after' => $this->after,
            ],
            fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    public function createDtoFromResponse(Response $response): WorkspaceStepMutationResponse
    {
        $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        $success = is_array($body) ? $body['success'] ?? [] : [];
        $data = is_array($success) ? $success['data'] ?? [] : [];
        $meta = is_array($success) ? $success['meta'] ?? [] : [];
        $result = is_array($data) ? $data['result'] ?? [] : [];
        $step = is_array($data) ? $data['step'] ?? [] : [];

        return new WorkspaceStepMutationResponse(
            result: $this->workspaceStepResult($result),
            step: $this->workspaceStep($step),
            meta: $this->stringKeyedArray($meta),
        );
    }
}
