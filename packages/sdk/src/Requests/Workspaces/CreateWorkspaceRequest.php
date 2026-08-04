<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Workspaces\CreateWorkspaceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateWorkspaceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $name,
        public readonly string $instance,
        public readonly ?string $base = null,
        public readonly ?string $phpVersion = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/workspaces';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'name' => $this->name,
                'instance' => $this->instance,
                'base' => $this->base,
                'php_version' => $this->phpVersion,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    public function createDtoFromResponse(Response $response): CreateWorkspaceResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $workspace = $this->stringKeyedArray($data['workspace'] ?? []);

        return new CreateWorkspaceResponse(
            name: is_string($workspace['name'] ?? null) ? $workspace['name'] : $this->name,
            project: is_string($workspace['project'] ?? null) ? $workspace['project'] : '',
            instance: is_string($workspace['instance'] ?? null) ? $workspace['instance'] : '',
            node: is_string($workspace['node'] ?? null) ? $workspace['node'] : null,
            path: is_string($workspace['path'] ?? null) ? $workspace['path'] : null,
            url: is_string($workspace['url'] ?? null) ? $workspace['url'] : null,
            phpVersion: is_string($workspace['php_version'] ?? null) ? $workspace['php_version'] : null,
            phpInherited: is_bool($workspace['php_inherited'] ?? null) ? $workspace['php_inherited'] : false,
            adopted: is_bool($workspace['adopted'] ?? null) ? $workspace['adopted'] : false,
            lifecycleStatus: is_string($workspace['lifecycle_status'] ?? null)
                ? $workspace['lifecycle_status']
                : 'setup-pending',
            base: is_string($meta['base'] ?? null) ? $meta['base'] : $this->base ?? 'main',
            action: is_string($data['result']['action'] ?? null) ? $data['result']['action'] : 'created',
            httpProbe: $this->stringKeyedArray($meta['http_probe'] ?? []),
            warnings: $this->listOfStringArrays($meta['warnings'] ?? []),
        );
    }
}
