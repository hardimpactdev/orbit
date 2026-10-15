<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    /** Each null field keeps its value; send at least one. */
    public function __construct(
        private readonly int $instanceId,
        private readonly ?string $branch = null,
        private readonly ?bool $autoDeploy = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstanceResponse
    {
        return InstanceResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{branch?: string, auto_deploy?: bool} */
    protected function defaultBody(): array
    {
        $body = [];

        if ($this->branch !== null) {
            $body['branch'] = $this->branch;
        }

        if ($this->autoDeploy !== null) {
            $body['auto_deploy'] = $this->autoDeploy;
        }

        return $body;
    }
}
