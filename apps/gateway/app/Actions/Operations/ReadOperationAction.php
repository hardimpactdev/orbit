<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\InstanceMigration\InstanceMigrateProjection;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\ReadNativeRunFailure;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampReadBudget;
use App\Infrastructure\Swamp\SwampTerminalReadUnavailable;
use App\Models\Operation;
use Illuminate\Support\Str;

/** Records terminal native history when the original workflow could not deliver its callback. */
final readonly class ReadOperationAction
{
    public function __construct(private ReadNativeRunFailure $history, private DirectOperation $direct, private FailNativeOperationAction $failures, private SwampReadBudget $budget) {}

    public function execute(Operation $operation): Operation
    {
        if (InstanceMigrateProjection::unsettled($operation)) {
            if (! $this->budget->take('operation:'.$operation->id, 30)) {
                return $operation;
            }

            return app(InstanceMigrateProjection::class)->settle($operation);
        }
        $runId = $operation->state['swamp_run_id'] ?? null;
        $definition = $this->direct->definition($operation->kind);
        $deadline = OperationRunBudgets::deadline($operation);
        if (! $operation->status->terminal() && $deadline !== null && ! $deadline->isFuture()
            && is_string($runId) && Str::isUuid($runId) && isset($definition['terminal_result'])) {
            try {
                return app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $runId], $definition['terminal_result'], atDeadline: true);
            } catch (ResourceOperationException) {
                return app(ExpireOperationAction::class)->execute($operation->refresh());
            }
        }
        $operation = app(ExpireOperationAction::class)->execute($operation);
        if ($operation->status->terminal() || ! is_string($runId) || ! Str::isUuid($runId)
            || $definition === null || $operation->node_id === null) {
            return $operation;
        }
        // The shared terminal subscriber is the main observer; a read checks the run again at most every 30 seconds.
        if (! $this->budget->take('operation:'.$operation->id, 30)) {
            return $operation;
        }
        $receipt = $this->history->read($operation, $definition['workflow'], $runId);
        if ($receipt === null) {
            return $operation->refresh();
        }
        try {
            if (! isset($definition['terminal_result'])) {
                return $this->failures->execute($operation, $runId, $receipt['status'], $receipt['failure'], $receipt['finished_at']);
            }

            return app(SwampCommandStep::class)->projectTerminal($operation,
                ['id' => $runId, 'status' => $receipt['status']->value], $definition['terminal_result']);
        } catch (SwampTerminalReadUnavailable) {
            return $operation->refresh();
        } catch (ResourceOperationException $exception) {
            if (! in_array($exception->errorCode, ['operation.run_mismatch', 'operation.run_unbound'], true)) {
                throw $exception;
            }

            return $operation->refresh();
        }
    }
}
