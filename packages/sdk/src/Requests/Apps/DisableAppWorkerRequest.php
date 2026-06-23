<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Apps;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Apps\AppWorkerResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class DisableAppWorkerRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $app,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/apps/'.rawurlencode($this->app).'/worker/disable';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [];
    }

    public function createDtoFromResponse(Response $response): AppWorkerResponse
    {
        return new AppWorkerResponse(data: $this->unwrapData($response));
    }
}
