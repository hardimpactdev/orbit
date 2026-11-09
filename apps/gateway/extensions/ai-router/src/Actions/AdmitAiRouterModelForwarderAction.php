<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use Orbit\Extensions\AiRouter\Domain\AiRouterModelForwarder;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;

/**
 * Records the intent of a container model forwarder on an agent Node (docs/cli/ai-router/model-forwarder.mmd): the guard with the Node's
 * WireGuard address added to its sources, or removed, then the forwarder's Caddyfile and UFW rule. Only the guard phase gets the keys;
 * the forwarder gets none. The run is a guard run too, so it carries the guard's intent at the top and takes the guard's lock.
 */
final readonly class AdmitAiRouterModelForwarderAction
{
    public function __construct(private AiRouterState $state, private AiRouterTaskGuard $guard, private AiRouterModelForwarder $forwarder,
        private SwampSshExecutionInput $ssh, private StartOperationAction $start) {}

    public function execute(Node $node, ?string $bridge, ?string $address, bool $remove = false, ?string $triggeredBy = null, ?string $requestId = null): Operation
    {
        $this->state->assertEnabled();
        $router = $this->guard->node() ?? throw new ResourceOperationException('ai_router.disabled', 'The ai-router has no Node.', 409);
        if ($node->status !== LifecycleStatus::Active || $node->platform !== 'linux') {
            throw new ResourceOperationException('ai_router.node_invalid', "Node [{$node->name}] is not an active Linux Node.", 422);
        }
        $this->guard->address($node);
        // The router's Node holds the real key; the swamp Node proves the guard's refusal; the vpn hub forwards every task VM's traffic
        // to the guard, which the forwarder's forward drop would cut.
        if ($node->id === $router->id || $node->roles()->whereIn('role', [RoleName::Swamp, RoleName::Vpn])->exists()) {
            throw new ResourceOperationException('ai_router.forwarder_refused',
                "Node [{$node->name}] holds the real key, proves the guard's refusal or forwards task VM traffic; it cannot run a forwarder.", 409);
        }
        // A forwarder run applies the guard; it must not bring back a guard that `ai-router:task-guard --remove` took off. A removal
        // while the guard is off runs only the forwarder half and leaves the guard off.
        $guard = $this->guard->applied($router);
        if (! $guard && ! $remove) {
            throw new ResourceOperationException('ai_router.guard_absent', 'The guard is not in place; run `ai-router:task-guard` first.', 409);
        }
        if ($remove) {
            ['bridge' => $bridge, 'address' => $address] = $this->forwarder->last($router, $node)
                ?? throw new ResourceOperationException('resource.not_found', "Node [{$node->name}] has no forwarder run.", 404);
        } elseif (! is_string($bridge) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,14}\z/', $bridge) !== 1
            || ! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException('validation.failed', 'The forwarder needs the bridge name and its IPv4 address.', 422);
        }
        $others = array_values(array_filter($this->guard->forwarders($router), static fn (Node $other): bool => $other->id !== $node->id));
        $rule = $this->forwarder->rule($bridge, $address);

        return $this->start->execute(AiRouterModelForwarder::Kind, 'node', $node->id, $router->id, [
            'intent' => [
                ...$this->guard->intent($router, $remove ? $others : $this->byId([...$others, $node]), false),
                'state' => $guard ? 'present' : 'absent',
                'forwarder' => [
                    'ssh' => $this->ssh->forNode($node),
                    'state' => $remove ? 'absent' : 'present',
                    'bridge' => $bridge,
                    'address' => $address,
                    'origin' => $this->forwarder->origin($address),
                    'guard_origin' => $this->guard->origin($router),
                    'directory' => AiRouterModelForwarder::Directory,
                    'unit' => AiRouterModelForwarder::Unit,
                    'caddyfile' => $this->forwarder->caddyfile($address, $this->guard->origin($router)),
                    'user' => AiRouterModelForwarder::User,
                    'table' => AiRouterModelForwarder::Table,
                    'table_unit' => AiRouterModelForwarder::TableUnit,
                    'ruleset' => $this->forwarder->ruleset($this->guard->address($router)),
                    'firewall' => [
                        'desired' => $remove ? [] : [['ufw', ...array_slice($rule->arguments, 2)]],
                        'remove' => $remove ? [$rule->removalArguments()] : [],
                    ],
                ],
            ],
            'execution_secrets' => $this->guard->executionSecrets($router),
        ], $triggeredBy, $requestId, lockKey: $this->guard->lock($router));
    }

    /**
     * In the order of every other guard run, so UFW and the managed rules list the allows alike.
     *
     * @param  list<Node>  $nodes
     * @return list<Node>
     */
    private function byId(array $nodes): array
    {
        usort($nodes, static fn (Node $a, Node $b): int => $a->id <=> $b->id);

        return $nodes;
    }
}
