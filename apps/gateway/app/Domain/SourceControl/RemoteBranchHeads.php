<?php

declare(strict_types=1);

namespace App\Domain\SourceControl;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;

/** Reads the commit a branch of a Project's repository points at, from the Gateway, without a checkout. */
interface RemoteBranchHeads
{
    /**
     * @return string the full SHA of the branch head
     *
     * @throws ResourceOperationException `source.head_unavailable` when the repository or the branch cannot be read
     */
    public function head(Project $project, string $branch): string;
}
