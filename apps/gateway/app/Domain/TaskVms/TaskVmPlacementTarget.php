<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** Where a placement puts a new task VM: on an Incus host Node, or at UpCloud, which has no host Node. */
final readonly class TaskVmPlacementTarget
{
    private function __construct(
        public TaskVmProviderKind $provider,
        public ?int $hostNodeId,
    ) {}

    public static function incusHost(int $hostNodeId): self
    {
        return new self(TaskVmProviderKind::Incus, $hostNodeId);
    }

    public static function upCloud(): self
    {
        return new self(TaskVmProviderKind::UpCloud, null);
    }
}
