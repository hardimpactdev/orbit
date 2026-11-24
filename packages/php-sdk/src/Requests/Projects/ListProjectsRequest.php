<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Projects;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\ProjectResponse;
use Orbit\Sdk\Responses\Projects\ProjectsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListProjectsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    /** @param  int|null  $nodeId  only the Project about this Node (docs/reference/projects.md#find-the-project-of-a-node) */
    public function __construct(
        private readonly ?int $nodeId = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects';
    }

    /** @return array<string, int> */
    protected function defaultQuery(): array
    {
        return $this->nodeId === null ? [] : ['node_id' => $this->nodeId];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProjectsResponse
    {
        $data = $this->unwrapDataList($response);
        $requestId = $this->successRequestId($response);
        $projects = [];

        foreach ($data as $project) {
            $projects[] = ProjectResponse::fromGatewayData($project, $requestId);
        }

        return new ProjectsResponse($projects, $requestId);
    }
}
