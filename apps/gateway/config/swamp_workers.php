<?php

declare(strict_types=1);

return [
    // Operator-owned rollout. No Node is enrolled by installing this release.
    'nodes' => json_decode((string) env('ORBIT_SWAMP_WORKER_NODES', '[]'), true),
];
