<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

/** @phpstan-import-type NodeServiceTargetRecord from InstanceNodeServiceTargetRecordResponse */
final readonly class InstanceNodeServiceTargetsResponse
{
    /** @param list<InstanceNodeServiceTargetRecordResponse> $targets */
    public function __construct(public array $targets, public string $requestId) {}

    /** @return array{targets: list<NodeServiceTargetRecord>, request_id: string} */
    public function toArray(): array
    {
        return ['targets' => array_map(static fn (InstanceNodeServiceTargetRecordResponse $target): array => $target->toArray(), $this->targets),
            'request_id' => $this->requestId];
    }
}
