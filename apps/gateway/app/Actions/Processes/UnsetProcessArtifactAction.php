<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Models\Process;
use App\Models\ProcessArtifact;

/** Removes a Process's release artifact record and leaves the Node alone. */
final readonly class UnsetProcessArtifactAction
{
    public function execute(Process $process): bool
    {
        return ProcessArtifact::query()->where('process_id', $process->id)->delete() > 0;
    }
}
