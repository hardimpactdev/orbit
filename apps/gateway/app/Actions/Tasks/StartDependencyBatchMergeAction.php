<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\DependencyBatchMergeGuard;
use App\Domain\Tasks\TaskOperations;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartDependencyBatchMergeAction
{
    public function __construct(private DependencyBatchMergeGuard $guard, private TaskOperations $tasks) {}

    public function execute(Task $group, int $publish, string $head, string $caller, string $request): Operation
    {
        $intent = $this->guard->lineage($group, $publish, $head);
        $this->guard->github($intent);

        return $this->tasks->start($group, 'tasks.dependency_merge', $intent, function (Task $group) use ($intent, $publish, $head): void {
            if ($this->guard->lineage($group, $publish, $head) !== $intent) {
                throw new ResourceOperationException('dependency_merge.lineage_changed', 'The batch changed before admission.', 409);
            }
        }, $caller, $request);
    }
}
