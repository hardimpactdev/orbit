<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Doctor\ProbeSnapshot;

final readonly class NodeRuntimeConfigsProbe
{
    public function __construct(
        public NodeRuntimeConfigsProbeStatus $status,
        public ProbeSnapshot $configs,
        public string $error = '',
    ) {}
}
