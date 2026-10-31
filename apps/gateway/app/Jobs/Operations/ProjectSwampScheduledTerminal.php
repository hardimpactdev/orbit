<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Infrastructure\Swamp\SwampCommandStep;
use Illuminate\Foundation\Bus\Dispatchable;

/** Projects exact scheduled history after a native commit notice. */
final readonly class ProjectSwampScheduledTerminal
{
    use Dispatchable;

    public function __construct(public string $workflowId, public string $runId, public ?string $status) {}

    public function handle(SwampCommandStep $step): bool
    {
        return $step->projectScheduledTerminal($this->workflowId, ['id' => $this->runId,
            ...($this->status !== null ? ['status' => $this->status] : [])]);
    }
}
