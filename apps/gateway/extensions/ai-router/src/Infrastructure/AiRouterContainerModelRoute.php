<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Orbit\Extensions\AiRouter\Domain\AiRouterSecrets;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;

/** The guarded model route and placeholder key used by Instance containers through their Node's forwarder. */
final readonly class AiRouterContainerModelRoute implements ContainerModelRoute
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

    /** Redaction needs the placeholder even while the extension or guard is unavailable. */
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
                'Set up the ai-router extension with ai-router:setup first. Instance containers reach models through its guard.', 409);
        }
        if (! $this->guard->applied($node)) {
            throw new ResourceOperationException('ai_router.guard_missing',
                'Apply the model guard with ai-router:task-guard first. Instance containers reach models only through it.', 409);
        }

        return $node;
    }
}
