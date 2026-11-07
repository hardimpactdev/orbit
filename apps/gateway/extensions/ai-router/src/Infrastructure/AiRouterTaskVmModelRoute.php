<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Models\Node;
use Orbit\Extensions\AiRouter\Domain\AiRouterSecrets;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;

/** The model route of task VMs: the task VM guard of the ai-router extension and its fixed placeholder key. */
final readonly class AiRouterTaskVmModelRoute implements TaskVmModelRoute
{
    public function __construct(
        private AiRouterState $state,
        private AiRouterTaskGuard $guard,
        private AiRouterSecrets $secrets,
    ) {}

    public function assertReady(): void
    {
        $this->node();
    }

    public function origin(): string
    {
        return $this->guard->origin($this->node());
    }

    public function key(): string
    {
        $this->assertReady();

        return $this->secrets->task();
    }

    /** The guard's Node while the extension is enabled and the last guard run applied the guard. */
    private function node(): Node
    {
        $node = $this->state->enabled() ? $this->guard->node() : null;
        if ($node === null) {
            throw new ResourceOperationException('ai_router.disabled',
                'Set up the ai-router extension with ai-router:setup first. Task VMs reach models through its task VM guard.', 409);
        }
        if (! $this->guard->applied($node)) {
            throw new ResourceOperationException('ai_router.guard_missing',
                'Apply the task VM guard with ai-router:task-guard first. Task VMs reach models only through it.', 409);
        }

        return $node;
    }
}
