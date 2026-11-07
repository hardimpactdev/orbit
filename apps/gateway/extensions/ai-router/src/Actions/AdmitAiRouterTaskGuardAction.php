<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Operation;
use Orbit\Extensions\AiRouter\Domain\AiRouterSecrets;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;

/**
 * Records the intent of the task VM guard on the router's Node (docs/cli/ai-router/task-guard.mmd): its Caddyfile, its two UFW rules and
 * vault copies of the task key and the anchor key. Swamp writes them on the Node and proves the guard refuses what it must.
 */
final readonly class AdmitAiRouterTaskGuardAction
{
    public function __construct(private AiRouterState $state, private AiRouterTaskGuard $guard, private AiRouterSecrets $secrets,
        private SwampSshExecutionInput $ssh, private StartOperationAction $start) {}

    public function execute(?string $triggeredBy = null, ?string $requestId = null): Operation
    {
        $this->state->assertEnabled();
        $node = $this->guard->node() ?? throw new ResourceOperationException('ai_router.disabled', 'The ai-router has no Node.', 409);
        $rules = $this->guard->rules($node);
        $vault = 'orbit-control-inputs';
        $shared = app(SwampCommandStep::class);

        return $this->start->execute('ai_router.task_guard', 'node', $node->id, $node->id, [
            'intent' => [
                'ssh' => $this->ssh->forNode($node),
                'origin' => $this->guard->origin($node),
                'directory' => AiRouterTaskGuard::Directory,
                'caddyfile' => $this->guard->caddyfile($node),
                // Deny first, then allow, each inserted at the top: the allow ends above the deny, both above the WireGuard member trust.
                'firewall' => [['ufw', 'insert', '1', ...array_slice($rules['deny']->arguments, 2)], ['ufw', 'insert', '1', ...array_slice($rules['allow']->arguments, 2)]],
            ],
            'execution_secrets' => [
                'task_key' => $shared->executionCopy($vault, 'ai-router-task-key-node-'.$node->id, $this->secrets->task()),
                'anchor_key' => $shared->executionCopy($vault, 'ai-router-anchor-key-node-'.$node->id,
                    $this->secrets->anchor(rtrim((string) $this->state->routerUrl(), '/'))),
            ],
        ], $triggeredBy, $requestId);
    }
}
