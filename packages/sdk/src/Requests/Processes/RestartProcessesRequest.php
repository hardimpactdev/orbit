<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Processes;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Processes\ProcessRestartResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RestartProcessesRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly ?string $instance,
        public readonly ?string $workspace,
        public readonly ?string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/processes/restart';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'instance' => $this->instance,
                'workspace' => $this->workspace,
                'name' => $this->name,
            ],
            fn (mixed $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): ProcessRestartResponse
    {
        return new ProcessRestartResponse(
            data: $this->unwrapData($response),
        );
    }
}
