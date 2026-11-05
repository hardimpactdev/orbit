<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Infrastructure\Firewall\DesiredFirewallRule;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Models\FirewallRule;
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
            array_push($expected, ...$this->forRule($rule));
        }

        return $expected;
    }

    /**
     * The lines one operator rule intends: one per protocol and address family.
     *
     * @return list<array{name:string,shape:array<string,mixed>}>
     */
    public function forRule(FirewallRule $rule): array
    {
        return array_map(static fn (FirewallInspectionTarget $target): array => ['name' => $rule->identity(), 'shape' => [
            'comment' => $target->shape->comment, 'action' => $target->shape->action,
            'direction' => $target->shape->direction, 'source' => $target->shape->source,
            'destination' => $target->shape->destination, 'port' => $target->shape->port,
            'protocol' => $target->shape->protocol, 'in_interface' => $target->shape->inInterface,
            'out_interface' => $target->shape->outInterface, 'family' => $target->shape->family,
        ]], FirewallInspectionTarget::forRule($rule));
    }
}
