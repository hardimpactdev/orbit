<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Projects;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Projects\ProjectCreateResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateProjectRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $name,
        public readonly string $node,
        public readonly ?string $repository,
        public readonly string $root,
        public readonly string $phpVersion,
        public readonly ?string $domain,
        public readonly ?string $templateRepository = null,
        public readonly ?string $newRepository = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/projects';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
            'node' => $this->node,
            'repository' => $this->repository,
            'template_repository' => $this->templateRepository,
            'new_repository' => $this->newRepository,
            'root' => $this->root,
            'php_version' => $this->phpVersion,
            'domain' => $this->domain,
        ];
    }

    public function createDtoFromResponse(Response $response): ProjectCreateResponse
    {
        $data = $this->unwrapData($response);
        $body = $response->json();
        $warnings = [];

        if (
            is_array($body)
            && isset($body['success']['meta']['warnings'])
            && is_array($body['success']['meta']['warnings'])
        ) {
            $warnings = $this->listOfStringKeyedArrays($body['success']['meta']['warnings']);
        }

        return new ProjectCreateResponse(
            data: $data,
            warnings: $warnings,
        );
    }
}
