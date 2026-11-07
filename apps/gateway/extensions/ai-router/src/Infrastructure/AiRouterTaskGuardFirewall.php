<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Infrastructure\Firewall\ContributedNodeFirewallRules;
use App\Models\Node;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;

/** The task VM guard's two UFW rules on the router's Node, which `ai_router.task_guard` applies. */
final readonly class AiRouterTaskGuardFirewall implements ContributedNodeFirewallRules
{
    public function __construct(private AiRouterTaskGuard $guard) {}

    public function forNode(Node $node): array
    {
        if ($this->guard->node()?->id !== $node->id) {
            return [];
        }
        $rules = $this->guard->rules($node);

        return [$rules['allow'], $rules['deny']];
    }
}
