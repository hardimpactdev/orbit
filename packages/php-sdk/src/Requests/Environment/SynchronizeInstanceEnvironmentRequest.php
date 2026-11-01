<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Environment;

use Orbit\Sdk\Requests\Deployments\StartInstanceOperationRequest;
use Saloon\Enums\Method;
use Saloon\Repositories\Body\JsonBodyRepository;

/** Starts an `instance.sync_environment` operation. The Gateway answers 202 with the queued operation at once. */
final class SynchronizeInstanceEnvironmentRequest extends StartInstanceOperationRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int|string $instance,
    ) {
        parent::__construct();
    }

    /** Synchronization takes an empty JSON object body. */
    #[\Override]
    public function body(): JsonBodyRepository
    {
        return parent::body()->setJsonFlags(JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/'.rawurlencode((string) $this->instance).'/environment/sync';
    }

    /** @return array<never, never> */
    protected function defaultBody(): array
    {
        return [];
    }
}
