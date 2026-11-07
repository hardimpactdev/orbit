<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\GitHub\GitHubComparisonStatus;

/** How one commit stands to another and which files differ between them, from `compare/{from}...{to}`. */
final readonly class ProofCommitRange
{
    public function __construct(public GitHubComparisonStatus $status, public ProofChangedFiles $changes) {}
}
