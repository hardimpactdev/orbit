<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** The same terminal transition serves root callbacks and verified history reads. */
final readonly class FailNativeOperationAction
{
    public function __construct(private DirectOperation $direct) {}

    public function execute(Operation $operation, string $runId): Operation
    {
        return DB::transaction(function () use ($operation, $runId): Operation {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($current->kind !== $operation->kind || $current->node_id !== $operation->node_id) {
                throw new ResourceOperationException('operation.run_mismatch', 'The Operation no longer matches its native binding.', 409);
            }
            $this->direct->assertRun($current, $runId);
            if ($current->status->terminal()) {
                return $current;
            }

            return $this->direct->project($current, [
                'run_id' => $runId, 'status' => 'failed',
                'started_at' => ($current->started_at ?? $current->created_at ?? now())->toISOString(),
                'finished_at' => now()->toISOString(), 'error_code' => 'operation.native_failed',
            ], []);
        });
    }
}
