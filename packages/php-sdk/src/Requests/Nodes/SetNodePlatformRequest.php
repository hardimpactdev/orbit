<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/** Sets the platform of an unmanaged Node: `linux`, `macos`, or `ipados`. */
final class SetNodePlatformRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $nodeId,
        private readonly string $platform,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/platform";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeResponse
    {
        return NodeResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{platform: string} */
    protected function defaultBody(): array
    {
        return ['platform' => $this->platform];
    }
}
