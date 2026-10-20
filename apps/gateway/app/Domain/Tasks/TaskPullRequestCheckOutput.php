<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Tasks\GitHubTaskPullRequestCheckOutput;
use App\Models\Task;
use Illuminate\Container\Attributes\Bind;

#[Bind(GitHubTaskPullRequestCheckOutput::class)]
interface TaskPullRequestCheckOutput
{
    /** Redacted CI evidence for one selected check fixup, or an explicit unreadable-log notice. */
    public function read(Task $group, TaskPullRequestCheck $check): string;
}
