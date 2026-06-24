<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Apps;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Apps\AppShowResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowAppRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $app,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/apps/'.rawurlencode($this->app);
    }

    public function createDtoFromResponse(Response $response): AppShowResponse
    {
        $data = $this->unwrapData($response);
        $app = $data['app'] ?? [];
        $details = $data['details'] ?? [];

        return new AppShowResponse(
            app: $this->stringKeyedArray($app),
            details: $this->stringKeyedArray($details),
        );
    }
}
