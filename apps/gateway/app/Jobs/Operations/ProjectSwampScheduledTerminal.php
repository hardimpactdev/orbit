<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Infrastructure\Swamp\SwampCommandStep;
use Illuminate\Foundation\Bus\Dispatchable;

/** Projects one exact scheduled root after its native terminal notice. */
final readonly class ProjectSwampScheduledTerminal
{
    use Dispatchable;

    public function __construct(public string $workflowId, public string $runId, public ?string $status) {}

    public function handle(SwampCommandStep $step): void
    {
        $step->projectScheduledTerminal($this->workflowId, ['id' => $this->runId,
            ...($this->status !== null ? ['status' => $this->status] : [])]);
    }
}
