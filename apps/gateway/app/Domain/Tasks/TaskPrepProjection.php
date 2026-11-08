<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the terminal event of one `operation-task-prep` run once: the workspace and VM as provision records them,
 * the metrics of each thread, and the draft pull request on the group as publish records it. A run in manual mode parks
 * the group, because the spec waits for the owner. The run's steps call the Gateway for none of this. See docs/reference/tasks.md#prepare-a-command.
 */
final readonly class TaskPrepProjection implements DirectOperationProjection
{
    /** The workflow steps whose failure has a code of its own; any other failed step of a turn is `tasks.turn_failed`. */
    private const array StepCodes = [
        'Claim a slot' => 'tasks.provision_failed',
        'Check the draft' => 'tasks.prep_not_committed',
        'Plan the cases 0' => 'tasks.prep_plan_failed', 'Plan the cases 1' => 'tasks.prep_plan_failed', 'Plan the cases 2' => 'tasks.prep_plan_failed', 'Settle fix 1' => 'tasks.prep_not_committed', 'Settle fix 2' => 'tasks.prep_not_committed',
        'Review has no findings' => 'tasks.prep_review_unresolved',
    ];

    public function __construct(private TaskProvisionProjection $provision) {}

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $provision = $this->provision->input(self::provisionView($operation));
        $intent = ValidatedData::object($operation->input);

        return [
            'intent' => ['provision' => $provision['intent'], 'draft' => $intent['draft'], 'fix' => $intent['fix'], 'reviews' => $intent['reviews'], 'publish' => $intent['publish'], 'plan' => $intent['plan']],
            // The claim's Pi token, which every turn of the prep reads.
            'execution_secrets' => [...ValidatedData::object($provision['execution_secrets']), 'pi_token' => ['vault' => 'orbit-control-inputs',
                'key' => TaskVmSlotRuns::piTokenKey(TaskVm::query()->findOrFail(ValidatedData::integer(ValidatedData::object($intent['provision'])['task_vm_id'])))]],
            'publish_secrets' => $intent['publish_secrets'],
        ];
    }

    /** @param array<string,mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $native = ValidatedData::object($data['result'] ?? []);
            $status = ValidatedData::string($data['status']);
            $failed = in_array($status, ['failed', 'interrupted'], true);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($operation->result ?? []));
            }
            $step = $failed ? TaskPrepResults::failedStep($native, $data) : null;
            $code = $failed ? (self::StepCodes[$step ?? ''] ?? ($step === 'Open a draft PR' ? TaskPrepResults::publishCode($data) : 'tasks.turn_failed')) : null;
            $claimed = is_array($native['provision'] ?? null);
            if ($status === 'succeeded' && ! $claimed) {
                throw new ResourceOperationException('operation.invalid_result', 'The prep run reports no claimed slot.', 422);
            }

            // The slot and the workspace of the group, as a claim records them. A run whose claim failed releases its slot.
            $this->provision->record(self::provisionView($operation), $claimed ? 'succeeded' : ($status === 'cancelled' ? 'cancelled' : 'failed'),
                $claimed ? null : ($code ?? 'tasks.provision_failed'), static fn (): Operation => $operation);

            $intent = ValidatedData::object($operation->input);
            $threads = self::threads($intent);
            $rounds = 0;
            foreach ($threads as $name => $thread) {
                $result = TaskPrepResults::turn($native[$name] ?? null);
                if ($result !== null) {
                    TaskTurnProjection::recordTurn($operation, $thread, $result);
                    $rounds += str_starts_with($name, 'review') ? 1 : 0;
                }
            }

            // What the last review still asked for, so the orchestrator can read why the loop did not end.
            $findings = $native['findings'] ?? null;
            $result = ['error_code' => $code, 'failed_step' => $step, ...($step === 'Review has no findings' && is_string($findings) ? ['findings' => mb_strcut($findings, 0, 4000, 'UTF-8')] : [])];
            if ($status === 'succeeded') {
                $pull = TaskPrepResults::pullRequest($native['publish'] ?? null)
                    ?? throw new ResourceOperationException('operation.invalid_result', 'The prep run reports no pull request.', 422);
                $result = ['branch' => ValidatedData::string(ValidatedData::object($intent['publish'])['branch']), 'pull_request_number' => $pull['pull_request_number'],
                    'pull_request_url' => $pull['pull_request_url'], 'rounds' => $rounds];
                Task::topLevel()->whereKey($operation->target_id)->update(['pr_url' => $pull['pull_request_url'], 'watched_pr_url' => $pull['pull_request_url'],
                    'watched_pr_number' => $pull['pull_request_number'], 'watched_pr_state' => 'open']);
                self::completeSubtasks($operation->target_id, $pull['pull_request_number']);
            }
            $data['result'] = $result;
            $data['error_code'] = $code;

            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            TaskVmReentry::release($projected);

            return $projected;
        });
    }

    /**
     * The spec is done once the App has opened its PR, so the group has no open subtask and the VM is released while the
     * spec waits for review.
     */
    public static function completeSubtasks(int $group, int $pullRequest): void
    {
        Task::query()->where('parent_id', $group)->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])->get()
            ->each(static fn (Task $subtask): bool => $subtask->update(['status' => TaskStatus::Completed, 'settled_at' => now(),
                'completion_summary' => 'The spec is in draft PR #'.$pullRequest.'.']));
    }

    /**
     * The thread each turn of the run takes its metrics from: the draft and the fixes run on the author thread, and each
     * review on a thread of its own.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,int>
     */
    public static function threads(array $input): array
    {
        $thread = static fn (mixed $turn): int => ValidatedData::integer(ValidatedData::object($turn)['thread_id']);
        $author = $thread($input['draft']);
        $reviews = array_map($thread, array_values(is_array($input['reviews'] ?? null) ? $input['reviews'] : []));

        return ['draft' => $author, 'review_1' => $reviews[0], 'fix_1' => $author, 'review_2' => $reviews[1], 'fix_2' => $author, 'review_3' => $reviews[2]];
    }

    /**
     * The provision of one prep run as the Operation of its own kind: the provision intent as input, so the provision records
     * apply to it unchanged.
     */
    private static function provisionView(Operation $operation): Operation
    {
        $view = $operation->replicate();
        $view->id = $operation->id;
        $view->kind = 'tasks.provision';
        $intent = ValidatedData::object($operation->input);
        $view->input = [...ValidatedData::object($intent['provision']), 'execution_secrets' => $intent['execution_secrets']];

        return $view;
    }
}
