<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Infrastructure\Firewall\DesiredFirewallRule;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;

/** Capture public desired rules once; execution and comparison belong to the native run. */
final readonly class FirewallInspectionInput
{
    /** @return array<string,mixed> */
    public function capture(Node $node): array
    {
        return ['ssh' => app(SwampSshExecutionInput::class)->forNode($node), 'expected' => $this->expected($node)];
    }

    /** @return list<array<string,mixed>> */
    public function expected(Node $node): array
    {
        $expected = array_map(static fn (DesiredFirewallRule $rule): array => [
            'name' => $rule->name, 'shape' => $rule->shape->toAgentInput(),
        ], app(NodeFirewallDesiredRules::class)->forNode($node));
        foreach ($node->firewallRules()->orderBy('name')->get() as $rule) {
            $targets = $rule->action === FirewallAction::Allowlist ? FirewallAllowlist::targets($rule) : [FirewallInspectionTarget::fromRule($rule)];
            foreach ($targets as $target) {
                $shape = $target->shape;
                $expected[] = ['name' => $rule->name, 'shape' => [
                    'comment' => $shape->comment, 'action' => $shape->action,
                    'direction' => $shape->direction, 'source' => $shape->source,
                    'destination' => $shape->destination, 'port' => $shape->port,
                    'protocol' => $shape->protocol, 'in_interface' => $shape->inInterface,
                    'out_interface' => $shape->outInterface, 'family' => $shape->family,
                ]];
            }
        }

        return $expected;
    }
}
