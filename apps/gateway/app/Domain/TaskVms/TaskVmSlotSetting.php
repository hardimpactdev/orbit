<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The ready slots a task VM host keeps: `count` stopped task VMs of `size`, enrolled for the Project `projectId`
 * (docs/cli/tasks/vm-slots.mmd). A host without this setting keeps no slots, and every provision clones.
 */
final readonly class TaskVmSlotSetting
{
    public function __construct(public int $count, public int $projectId, public TaskVmSize $size) {}

    /** @return array{count:int,project_id:int,size:string} */
    public function toArray(): array
    {
        return ['count' => $this->count, 'project_id' => $this->projectId, 'size' => $this->size->value];
    }
}
