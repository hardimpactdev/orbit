<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\DirectOperation;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A durable native terminal notice. Only the shared helper reads history or projects its public result. */
final class ProjectSwampTerminal implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $operationId, public readonly string $runId, public readonly ?string $status)
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
        $step->projectTerminal($operation, ['id' => $this->runId, ...($this->status !== null ? ['status' => $this->status] : [])], $selector);
    }
}
