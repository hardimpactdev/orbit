<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Deploy;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Deploy\DeployResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RunDeployRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $app,
        public readonly bool $detach = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/deploy/run';
    }

    protected function defaultBody(): array
    {
        return [
            'app' => $this->app,
            'detach' => $this->detach,
        ];
    }

    public function createDtoFromResponse(Response $response): DeployResponse
    {
        return DeployResponseFactory::fromResponse($response);
    }
}
