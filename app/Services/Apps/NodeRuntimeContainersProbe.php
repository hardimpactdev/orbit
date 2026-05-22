<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Doctor\ProbeSnapshot;

final readonly class NodeRuntimeContainersProbe
{
    public function __construct(
        public NodeRuntimeContainersProbeStatus $status,
        public ProbeSnapshot $containers,
        public string $error = '',
    ) {}
}
