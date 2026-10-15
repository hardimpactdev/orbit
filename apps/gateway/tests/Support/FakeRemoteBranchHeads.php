<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Models\Project;

/** Branch heads of a fake remote, by Project slug and branch. A missing head is an unreadable branch. */
final class FakeRemoteBranchHeads implements RemoteBranchHeads
{
    /** @var array<string, string> */
    public array $heads = [];

    /** @var list<string> Each read as `slug:branch`. */
    public array $reads = [];

    public function set(Project $project, string $branch, string $commit): void
    {
        $this->heads["{$project->slug}:{$branch}"] = $commit;
    }

    public function head(Project $project, string $branch): string
    {
        $this->reads[] = "{$project->slug}:{$branch}";

        return $this->heads["{$project->slug}:{$branch}"]
            ?? throw new ResourceOperationException('source.head_unavailable', 'The fake remote has no such branch.', 502);
    }
}
