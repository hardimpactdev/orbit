<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstanceNodeServiceTargetRecordResponse;
use Orbit\Sdk\Responses\Instances\InstanceNodeServiceTargetsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListInstanceNodeServiceTargetsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(private readonly int $instanceId) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/'.$this->instanceId.'/node-service-targets';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstanceNodeServiceTargetsResponse
    {
        return new InstanceNodeServiceTargetsResponse(array_map(InstanceNodeServiceTargetRecordResponse::fromGatewayData(...),
            $this->unwrapDataList($response)), $this->successRequestId($response));
    }
}
