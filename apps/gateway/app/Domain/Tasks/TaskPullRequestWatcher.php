<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use App\Models\Task;
use Illuminate\Container\Attributes\Bind;

#[Bind(HttpTaskPullRequestWatcher::class)]
interface TaskPullRequestWatcher
{
    /** @return 'merged'|'closed'|'open'|null */
    public function status(Task $group): ?string;

    /** The pull request's state and, while it is open, its conflicts and failed checks. Null when unknown. */
    public function health(Task $group): ?TaskPullRequestHealth;
}
