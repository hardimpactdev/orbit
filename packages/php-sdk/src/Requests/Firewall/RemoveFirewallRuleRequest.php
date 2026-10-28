<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Firewall;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveFirewallRuleRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $nodeId,
        private readonly string $name,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/firewall-rules/".rawurlencode($this->name);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        $requestId = $this->successRequestId($response);

        return OperationResponse::fromGatewayData($this->unwrapData($response), $requestId);
    }
}
