<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\OperationExecutors;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class ResumeOperationAction
{
    public function __construct(private OperationStore $store, private OperationExecutors $executors) {}

    public function execute(Operation $operation): Operation
    {
        return DB::transaction(function () use ($operation): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status !== OperationStatus::Waiting || $operation->cancel_requested_at !== null
                || ! $this->executors->swamp($operation->kind)
                || ($operation->state['executor_assistance'] ?? null) !== ['error_code' => 'operation.reclaim_limit']) {
                throw new ResourceOperationException('operation.not_resumable', 'Only a Swamp Operation stopped at its reclaim limit without a cancel request can resume.', 409);
            }
            $state = $operation->state ?? [];
            unset($state['executor_assistance']);
            $this->store->change($operation, ['status' => OperationStatus::Queued, 'attempts' => 0, 'worker' => null, 'state' => $state]);

            return $operation;
        });
    }
}
