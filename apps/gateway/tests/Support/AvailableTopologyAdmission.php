<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\TaskTopologyAdmission;
use App\Models\Task;

/** A lane that admits every declared topology, so a group with one gets a large task VM. */
final readonly class AvailableTopologyAdmission implements TaskTopologyAdmission
{
    public function available(): bool
    {
        return true;
    }

    public function prepare(Task $group, Task $task): void {}
}
