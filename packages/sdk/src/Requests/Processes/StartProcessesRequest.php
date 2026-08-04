<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Processes;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Processes\ProcessStartResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class StartProcessesRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly ?string $instance = null,
        public readonly ?string $workspace = null,
        public readonly ?string $name = null,
        public readonly ?string $app = null,
        public readonly ?string $node = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/processes/start';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'app' => $this->app,
                'node' => $this->node,
                'instance' => $this->instance,
                'workspace' => $this->workspace,
                'name' => $this->name,
            ],
            fn (mixed $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): ProcessStartResponse
    {
        return new ProcessStartResponse(
            data: $this->unwrapData($response),
        );
    }
}
