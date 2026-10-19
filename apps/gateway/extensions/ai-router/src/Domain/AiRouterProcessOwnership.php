<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Processes\ProcessRemovalGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

final readonly class AiRouterProcessOwnership implements ProcessRemovalGuard
{
    public function __construct(private AiRouterState $state) {}

    public function assertRemovable(#[SensitiveParameter] Process $process): void
    {
        if (! $this->state->enabled()) {
            return;
        }

        $nodeId = $this->state->nodeId();

        if (
            $process->owner_type === Node::class
            && $process->owner_id === $nodeId
            && $process->name === AiRouterProcess::NAME
        ) {
            throw new ResourceOperationException(
                'process.required_by_ai_router',
                "Process [{$process->name}] belongs to the ai-router extension. Disable ai-router first.",
                409,
            );
        }
    }
}
