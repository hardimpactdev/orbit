<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\DependencyBatchMergeGuard;
use App\Domain\Tasks\TaskExecutionLock;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        return app(TaskExecutionLock::class)->synchronized($group->id, function () use ($group, $publish, $head, $caller, $request): Operation {
            if (($existing = $this->idempotency->replay('tasks.dependency_merge')) !== null) {
                return $existing;
            }
            if (Operation::query()->where('target_type', 'task_group')->where('target_id', $group->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('operation.target_busy', 'Another Task Operation owns this group.', 409);
            }
            $intent = [...$this->guard->lineage($group, $publish, $head), 'node_id' => SwampNode::id()];
            // Only the vault key names are admitted. The first step of the run mints the tokens ({@see TaskGitHubTokens}).
            $secrets = [];
            foreach (['write_token', 'read_token'] as $name) {
                $secrets[$name] = ['vault' => 'orbit-control-inputs', 'key' => 'dependency-merge-'.str_replace('_token', '', $name).'-'.Str::uuid()];
            }

            return DB::transaction(function () use ($group, $intent, $secrets, $caller, $request): Operation {
                Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail();

                return $this->operations->execute('tasks.dependency_merge', 'task_group', $group->id,
                    SwampNode::id(), [...$intent, 'execution_secrets' => $secrets], $caller, $request,
                    lockKey: OperationStore::lockKey('task_group', $group->id));
            });
        });
    }
}
