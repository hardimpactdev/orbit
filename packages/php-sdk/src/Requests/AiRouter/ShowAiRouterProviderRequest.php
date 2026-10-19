<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AiRouter;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AiRouter\AiRouterProviderResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ShowAiRouterProviderRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $provider,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/ai-router/providers/'.rawurlencode($this->provider);
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): AiRouterProviderResponse
    {
        return AiRouterProviderResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
