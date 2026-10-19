<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

interface TaskPullRequestCheckOutput
{
    /** Redacted CI evidence for one selected check fixup, or an explicit unreadable-log notice. */
    public function read(Task $group, TaskPullRequestCheck $check): string;
}
