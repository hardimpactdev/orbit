<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AiRouter;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AiRouter\AiRouterModelResponse;
use Orbit\Sdk\Responses\AiRouter\AiRouterModelsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ListAiRouterModelsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/ai-router/models';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): AiRouterModelsResponse
    {
        $requestId = $this->successRequestId($response);
        $rows = $this->unwrapDataList($response, rejectMalformed: true);

        if (count($rows) > 10_000) {
            throw new GatewayApiException('Gateway response contains invalid ai-router models.', requestId: $requestId);
        }

        return new AiRouterModelsResponse(
            array_map(
                static fn (array $row): AiRouterModelResponse => AiRouterModelResponse::fromGatewayData($row, $requestId),
                $rows,
            ),
            $requestId,
        );
    }
}
