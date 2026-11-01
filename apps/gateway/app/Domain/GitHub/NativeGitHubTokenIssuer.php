<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Models\Operation;

/** Owner-checked policy at the existing numeric-operation mint primitive. */
interface NativeGitHubTokenIssuer
{
    /** @return array<string,array{vault:string,key:string}> */
    public function issue(Operation $operation, int $callerNodeId, string $runId): array;
}
