<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Workspaces\SetupWorkspaceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class SetupWorkspaceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $instance = null,
        public readonly ?string $path = null,
        public readonly ?string $callerCwd = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/workspaces/setup';
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
                'path' => $this->path,
                'caller_cwd' => $this->callerCwd,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    public function createDtoFromResponse(Response $response): SetupWorkspaceResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $workspace = $this->stringKeyedArray($data['workspace'] ?? []);

        return new SetupWorkspaceResponse(
            name: is_string($workspace['name'] ?? null) ? $workspace['name'] : $this->name ?? '',
            app: is_string($workspace['app'] ?? null) ? $workspace['app'] : '',
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
            action: is_string($data['result']['action'] ?? null) ? $data['result']['action'] : 'set_up',
            httpProbe: $this->stringKeyedArray($meta['http_probe'] ?? []),
            warnings: $this->listOfStringKeyedArrays($meta['warnings'] ?? []),
        );
    }
}
