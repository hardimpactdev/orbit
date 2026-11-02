<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Environment;

use Orbit\Sdk\Requests\Deployments\StartInstanceOperationRequest;
use Saloon\Enums\Method;
use Saloon\Repositories\Body\JsonBodyRepository;

/** Starts an `instance.env_import` operation. The Gateway answers 202 with the queued operation at once. */
final class ImportInstanceEnvironmentRequest extends StartInstanceOperationRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int|string $instance,
        private readonly ?bool $replace = null,
    ) {
        parent::__construct();
    }

    /** Import without replacement sends an empty JSON object. */
    #[\Override]
    public function body(): JsonBodyRepository
    {
        return parent::body()->setJsonFlags(JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/'.rawurlencode((string) $this->instance).'/environment/import';
    }

    /** @return array{replace?: bool} */
    protected function defaultBody(): array
    {
        return $this->replace === null ? [] : ['replace' => $this->replace];
    }
}
