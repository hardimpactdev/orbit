<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Deployments\AutomaticDeployReceiptResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class ResolveAutomaticDeployAdmissionRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $instanceId,
        private readonly string $requestKey,
        private readonly string $commit,
        private readonly ?int $callerNodeId = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/deploy/automatic/resolve";
    }

    /** @return array{request_key: string, commit: string, caller_node_id?: int} */
    protected function defaultBody(): array
    {
        return [
            'request_key' => $this->requestKey, 'commit' => $this->commit,
            ...($this->callerNodeId === null ? [] : ['caller_node_id' => $this->callerNodeId]),
        ];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AutomaticDeployReceiptResponse
    {
        return AutomaticDeployReceiptResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
