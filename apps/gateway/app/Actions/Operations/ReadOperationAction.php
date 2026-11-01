<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationRunBudgets;
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
        $status = $this->history->status($operation, $definition['workflow'], $runId);
        if ($status === null) {
            return $operation->refresh();
        }
        try {
            return $this->failures->execute($operation, $runId, $status);
        } catch (ResourceOperationException $exception) {
            if (! in_array($exception->errorCode, ['operation.run_mismatch', 'operation.run_unbound'], true)) {
                throw $exception;
            }

            return $operation->refresh();
        }
    }
}
