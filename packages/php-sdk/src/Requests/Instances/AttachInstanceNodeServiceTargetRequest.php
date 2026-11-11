<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstanceNodeServiceTargetResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AttachInstanceNodeServiceTargetRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $instanceId,
        private readonly int $processId,
        private readonly string $protocol,
        private readonly ?int $port,
        private readonly string $hostEnvKey,
        private readonly string $portEnvKey,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/'.$this->instanceId.'/node-service-targets';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstanceNodeServiceTargetResponse
    {
        return InstanceNodeServiceTargetResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, int|string> */
    protected function defaultBody(): array
    {
        return ['process_id' => $this->processId, 'protocol' => $this->protocol,
            ...($this->port === null ? [] : ['port' => $this->port]),
            'host_env_key' => $this->hostEnvKey, 'port_env_key' => $this->portEnvKey];
    }
}
