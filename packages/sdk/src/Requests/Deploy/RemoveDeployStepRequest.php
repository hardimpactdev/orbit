<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Deploy;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Deploy\DeployResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveDeployStepRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $instance,
        public readonly string $step,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/deploy/steps/{$this->step}";
    }

    protected function defaultQuery(): array
    {
        return [
            'instance' => $this->instance,
            'destructive_consent' => true,
        ];
    }

    public function createDtoFromResponse(Response $response): DeployResponse
    {
        return DeployResponseFactory::fromResponse($response);
    }
}
