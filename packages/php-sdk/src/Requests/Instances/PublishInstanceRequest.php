<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\Requests\Deployments\StartInstanceOperationRequest;
use Saloon\Enums\Method;

/** Starts instance.publish; use the operation requests to follow its result. */
final class PublishInstanceRequest extends StartInstanceOperationRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    /** @param array{base: string, title: string, body: string}|null $pullRequest */
    public function __construct(
        private readonly int $instanceId,
        private readonly string $commit,
        private readonly string $branch,
        private readonly ?array $pullRequest = null,
        ?string $idempotencyKey = null,
    ) {
        parent::__construct($idempotencyKey);
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/publish";
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return ['commit' => $this->commit, 'branch' => $this->branch, ...($this->pullRequest === null ? [] : ['pull_request' => $this->pullRequest])];
    }
}
