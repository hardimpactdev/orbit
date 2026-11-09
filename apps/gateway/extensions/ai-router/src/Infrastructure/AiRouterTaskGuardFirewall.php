<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Infrastructure\Firewall\ContributedNodeFirewallRules;
use App\Models\Node;
use Orbit\Extensions\AiRouter\Domain\AiRouterModelForwarder;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;

/**
 * The guard's UFW rules on the router's Node, in UFW's order, while the last guard or forwarder run left the guard in place: each
 * forwarder Node's allow (each inserted at the top, so the last one first), the task VM range, and the deny. On a forwarder Node, while
 * its last succeeded run left the forwarder in place: the bridge rule. Its nftables table is no UFW rule.
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

        $allows = array_map(fn (array $own) => $this->guard->forwarderRule($node, $own['node']), array_values($present));

        return [...array_reverse($allows), $rules['allow'], $rules['deny']];
    }
}
