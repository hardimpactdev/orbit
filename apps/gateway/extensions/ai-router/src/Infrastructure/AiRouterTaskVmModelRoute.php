<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Models\Node;
use Orbit\Extensions\AiRouter\Domain\AiRouterSecrets;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;

/** The model route of containers: the Node model forwarder reaches the ai-router guard with one fixed placeholder key. */
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

    /** The key exists before setup: containers keep it while the router is off, and redaction must still recognize it. */
    public function key(): string
    {
        return $this->secrets->task();
    }

    /** The guard's Node while the extension is enabled and the last guard run applied the guard. */
    private function node(): Node
    {
        $node = $this->state->enabled() ? $this->guard->node() : null;
        if ($node === null) {
            throw new ResourceOperationException('ai_router.disabled',
                'Set up the ai-router extension with ai-router:setup first. Containers reach models through its guard.', 409);
        }
        if (! $this->guard->applied($node)) {
            throw new ResourceOperationException('ai_router.guard_missing',
                'Apply the router guard with ai-router:task-guard first. Containers reach models through the Node model forwarder and this guard.', 409);
        }

        return $node;
    }
}
