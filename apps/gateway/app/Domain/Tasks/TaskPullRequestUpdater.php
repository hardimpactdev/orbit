<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use App\Models\Task;
use Illuminate\Container\Attributes\Bind;

#[Bind(HttpTaskPullRequestWatcher::class)]
interface TaskPullRequestUpdater
{
    public function updateBranch(Task $group, string $headSha): TaskBranchUpdate;
}
