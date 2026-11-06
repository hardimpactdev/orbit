<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Operation;
use Illuminate\Support\Str;

/**
 * The one place that turns a recorded intent to stop an Operation into a request to Swamp. The Gateway records and
 * forwards; the native cancellation path stops the run. A run that no step has bound has nothing to cancel.
 */
final readonly class NativeRunCancellation
{
    public function __construct(private OperationStore $store) {}

    /**
     * Records the request once on the Operation; the reconciler forwards it until Swamp confirms. Only an operator's cancel
     * records it again, for a request that Swamp never confirmed.
     */
    public function request(Operation $operation, bool $again = false): bool
    {
        $run = $operation->state['swamp_run_id'] ?? null;
        if (! is_string($run) || ! Str::isUuid($run) || ! DirectOperation::owns($operation)
            || ! app(DirectOperation::class)->usesNativeTerminal($operation)
            || (! $again && isset($operation->state['native_cancel_requested_at']))) {
            return false;
        }
        $state = $operation->state ?? [];
        unset($state['native_cancel_confirmed_at']);
        $this->store->change($operation, ['state' => [...$state, 'native_cancel_requested_at' => now()->toISOString()]]);
        ReconcileSwampOperations::dispatch()->afterCommit();

        return true;
    }

    /** True for a failed Operation that the Gateway timed out and whose native run has no recorded end. */
    public function outlivesTimeout(Operation $operation): bool
    {
        return ExpireOperationAction::expiredUnsettled($operation) && isset($operation->state['swamp_run_id']);
    }
}
