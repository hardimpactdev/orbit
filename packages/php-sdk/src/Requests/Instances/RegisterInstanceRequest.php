<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstanceRegistrationResult;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RegisterInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $sourcePath,
        private readonly ?int $projectId = null,
        private readonly ?string $instanceName = null,
        private readonly ?string $root = null,
        private readonly ?string $domain = null,
        private readonly bool $setup = false,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/register';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstanceRegistrationResult
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return $response->status() === 201
            ? new InstanceRegistrationResult(InstanceResponse::fromGatewayData($data, $requestId), null)
            : new InstanceRegistrationResult(null, OperationResponse::fromGatewayData($data, $requestId));
    }

    /** @return array<string, bool|int|string> */
    protected function defaultBody(): array
    {
        $body = ['source_path' => $this->sourcePath];

        if ($this->setup) {
            $body['setup'] = true;
        }

        foreach ([
            'project_id' => $this->projectId,
            'instance_name' => $this->instanceName,
            'root' => $this->root,
            'domain' => $this->domain,
        ] as $key => $value) {
            if ($value === null) {
                continue;
            }

            $body[$key] = $value;
        }

        return $body;
    }
}
