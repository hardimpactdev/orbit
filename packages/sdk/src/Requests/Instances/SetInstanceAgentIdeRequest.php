<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Instances;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Instances\InstanceAgentIdeResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class SetInstanceAgentIdeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $instance,
        public readonly string $agentIde,
        public readonly bool $force = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/instances/'.rawurlencode($this->instance).'/agent-ide';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'agent_ide' => $this->agentIde,
            'force' => $this->force,
        ];
    }

    public function createDtoFromResponse(Response $response): InstanceAgentIdeResponse
    {
        return new InstanceAgentIdeResponse(
            data: $this->unwrapData($response),
        );
    }
}
