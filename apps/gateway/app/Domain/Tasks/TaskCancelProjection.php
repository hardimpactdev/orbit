<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofKeptVms;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Complete cleanup alone may forget workspace records and settle cancellation. */
final readonly class TaskCancelProjection implements DirectOperationProjection, DirectOperationTimeout
{
    public function input(Operation $operation): array
    {
        return app(TaskCancelIntent::class)->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        if ($data['status'] === OperationStatus::Interrupted->value) {
            $data['status'] = OperationStatus::Failed->value;
        }

        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                return $operation;
            }
            $group = Task::topLevel()->lockForUpdate()->findOrFail($operation->target_id);
            if ($data['status'] === 'succeeded') {
                $push = $operation->input['push'] ?? null;
                if (is_array($push) && is_string($push['commit'] ?? null)) {
                    TaskFinalReview::recordRemovalPush($group, $push['commit']);
                }
                app(TaskCancelIntent::class)->input($operation);
            }
            $result = ValidatedData::object($data['result'] ?? []);
            if ($data['status'] === 'succeeded') {
                if (($result['group_id'] ?? null) !== $group->id || ($result['instance_id'] ?? null) !== ($operation->input['instance_id'] ?? null)
                    || ($result['cleanup_complete'] ?? null) !== true) {
                    throw new ResourceOperationException('operation.invalid_result', 'Cancellation requires its exact complete cleanup receipt.', 422);
                }
                app(TaskGroupEnding::class)->settle($group, $operation->input, $operation->kind);
                // A VM that a failed proof run kept (--keep-failed) is released now: the kept list says so.
                app(ProofKeptVms::class)->released($operation, $group->id);
            } elseif (in_array($data['status'], ['failed', 'cancelled'], true)) {
                $this->failed($operation, ValidatedData::nullableString($data['error_code'] ?? null));
            }

            return app(DirectOperation::class)->project($operation, $data, $result);
        });
    }

    public function timedOut(Operation $operation): void
    {
        $this->failed($operation, 'operation.timed_out');
    }

    /** Incomplete cleanup keeps the group and workspace for another cancel; only its captured VM cleanup fails. */
    private function failed(Operation $operation, ?string $errorCode): void
    {
        app(TaskGroupEnding::class)->failed($operation->input, $errorCode);
    }
}
