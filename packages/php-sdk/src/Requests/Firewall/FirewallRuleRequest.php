<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Firewall;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/** Admits one firewall write on a Node. An omitted source, protocol, or label is left out of the body. */
abstract class FirewallRuleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $nodeId,
        private readonly string $port,
        private readonly ?string $source = null,
        private readonly ?string $protocol = null,
        private readonly ?string $label = null,
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
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{port: string, source?: string, protocol?: string, label?: string} */
    protected function defaultBody(): array
    {
        return array_filter(
            ['port' => $this->port, 'source' => $this->source, 'protocol' => $this->protocol, 'label' => $this->label],
            static fn (?string $value): bool => $value !== null,
        );
    }
}
