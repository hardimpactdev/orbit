<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Operations\OperationStatus;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Runs successful wake hooks on a queue worker, with one receipt per Operation. */
final class ProjectExecutorWake implements ShouldQueue
{
    use Queueable;

    private const string Receipt = 'wake_executor_projected';

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::OperationsPrompt->value);
    }

    public function handle(InstanceLifecycleContributions $contributions): void
    {
        DB::transaction(function () use ($contributions): void {
            $operation = Operation::query()->whereKey($this->operationId)->lockForUpdate()->first();
            if ($operation === null || ! ExecutorWake::owns($operation) || $operation->status !== OperationStatus::Succeeded
                || ($operation->state[self::Receipt] ?? false) === true) {
                return;
            }
            if (($instance = Instance::query()->find($operation->target_id)) !== null) {
                $contributions->projectWake($instance);
            }
            $operation->forceFill(['state' => [...($operation->state ?? []), self::Receipt => true]])->save();
        });
    }
}
