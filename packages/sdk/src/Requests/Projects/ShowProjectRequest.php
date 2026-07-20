<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Projects;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Projects\ProjectShowResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowProjectRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $project,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/projects/'.rawurlencode($this->project);
    }

    public function createDtoFromResponse(Response $response): ProjectShowResponse
    {
        $data = $this->unwrapData($response);
        $project = $data['project'] ?? [];
        $details = $data['details'] ?? [];

        return new ProjectShowResponse(
            project: $this->stringKeyedArray($project),
            details: $this->stringKeyedArray($details),
        );
    }
}
