<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the terminal event of one `operation-task-prep` run once: the workspace and VM as provision records them,
 * the metrics of each thread, and the draft pull request on the group as publish records it. The run's steps call
 * the Gateway for none of this. See docs/reference/tasks.md#prepare-a-command.
 */
final readonly class TaskPrepProjection implements DirectOperationProjection
{
    /** The workflow steps whose failure has a code of its own; any other failed step of a turn is `tasks.turn_failed`. */
    private const array StepCodes = [
        'Create the VM' => 'tasks.provision_failed', 'Pin the VM' => 'tasks.provision_failed',
        'Check the draft' => 'tasks.prep_not_committed', 'Settle fix 1' => 'tasks.prep_not_committed', 'Settle fix 2' => 'tasks.prep_not_committed',
        'Review has no findings' => 'tasks.prep_review_unresolved',
    ];

    public function __construct(private TaskProvisionProjection $provision) {}

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $provision = $this->provision->input(self::provisionView($operation));
        $intent = ValidatedData::object($operation->input);

        return [
            'intent' => ['provision' => $provision['intent'], 'draft' => $intent['draft'], 'fix' => $intent['fix'], 'reviews' => $intent['reviews'], 'publish' => $intent['publish']],
            'execution_secrets' => $provision['execution_secrets'],
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
            $step = $failed ? $this->failedStep($native, $data) : null;
            $code = $failed ? (self::StepCodes[$step ?? ''] ?? ($step === 'Open a draft PR' ? self::publishCode($data) : 'tasks.turn_failed')) : null;
            $receipt = TaskPrepResults::receipt($native['provision'] ?? null);
            if ($status === 'succeeded' && $receipt === null) {
                throw new ResourceOperationException('operation.invalid_result', 'The prep run reports no ready VM.', 422);
            }

            // The VM and the workspace of the group, from the same receipt provision records. A run that never got a VM ready records the failure.
            $this->provision->record(self::provisionView($operation), ['status' => $receipt !== null ? 'succeeded' : ($status === 'cancelled' ? 'cancelled' : 'failed'),
                'error_code' => $receipt !== null ? null : ($code ?? 'tasks.provision_failed')], $receipt ?? ['failed_step' => 'provision'], static fn (): Operation => $operation);

            // The draft and the fixes run on the author thread, and each review on a thread of its own.
            $intent = ValidatedData::object($operation->input);
            $author = ValidatedData::integer(ValidatedData::object($intent['draft'])['thread_id']);
            $reviews = array_map(static fn (mixed $turn): int => ValidatedData::integer(ValidatedData::object($turn)['thread_id']), array_values(ValidatedData::object($intent['reviews'])));
            $threads = ['draft' => $author, 'review_1' => $reviews[0], 'fix_1' => $author, 'review_2' => $reviews[1], 'fix_2' => $author, 'review_3' => $reviews[2]];
            $rounds = 0;
            foreach ($threads as $name => $thread) {
                $result = TaskPrepResults::turn($native[$name] ?? null);
                if ($result !== null) {
                    TaskTurnProjection::recordThread($operation->target_id, $thread, $result);
                    $rounds += str_starts_with($name, 'review') ? 1 : 0;
                }
            }

            $result = ['error_code' => $code, 'failed_step' => $step];
            if ($status === 'succeeded') {
                $pull = TaskPrepResults::pullRequest($native['publish'] ?? null)
                    ?? throw new ResourceOperationException('operation.invalid_result', 'The prep run reports no pull request.', 422);
                $result = ['branch' => ValidatedData::string(ValidatedData::object($intent['publish'])['branch']), 'pull_request_number' => $pull['pull_request_number'],
                    'pull_request_url' => $pull['pull_request_url'], 'rounds' => $rounds];
                Task::topLevel()->whereKey($operation->target_id)->update(['pr_url' => $pull['pull_request_url'], 'watched_pr_url' => $pull['pull_request_url'],
                    'watched_pr_number' => $pull['pull_request_number'], 'watched_pr_state' => 'open']);
            }
            $data['result'] = $result;
            $data['error_code'] = $code;

            return app(DirectOperation::class)->project($operation, $data, $result);
        });
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

    /**
     * @param  array<string,mixed>  $native
     * @param  array<string,mixed>  $data
     */
    private function failedStep(array $native, array $data): ?string
    {
        $failure = ValidatedData::object($data['failure'] ?? []);
        $steps = ValidatedData::object($native['summary'] ?? [])['failed_steps'] ?? [];
        $step = $failure['failed_step'] ?? (is_array($steps) ? ($steps[0] ?? null) : null);

        return is_string($step) ? $step : null;
    }

    /** @param array<string,mixed> $data */
    private static function publishCode(array $data): string
    {
        $failure = is_array($data['failure'] ?? null) ? $data['failure'] : [];
        $detail = $failure['detail'] ?? null;

        return in_array($detail, ['tasks.head_moved', 'tasks.not_fast_forward', 'tasks.workflows_permission', 'tasks.push_failed', 'tasks.publish_failed', 'tasks.repository_execution_unknown'], true)
            ? $detail : 'tasks.publish_failed';
    }
}
