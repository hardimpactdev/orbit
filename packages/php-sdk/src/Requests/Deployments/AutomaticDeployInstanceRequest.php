<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Saloon\Enums\Method;

/** Starts a trusted automatic pinned deploy; the Gateway requires fleet authority. */
final class AutomaticDeployInstanceRequest extends StartInstanceOperationRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(private readonly int $instanceId, private readonly string $commit, string $idempotencyKey)
    {
        parent::__construct($idempotencyKey);
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/deploy/automatic";
    }

    /** @return array{commit: string} */
    protected function defaultBody(): array
    {
        return ['commit' => $this->commit];
    }
}
