<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeObservers;

use App\Data\Doctor\ProbeSnapshot;
use App\Models\Node;
use App\Models\Process;

interface ProcessRuntimeObserver
{
    public function observe(Process $process, Node $node): ProbeSnapshot;
}
