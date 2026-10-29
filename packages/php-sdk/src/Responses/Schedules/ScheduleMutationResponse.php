<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Schedules;

use Orbit\Sdk\Responses\Operations\OperationResponse;

/** Executor-Node mutations finish synchronously; other Nodes return an Operation. */
final readonly class ScheduleMutationResponse
{
    public function __construct(public ScheduleResponse|OperationResponse $result) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[\SensitiveParameter] array $data, string $requestId, int $status): self
    {
        return new self($status === 202
            ? OperationResponse::fromGatewayData($data, $requestId)
            : ScheduleResponse::fromGatewayData($data, $requestId));
    }
}
