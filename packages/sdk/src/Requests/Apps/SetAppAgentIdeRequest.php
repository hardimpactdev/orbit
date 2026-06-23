<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Apps;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Apps\AppAgentIdeResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class SetAppAgentIdeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $app,
        public readonly string $agentIde,
        public readonly bool $force = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/apps/'.rawurlencode($this->app).'/agent-ide';
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

    public function createDtoFromResponse(Response $response): AppAgentIdeResponse
    {
        return new AppAgentIdeResponse(
            data: $this->unwrapData($response),
        );
    }
}
