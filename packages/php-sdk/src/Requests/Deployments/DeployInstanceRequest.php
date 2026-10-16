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

    public function __construct(private readonly int $instanceId) {}

    /** The Gateway requires an empty JSON object, so the empty body encodes as `{}`, not `[]`. */
    #[\Override]
    public function body(): JsonBodyRepository
    {
        return parent::body()->setJsonFlags(JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/deploy";
    }

    /** @return array<never, never> */
    protected function defaultBody(): array
    {
        return [];
    }
}
