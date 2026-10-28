<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleMutationResponse;
use Orbit\Sdk\Responses\Nodes\NodeRoleRemovalResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveNodeRoleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $nodeId,
        private readonly string $role,
        private readonly bool $force,
        private readonly bool $purgeData = false,
        private readonly bool $offline = false,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/roles/".rawurlencode($this->role);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeRoleRemovalResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return new NodeRoleRemovalResponse($response->status() === 202
            ? OperationResponse::fromGatewayData($data, $requestId)
            : NodeRoleMutationResponse::fromGatewayData($data, $requestId));
    }

    /** @return array{force: bool, purge_data: bool, offline: bool} */
    protected function defaultBody(): array
    {
        return [
            'force' => $this->force,
            'purge_data' => $this->purgeData,
            'offline' => $this->offline,
        ];
    }
}
