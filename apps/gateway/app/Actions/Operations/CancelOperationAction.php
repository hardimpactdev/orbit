<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationCancelGuard;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Cancels unstarted intent; a bound native workflow owns its terminal result. */
final readonly class CancelOperationAction
{
    public function __construct(private OperationStore $store) {}

    public function execute(Operation $operation, bool $targetEnding = false): Operation
    {
        DB::transaction(function () use ($operation): void {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if ($operation->status->terminal()) {
                return;
            }
            $guard = app(DirectOperation::class)->definition($operation->kind)['cancel_guard'] ?? null;
            if (is_string($guard)) {
                $instance = app($guard);
                assert($instance instanceof OperationCancelGuard);
                $instance->assertCancellable($operation);
            }
            if (in_array($operation->status, [OperationStatus::Queued, OperationStatus::Running], true)
                && ! isset($operation->state['swamp_run_id']) && $operation->attempts === 0) {
                $this->store->change($operation, ['cancel_requested_at' => $operation->cancel_requested_at ?? now(),
                    'status' => OperationStatus::Cancelled, 'finished_at' => now(), 'error_code' => null,
                    'current_step' => null, 'failed_step' => null, 'lock_key' => null]);
            } elseif ($operation->cancel_requested_at === null) {
                $this->store->change($operation, ['cancel_requested_at' => now()]);
            }
        });

        return $operation->refresh();
    }
}
