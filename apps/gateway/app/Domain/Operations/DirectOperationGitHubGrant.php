<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\GitHub\GitHubTokenGrant;
use App\Models\Operation;

/** The existing mint primitive may derive a fixed grant from admitted domain ownership. */
interface DirectOperationGitHubGrant
{
    public function githubTokenGrant(Operation $operation): GitHubTokenGrant;
}
