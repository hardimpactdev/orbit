<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class SetNodeWireGuardKeyRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $nodeId,
        private readonly string $publicKey,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/wireguard-key";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeResponse
    {
        return NodeResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{wireguard_public_key: string} */
    protected function defaultBody(): array
    {
        return ['wireguard_public_key' => $this->publicKey];
    }
}
