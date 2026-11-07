<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Operation;
use Orbit\Extensions\AiRouter\Domain\AiRouterSecrets;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;

/**
 * Records the intent of the task VM guard on the router's Node (docs/cli/ai-router/task-guard.mmd): its Caddyfile, its two UFW rules and
 * vault copies of the task key and the anchor key. Swamp writes them on the Node and proves the guard refuses what it must. With
 * `$remove`, the run takes the guard, its keys and its rules off the Node instead.
 */
final readonly class AdmitAiRouterTaskGuardAction
{
    public function __construct(private AiRouterState $state, private AiRouterTaskGuard $guard, private AiRouterSecrets $secrets,
        private SwampSshExecutionInput $ssh, private NodeFirewallRuleCatalog $firewall, private StartOperationAction $start) {}

    public function execute(bool $remove = false, ?string $triggeredBy = null, ?string $requestId = null): Operation
    {
        if (! $remove) {
            $this->state->assertEnabled();
        }
        $node = $this->guard->node() ?? throw new ResourceOperationException('ai_router.disabled', 'The ai-router has no Node.', 409);
        $rules = $this->guard->rules($node);
        $ufw = static fn (array $arguments): array => ['ufw', ...array_slice($arguments, 2)];
        $vault = 'orbit-control-inputs';
        $shared = app(SwampCommandStep::class);

        return $this->start->execute('ai_router.task_guard', 'node', $node->id, $node->id, [
            'intent' => [
                'ssh' => $this->ssh->forNode($node),
                'state' => $remove ? 'absent' : 'present',
                'origin' => $this->guard->origin($node),
                'directory' => AiRouterTaskGuard::Directory,
                'unit' => AiRouterTaskGuard::Unit,
                'caddyfile' => $this->guard->caddyfile($node),
                // The shape `tasks/firewall.yml` keeps: the WireGuard member trust first, then each deny inserted at the top and its allow
                // after it, so the allow ends above the deny and both above the trust; it re-places a pair it finds out of order.
                'firewall' => [
                    'desired' => [$ufw($this->firewall->forNode($node)[1]->arguments),
                        ...($remove ? [] : [['ufw', 'insert', '1', ...array_slice($rules['deny']->arguments, 2)], ['ufw', 'insert', '1', ...array_slice($rules['allow']->arguments, 2)]])],
                    'remove' => $remove ? [$rules['allow']->removalArguments(), $rules['deny']->removalArguments()] : [],
                ],
            ],
            'execution_secrets' => [
                'task_key' => $shared->executionCopy($vault, 'ai-router-task-key-node-'.$node->id, $this->secrets->task()),
                'anchor_key' => $shared->executionCopy($vault, 'ai-router-anchor-key-node-'.$node->id,
                    $this->secrets->anchor(rtrim((string) $this->state->routerUrl(), '/'))),
            ],
        ], $triggeredBy, $requestId);
    }
}
