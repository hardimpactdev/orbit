<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Tasks\AgentViewTaskWorkspaceDiffReader;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

/**
 * Reads the line diff of a task workspace against the Project default branch.
 *
 * A no-op implementation returns 0. A refused remote git command returns 0.
 */
#[Bind(AgentViewTaskWorkspaceDiffReader::class)]
interface TaskWorkspaceDiffReader
{
    /** @return array{additions: int, deletions: int}|null */
    public function lineChanges(Instance $instance, string $baseBranch): ?array;

    public function lineDiff(Instance $instance, string $baseBranch): int;

    public function hasCommitsSince(Instance $instance, string $since): bool;
}
