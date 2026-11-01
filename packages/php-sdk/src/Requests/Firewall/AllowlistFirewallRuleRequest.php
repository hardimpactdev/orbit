<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Firewall;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AllowlistFirewallRuleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /** @param list<string> $sources */
    public function __construct(
        private readonly int $nodeId,
        private readonly string $name,
        private readonly array $sources,
        private readonly ?string $protocol,
        private readonly string $port,
        private readonly ?string $idempotencyKey = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/firewall-rules/allowlist";
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    /** @return array{name: string, sources: list<string>, port: string, protocol?: string} */
    protected function defaultBody(): array
    {
        $body = ['name' => $this->name, 'sources' => $this->sources, 'port' => $this->port];
        if ($this->protocol !== null) {
            $body['protocol'] = $this->protocol;
        }

        return $body;
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
