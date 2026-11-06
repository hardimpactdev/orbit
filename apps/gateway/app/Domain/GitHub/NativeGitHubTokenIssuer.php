<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Models\Operation;

/** Owner-checked policy at the existing numeric-operation mint primitive. */
interface NativeGitHubTokenIssuer
{
    /**
     * `$purpose` names the step that asks, for an Operation whose steps need tokens of different kinds at different times. A kind with one token takes none.
     *
     * @return array<string,array{vault:string,key:string}>
     */
    public function issue(Operation $operation, int $callerNodeId, string $runId, ?string $purpose = null): array;
}
