<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Read exact terminal history on the existing operations queue, away from live event reception. */
final class ProjectSwampTerminal implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $operationId, public string $runId, public ?string $status, public bool $atDeadline = false)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(SwampCommandStep $step): void
    {
        $operation = Operation::query()->findOrFail($this->operationId);
        $selector = app(DirectOperation::class)->definition($operation->kind)['terminal_result'] ?? null;
        if ($selector === null) {
            return;
        }
        try {
            $step->projectTerminal($operation, ['id' => $this->runId, ...($this->status !== null ? ['status' => $this->status] : [])], $selector, $this->atDeadline);
        } catch (ResourceOperationException $exception) {
            if (! $this->atDeadline) {
                throw $exception;
            }
            $expired = app(ExpireOperationAction::class)->execute($operation->refresh());
            if (! ExpireOperationAction::awaitsSettlement($expired)) {
                throw $exception;
            }
        }
    }
}
