<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AiRouter;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AiRouter\AiRouterAccountResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class EnableAiRouterAccountRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $account,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/ai-router/accounts/'.rawurlencode($this->account).'/enable';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): AiRouterAccountResponse
    {
        return AiRouterAccountResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
