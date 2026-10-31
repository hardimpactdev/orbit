<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Operations\DirectOperation;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use Illuminate\Foundation\Bus\Dispatchable;

/** A synchronous native terminal notice. Only the shared helper reads history or projects its public result. */
final readonly class ProjectSwampTerminal
{
    use Dispatchable;

    public function __construct(public int $operationId, public string $runId, public ?string $status) {}

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
