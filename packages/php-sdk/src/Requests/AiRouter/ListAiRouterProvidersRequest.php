<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AiRouter;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AiRouter\AiRouterProviderResponse;
use Orbit\Sdk\Responses\AiRouter\AiRouterProvidersResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ListAiRouterProvidersRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/ai-router/providers';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): AiRouterProvidersResponse
    {
        $requestId = $this->successRequestId($response);
        $rows = $this->unwrapDataList($response, rejectMalformed: true);

        if (count($rows) > 1_000) {
            throw new GatewayApiException('Gateway response contains invalid ai-router providers.', requestId: $requestId);
        }

        return new AiRouterProvidersResponse(
            array_map(
                static fn (array $row): AiRouterProviderResponse => AiRouterProviderResponse::fromGatewayData($row, $requestId),
                $rows,
            ),
            $requestId,
        );
    }
}
