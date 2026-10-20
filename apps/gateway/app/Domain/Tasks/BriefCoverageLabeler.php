<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Tasks\JevBriefCoverageLabeler;
use App\Models\Task;
use Illuminate\Container\Attributes\Bind;

#[Bind(JevBriefCoverageLabeler::class)]
interface BriefCoverageLabeler
{
    public function label(Task $group, TaskPullRequestHealth $merge): void;
}
