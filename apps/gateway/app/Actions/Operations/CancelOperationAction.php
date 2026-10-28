<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\OperationExecutors;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStore;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Asks an operation to stop (docs/reference/operations.md#cancel-an-operation). A queued operation ends `cancelled`
 * at once; a running one stops before its next step. A terminal operation stays as it is.
 */
final readonly class CancelOperationAction
{
    public function __construct(private OperationStore $store, private OperationRunner $runner) {}

    public function execute(Operation $operation, bool $targetEnding = false): Operation
    {
        if ($operation->status->terminal()) {
            return $operation;
        }

        if ($operation->cancel_requested_at === null) {
            $this->store->change($operation, ['cancel_requested_at' => Carbon::now()]);
        }

        DB::transaction(function () use ($operation): void {
            if ($this->store->cancelQueued($operation)) {
                app(OperationExecutors::class)->completion($operation->kind)?->complete($operation, OperationOutcome::cancelled());
            }
        });
        if (! app(OperationExecutors::class)->swamp($operation->kind)) {
            $this->runner->cancelWaiting($operation, $targetEnding);
        }

        return $operation->refresh();
    }
}
