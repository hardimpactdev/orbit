<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\ReadNativeRunFailure;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use Illuminate\Support\Str;

/** Records terminal native history when the original workflow could not deliver its callback. */
final readonly class ReadOperationAction
{
    public function __construct(private ReadNativeRunFailure $history, private DirectOperation $direct, private FailNativeOperationAction $failures) {}

    public function execute(Operation $operation): Operation
    {
        $runId = $operation->state['swamp_run_id'] ?? null;
        $definition = $this->direct->definition($operation->kind);
        $deadline = OperationRunBudgets::deadline($operation);
        if ((! $operation->status->terminal() || ExpireOperationAction::awaitsSettlement($operation)) && $deadline !== null && ! $deadline->isFuture()
            && is_string($runId) && Str::isUuid($runId) && isset($definition['terminal_result'])) {
            try {
                return app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $runId], $definition['terminal_result'], atDeadline: true);
            } catch (ResourceOperationException) {
                return app(ExpireOperationAction::class)->execute($operation->refresh());
            }
        }
        $operation = app(ExpireOperationAction::class)->execute($operation);
        if (($operation->status->terminal() && ! ($operation->error_code === 'operation.native_failed'
            && in_array($operation->status, [OperationStatus::Failed, OperationStatus::Interrupted], true)
            && ! array_key_exists('native_failure', $operation->state ?? []))) || ! is_string($runId) || ! Str::isUuid($runId)
            || $definition === null || $operation->node_id === null) {
            return $operation;
        }
        $receipt = $this->history->read($operation, $definition['workflow'], $runId);
        if ($receipt === null) {
            return $operation->refresh();
        }
        try {
            return $this->failures->execute($operation, $runId, $receipt['status'], $receipt['failure'], $receipt['finished_at']);
        } catch (ResourceOperationException $exception) {
            if (! in_array($exception->errorCode, ['operation.run_mismatch', 'operation.run_unbound'], true)) {
                throw $exception;
            }

            return $operation->refresh();
        }
    }
}
