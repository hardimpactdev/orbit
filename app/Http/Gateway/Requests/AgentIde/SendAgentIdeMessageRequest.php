<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\AgentIde;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\AgentIde\AgentIdeMessageResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class SendAgentIdeMessageRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $message,
        public readonly string $app,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/agent-ide/message';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'message' => $this->message,
            'app' => $this->app,
        ];
    }

    public function createDtoFromResponse(Response $response): AgentIdeMessageResponse
    {
        return new AgentIdeMessageResponse(
            data: $this->unwrapData($response),
        );
    }
}
