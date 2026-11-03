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

    /** @param  bool  $force  replace a `.env` whose key names the Node agent has not reported, or that holds keys stored configuration lacks */
    public function __construct(
        private readonly int|string $instance,
        private readonly bool $force = false,
    ) {
        parent::__construct();
    }

    /** Synchronization takes a JSON object body: empty, or `force`. */
    #[\Override]
    public function body(): JsonBodyRepository
    {
        return parent::body()->setJsonFlags(JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/'.rawurlencode((string) $this->instance).'/environment/sync';
    }

    /** @return array{force?: true} */
    protected function defaultBody(): array
    {
        return $this->force ? ['force' => true] : [];
    }
}
