<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Saloon\Enums\Method;
use Saloon\Repositories\Body\JsonBodyRepository;

/** Starts an `instance.deploy` operation. The Gateway answers 202 with the queued operation at once. */
final class DeployInstanceRequest extends StartInstanceOperationRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(private readonly int $instanceId, ?string $idempotencyKey = null, private readonly ?string $commit = null)
    {
        parent::__construct($idempotencyKey);
    }

    /** An unpinned manual deploy keeps its empty JSON object body. */
    #[\Override]
    public function body(): JsonBodyRepository
    {
        return parent::body()->setJsonFlags(JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/deploy";
    }

    /** @return array{commit?: string} */
    protected function defaultBody(): array
    {
        return $this->commit === null ? [] : ['commit' => $this->commit];
    }
}
