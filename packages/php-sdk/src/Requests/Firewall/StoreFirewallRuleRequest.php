<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Firewall;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

abstract class StoreFirewallRuleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $nodeId,
        private readonly string $name,
        private readonly ?string $source,
        private readonly ?string $protocol,
        private readonly string $port,
        private readonly ?string $idempotencyKey = null,
    ) {}

    abstract protected function action(): string;

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/firewall-rules/{$this->action()}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        $requestId = $this->successRequestId($response);

        return OperationResponse::fromGatewayData($this->unwrapData($response), $requestId);
    }

    /** @return array{name: string, source?: string, protocol?: string, port: string} */
    protected function defaultBody(): array
    {
        $body = ['name' => $this->name];

        if ($this->source !== null) {
            $body['source'] = $this->source;
        }

        if ($this->protocol !== null) {
            $body['protocol'] = $this->protocol;
        }

        $body['port'] = $this->port;

        return $body;
    }
}
