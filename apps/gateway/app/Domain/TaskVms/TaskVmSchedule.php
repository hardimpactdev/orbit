<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** The task VM switches that the scheduler reads, from {@see TaskVmSettings::scheduled()}. */
final readonly class TaskVmSchedule
{
    public function __construct(
        /** `task_vms.enabled` is true. */
        public bool $enabled,
        /** The UpCloud token file is set, also while the overflow is off. */
        public bool $upcloudConfigured,
    ) {}
}
