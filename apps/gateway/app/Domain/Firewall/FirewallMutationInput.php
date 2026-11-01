<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Infrastructure\Firewall\DesiredFirewallRule;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\FirewallRule;

/** Capture complete public intent before the native run starts. */
final readonly class FirewallMutationInput
{
    public function __construct(private SwampSshExecutionInput $ssh) {}

    /** @return array<string,mixed> */
    public function capture(FirewallRule $rule, bool $removing = false): array
    {
        $rule->loadMissing('node');
        $intent = ['node_id' => $rule->node_id, 'action' => $rule->action->value, 'port' => $rule->port,
            'protocol' => $rule->protocol, 'source' => $rule->source, 'comment' => $rule->comment()];
        // A role-managed rule with the same tuple owns that ufw row too; removal leaves it in place.
        $intent['retain'] = $removing && array_any(app(NodeFirewallDesiredRules::class)->forNode($rule->node),
            static fn (DesiredFirewallRule $other): bool => $other->shape->action === $rule->action->value
                && $other->shape->source === $rule->source && $other->shape->port === $rule->port
                && in_array($other->shape->protocol, $rule->protocols(), true) && $other->shape->direction === 'in'
                && $other->shape->destination === 'any' && $other->shape->inInterface === null && $other->shape->outInterface === null);

        return ['ssh' => $this->ssh->forNode($rule->node), 'rule' => $intent, 'ensure' => $removing ? 'absent' : 'present'];
    }
}
