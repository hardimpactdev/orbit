<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;

/**
 * Records the intent of the task VM guard on the router's Node (docs/cli/ai-router/task-guard.mmd): its Caddyfile, its UFW rules and
 * vault copies of the task key and the anchor key. Swamp writes them on the Node and proves the guard refuses what it must. With
 * `$remove`, the run takes the guard, its keys and its rules off the Node instead.
 */
final readonly class AdmitAiRouterTaskGuardAction
{
    public function __construct(private AiRouterState $state, private AiRouterTaskGuard $guard, private StartOperationAction $start) {}

    public function execute(bool $remove = false, ?string $triggeredBy = null, ?string $requestId = null): Operation
    {
        if (! $remove) {
            $this->state->assertEnabled();
        }
        $node = $this->guard->node() ?? throw new ResourceOperationException('ai_router.disabled', 'The ai-router has no Node.', 409);

        return $this->start->execute('ai_router.task_guard', 'node', $node->id, $node->id, [
            'intent' => $this->guard->intent($node, $this->guard->forwarders($node), $remove),
            'execution_secrets' => $this->guard->executionSecrets($node),
        ], $triggeredBy, $requestId, lockKey: $this->guard->lock($node));
    }
}
