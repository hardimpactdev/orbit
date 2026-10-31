<?php

declare(strict_types=1);

return ['metrics.reconcile' => ['executor' => 'swamp', 'result_fields' => [
    'ready', 'error_code', 'runtime_committed',
    ...array_map(static fn (string $name): array => ['name' => $name, 'type' => 'list', 'items' => 'integer', 'max' => 256],
        ['missing_worker_ids', 'verified_node_ids', 'restored_node_ids', 'unknown_node_ids']),
]]];
