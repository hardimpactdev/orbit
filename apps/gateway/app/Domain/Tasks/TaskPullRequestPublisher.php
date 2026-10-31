<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/** Handoff-branch cleanup for the remaining terminal workspace adapter. */
interface TaskPullRequestPublisher
{
    public function deleteHandoffBranch(Task $group): void;
}
