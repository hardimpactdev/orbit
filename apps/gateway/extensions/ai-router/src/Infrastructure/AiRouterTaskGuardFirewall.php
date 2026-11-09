<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Infrastructure\Firewall\ContributedNodeFirewallRules;
use App\Models\Node;
use Orbit\Extensions\AiRouter\Domain\AiRouterModelForwarder;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;

/**
 * The guard's UFW rules on the router's Node, while the last applied guard or forwarder run left the guard in place: the task VM range,
 * each forwarder Node, and the deny. On a forwarder Node, the forwarder's bridge rule while its last succeeded run left it in place.
 */
final readonly class AiRouterTaskGuardFirewall implements ContributedNodeFirewallRules
{
    public function __construct(private AiRouterTaskGuard $guard, private AiRouterModelForwarder $forwarder) {}

    public function forNode(Node $node): array
    {
        $router = $this->guard->node();
        if ($router === null) {
            return [];
        }
        $present = $this->forwarder->present($router);
        if ($router->id !== $node->id) {
            $own = $present[$node->id] ?? null;

            return $own === null ? [] : [$this->forwarder->rule($own['bridge'], $own['address'])];
        }
        if (! $this->guard->applied($node)) {
            return [];
        }
        $rules = $this->guard->rules($node);

        return [$rules['allow'], ...array_map(fn (array $own) => $this->guard->forwarderRule($node, $own['node']), array_values($present)), $rules['deny']];
    }
}
