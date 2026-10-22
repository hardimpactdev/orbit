<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstancePathProxyResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class SetInstancePathProxyRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly int|string $instance,
        private readonly int $processId,
        private readonly int $port,
        private readonly ?string $prefix = null,
    ) {}

    /** @return array<string, int|string> */
    protected function defaultBody(): array
    {
        return ['process_id' => $this->processId, 'port' => $this->port,
            ...($this->prefix === null ? [] : ['prefix' => $this->prefix])];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/'.rawurlencode((string) $this->instance).'/path-proxy';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstancePathProxyResponse
    {
        return InstancePathProxyResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
