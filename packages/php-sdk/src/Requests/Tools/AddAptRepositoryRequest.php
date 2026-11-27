<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/** Publishes an apt repository on a Node, or publishes it again under the same name. */
final class AddAptRepositoryRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /** @param array<string, mixed> $definition the definition without its name: uris, suites, components, architectures and signing_key */
    public function __construct(
        private readonly int $nodeId,
        private readonly string $name,
        #[\SensitiveParameter]
        private readonly array $definition,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/apt-repositories';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{node_id: int, name: string, definition: array<string, mixed>} */
    protected function defaultBody(): array
    {
        return ['node_id' => $this->nodeId, 'name' => $this->name, 'definition' => $this->definition];
    }
}
