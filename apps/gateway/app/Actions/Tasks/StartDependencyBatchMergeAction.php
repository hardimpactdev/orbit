<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Tasks\DependencyBatchMergeGuard;
use App\Domain\Tasks\DependencyMergeProjection;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartDependencyBatchMergeAction
{
    public function __construct(private DependencyBatchMergeGuard $guard, private StartOperationAction $operations,
        private RequireTasksExtensionAction $extension, private OperationIdempotency $idempotency) {}

    public function execute(Task $group, int $publish, string $head, string $caller, string $request): Operation
    {
        $this->extension->execute();
        if (($existing = $this->idempotency->replay('tasks.dependency_merge')) !== null) {
            return $existing;
        }
        $intent = [...$this->guard->lineage($group, $publish, $head), 'node_id' => DependencyMergeProjection::WorkerNodeId];

        return $this->operations->execute('tasks.dependency_merge', 'task_group', $group->id,
            DependencyMergeProjection::WorkerNodeId, $intent, $caller, $request);
    }
}
