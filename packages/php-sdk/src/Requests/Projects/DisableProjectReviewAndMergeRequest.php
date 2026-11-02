<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Projects;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\ProjectResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DisableProjectReviewAndMergeRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $projectId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/review-and-merge/disable";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProjectResponse
    {
        return ProjectResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
