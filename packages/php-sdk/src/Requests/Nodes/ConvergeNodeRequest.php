<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class ConvergeNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /** @param list<string>|null $roles */
    public function __construct(
        private readonly int $nodeId,
        private readonly bool $base = false,
        private readonly ?string $idempotencyKey = null,
        private readonly ?array $roles = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/converge";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    /** @return array{base: bool, roles?: list<string>} */
    protected function defaultBody(): array
    {
        return ['base' => $this->base, ...($this->roles === null ? [] : ['roles' => $this->roles])];
    }
}
