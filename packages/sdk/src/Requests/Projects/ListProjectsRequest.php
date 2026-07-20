<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Projects;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Projects\ProjectListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListProjectsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $environment = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/projects';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter(
            [
                'environment' => $this->environment,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): ProjectListResponse
    {
        $data = $this->unwrapData($response);
        $projects = $data['projects'] ?? [];

        return new ProjectListResponse(
            projects: $this->listOfStringKeyedArrays($projects),
        );
    }
}
