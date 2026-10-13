<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The state of one topology preparation unit, as `resources/task-vms/topology.sh status` reports it, with the step it
 * ended at and the end of its log.
 */
final readonly class TaskVmTopologyRun
{
    public function __construct(
        public TaskVmTopologyRunState $state,
        public ?string $step = null,
        public string $log = '',
    ) {}

    /**
     * The run from `status` JSON. An unknown state reads as lost.
     *
     * @param  array<string, mixed>  $status
     */
    public static function fromStatus(array $status): self
    {
        return new self(
            TaskVmTopologyRunState::tryFrom(is_string($status['state'] ?? null) ? $status['state'] : '') ?? TaskVmTopologyRunState::Lost,
            is_string($status['step'] ?? null) ? $status['step'] : null,
            is_string($status['log'] ?? null) ? trim($status['log']) : '',
        );
    }
}
