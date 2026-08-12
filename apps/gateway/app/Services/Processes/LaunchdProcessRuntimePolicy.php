<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\Node;
use App\Models\Process;

final readonly class LaunchdProcessRuntimePolicy
{
    public function shouldBeLoaded(Process $process, Node $node): bool
    {
        $process->loadMissing('owner');

        return $process->owner instanceof Node || ! $node->hasActiveRole('app-dev');
    }
}
