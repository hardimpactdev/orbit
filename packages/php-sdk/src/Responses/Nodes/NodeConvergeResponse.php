<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use Orbit\Sdk\Responses\Fleet\NodeFootprintResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** Executor Nodes finish synchronously; other standalone Nodes return an Operation. */
final readonly class NodeConvergeResponse
{
    public function __construct(public NodeFootprintResponse|OperationResponse $result) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[\SensitiveParameter] array $data, string $requestId, int $status): self
    {
        return new self($status === 202
            ? OperationResponse::fromGatewayData($data, $requestId)
            : NodeFootprintResponse::fromGatewayData($data, $requestId));
    }
}
