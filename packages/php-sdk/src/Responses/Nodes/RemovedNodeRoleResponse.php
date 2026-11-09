<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use InvalidArgumentException;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final readonly class RemovedNodeRoleResponse
{
    public function __construct(public ?OperationResponse $operation, public ?int $nodeId, public ?string $skipped, public string $requestId) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $requestId): self
    {
        if (isset($data['id'])) {
            return new self(OperationResponse::fromGatewayData($data, $requestId), null, null, $requestId);
        }
        if (! is_int($data['node_id'] ?? null) || $data['node_id'] < 1 || ($data['role'] ?? null) !== 'ingress' || ($data['skipped'] ?? null) !== 'node_role.absent') {
            throw new InvalidArgumentException('Gateway response contains an invalid role removal.');
        }

        return new self(null, $data['node_id'], $data['skipped'], $requestId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->operation?->toArray() ?? ['node_id' => $this->nodeId, 'role' => 'ingress', 'skipped' => $this->skipped, 'request_id' => $this->requestId];
    }
}
