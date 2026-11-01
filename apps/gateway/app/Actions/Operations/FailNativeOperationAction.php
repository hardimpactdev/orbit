<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** The same terminal transition serves root callbacks and verified history reads. */
final readonly class FailNativeOperationAction
{
    public function __construct(private DirectOperation $direct) {}

    /** @param array{failed_step:string,detail:string}|null $failure */
    public function execute(Operation $operation, string $runId, OperationStatus $status = OperationStatus::Failed, ?array $failure = null, ?string $finishedAt = null): Operation
    {
        if (! in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true)) {
            throw new \LogicException('A native failure transition requires failed or interrupted history.');
        }

        return DB::transaction(function () use ($operation, $runId, $status, $failure, $finishedAt): Operation {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($current->kind !== $operation->kind || $current->node_id !== $operation->node_id) {
                throw new ResourceOperationException('operation.run_mismatch', 'The Operation no longer matches its native binding.', 409);
            }
            $this->direct->assertRun($current, $runId);
            if ($current->status->terminal()) {
                if ($current->status === $status && $current->error_code === 'operation.native_failed') {
                    return $this->direct->project($current, ['run_id' => $runId, 'status' => $status->value,
                        'error_code' => $current->error_code, 'failure' => $failure], $current->result ?? []);
                }

                return $current;
            }

            return $this->direct->project($current, [
                'run_id' => $runId, 'status' => $status->value,
                'started_at' => ($current->started_at ?? $current->created_at ?? now())->toISOString(),
                'finished_at' => $finishedAt ?? now()->toISOString(), 'error_code' => 'operation.native_failed', 'failure' => $failure,
            ], []);
        });
    }
}
