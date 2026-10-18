<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Models\Process;
use SensitiveParameter;

/** Refuses removal before the Process or its runtime changes ([Extensions](/reference/extensions#plug-in-points)). */
interface ProcessRemovalGuard
{
    public function assertRemovable(#[SensitiveParameter] Process $process): void;
}
