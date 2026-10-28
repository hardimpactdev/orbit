<?php

declare(strict_types=1);

return [
    'worker_node_ids' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('ORBIT_OPERATION_EXECUTOR_WORKER_NODE_IDS', ''))), static fn (string $id): bool => $id !== '')),
];
