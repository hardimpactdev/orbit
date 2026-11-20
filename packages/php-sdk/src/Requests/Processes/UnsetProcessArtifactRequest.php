<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Processes\ProcessArtifactRemovedResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

/** Removes the release artifact record of a Process and leaves the Node alone. */
final class UnsetProcessArtifactRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(private readonly int $processId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/processes/{$this->processId}/artifact";
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ProcessArtifactRemovedResponse
    {
        return ProcessArtifactRemovedResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
