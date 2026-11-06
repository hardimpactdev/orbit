<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskPullRequestEvidence;
use App\Domain\Tasks\TaskPullRequestTarget;
use App\Models\Operation;
use App\Models\Task;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admits the ready and merge steps of a task pull request. The Gateway reads GitHub with read-only tokens and records
 * the intent with the vault key name of the write token. The step asks the Gateway for the token when it runs, and Swamp calls GitHub with it.
 */
final readonly class StartTaskPullRequestStepAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private StartOperationAction $operations,
        private OperationIdempotency $idempotency, private TaskOperations $groups, private TaskPullRequestEvidence $evidence) {}

    public function ready(Task $group, string $head, string $mode, string $caller, string $request): Operation
    {
        return $this->admit(OperationKinds::TasksPrReady, $group, 'pr-ready', $caller, $request,
            fn (): TaskPullRequestTarget => $this->evidence->ready($group, $head, $mode),
            ['head_sha' => $head, 'mode' => $mode, 'reviewer' => TaskPullRequestEvidence::reviewer()]);
    }

    public function merge(Task $group, string $head, string $caller, string $request): Operation
    {
        return $this->admit(OperationKinds::TasksPrMerge, $group, 'pr-merge', $caller, $request,
            fn (): TaskPullRequestTarget => $this->evidence->merge($group, $head), ['head_sha' => $head]);
    }

    /**
     * @param  Closure(): TaskPullRequestTarget  $read
     * @param  array<string, mixed>  $extra
     */
    private function admit(string $kind, Task $group, string $prefix, string $caller, string $request, Closure $read, array $extra): Operation
    {
        $this->extension->execute();
        if (($existing = $this->idempotency->replay($kind)) !== null) {
            return $existing;
        }

        return app(TaskExecutionLock::class)->synchronized($group->id, function () use ($kind, $group, $prefix, $caller, $request, $read, $extra): Operation {
            if (($existing = $this->idempotency->replay($kind)) !== null) {
                return $existing;
            }
            $group->refresh();
            $this->groups->requireOpenExternal($group);
            if (Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('operation.target_busy', 'Another Task Operation owns this group.', 409);
            }
            $target = $read();
            $intent = [...$target->intent($group), ...$extra, 'node_id' => SwampNode::id()];
            // Only the vault key name is admitted. The step mints its token when it runs ({@see TaskGitHubTokens}).
            $secrets = ['write_token' => ['vault' => 'orbit-control-inputs', 'key' => $prefix.'-write-'.Str::uuid()]];

            return DB::transaction(function () use ($kind, $group, $intent, $secrets, $caller, $request): Operation {
                Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail();
                $operation = $this->operations->execute($kind, OperationKinds::TaskGroupTarget, $group->id,
                    SwampNode::id(), [...$intent, 'execution_secrets' => $secrets], $caller, $request,
                    lockKey: OperationStore::lockKey(OperationKinds::TaskGroupTarget, $group->id));

                return $operation;
            });
        });
    }
}
