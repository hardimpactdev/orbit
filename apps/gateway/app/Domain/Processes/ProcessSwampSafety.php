<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

/** An executor must not depend on itself to recover its local runtime. */
final readonly class ProcessSwampSafety
{
    public function assertAllowed(Node $node): void
    {
        if ($this->requiresSynchronous($node)) {
            throw new ResourceOperationException('process.executor_dependency', 'Processes on Swamp executor Nodes require synchronous control.', 409);
        }
    }

    public function requiresSynchronous(Node $node): bool
    {
        $services = config('dependency_detection.services_node_id');
        $workers = config('operation_executor.worker_node_ids', []);
        $ids = is_array($workers) ? [...$workers, $services] : [$services];

        return array_any($ids, static fn (mixed $id): bool => (is_int($id) || is_string($id)) && (string) $id === (string) $node->id);
    }
}
