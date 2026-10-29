<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Processes;

use Orbit\Sdk\Responses\Operations\OperationResponse;

/** Executor-Node mutations finish synchronously; other Nodes return an Operation. */
final readonly class ProcessMutationResponse
{
    public function __construct(public ProcessResponse|OperationResponse $result) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[\SensitiveParameter] array $data, string $requestId, int $status): self
    {
        return new self($status === 202
            ? OperationResponse::fromGatewayData($data, $requestId)
            : ProcessResponse::fromGatewayData($data, $requestId));
    }
}
