<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AddNodeRoleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $nodeId,
        private readonly string $role,
        private readonly bool $convergeExisting = false,
        private readonly ?int $postgresProcessId = null,
        private readonly ?int $clickhouseProcessId = null,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/roles";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return OperationResponse::fromGatewayData($data, $requestId);
    }

    /**
     * The two Process IDs belong to the `analytics` role only, so they are absent for every other role.
     *
     * @return array{role: string, converge_existing: bool, postgres_process_id?: int, clickhouse_process_id?: int}
     */
    protected function defaultBody(): array
    {
        return [
            'role' => $this->role,
            'converge_existing' => $this->convergeExisting,
            ...($this->postgresProcessId === null ? [] : ['postgres_process_id' => $this->postgresProcessId]),
            ...($this->clickhouseProcessId === null ? [] : ['clickhouse_process_id' => $this->clickhouseProcessId]),
        ];
    }
}
