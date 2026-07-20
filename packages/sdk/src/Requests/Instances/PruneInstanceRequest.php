<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Instances;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Instances\PruneInstanceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class PruneInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $instance,
        public readonly bool $dryRun = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/instances/prune';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'instance' => $this->instance,
            'dry_run' => $this->dryRun,
        ];
    }

    public function createDtoFromResponse(Response $response): PruneInstanceResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);

        return new PruneInstanceResponse(
            instance: is_string($data['instance'] ?? null) ? $data['instance'] : '',
            staleWorkspaces: $this->listOfStringKeyedArrays($data['stale_workspaces'] ?? []),
            warnings: $this->listOfStringArrays($meta['warnings'] ?? []),
            dryRun: (bool) ($data['dry_run'] ?? false),
        );
    }
}
