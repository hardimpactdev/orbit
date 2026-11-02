<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Infrastructure\Firewall\DesiredFirewallRule;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Models\Node;

/** Public desired UFW rules for Doctor and the live list; execution and comparison belong to the native run. */
final readonly class FirewallInspectionInput
{
    /** @return list<array{name:string,shape:array<string,mixed>}> */
    public function expected(Node $node): array
    {
        $expected = array_map(static fn (DesiredFirewallRule $rule): array => [
            'name' => $rule->name, 'shape' => $rule->shape->toAgentInput(),
        ], app(NodeFirewallDesiredRules::class)->forNode($node));
        foreach ($node->firewallRules()->orderBy('port')->orderBy('protocol')->orderBy('source')->get() as $rule) {
            foreach (FirewallInspectionTarget::forRule($rule) as $target) {
                $shape = $target->shape;
                $expected[] = ['name' => $rule->identity(), 'shape' => [
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
