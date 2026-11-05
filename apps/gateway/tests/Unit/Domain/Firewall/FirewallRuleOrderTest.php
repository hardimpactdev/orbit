<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallRuleOrder;
use App\Models\FirewallRule;

function order_rule(int $id, string $port, string $source = 'any', string $protocol = 'tcp'): FirewallRule
{
    return new FirewallRule()->forceFill(['id' => $id, 'port' => $port, 'source' => $source, 'protocol' => $protocol]);
}

/** @param list<FirewallRule> $rules */
function order_of(array $rules): array
{
    return array_map(static fn (FirewallRule $rule): string => "{$rule->port} {$rule->source} {$rule->protocol}", FirewallRuleOrder::sort($rules));
}

describe('FirewallRuleOrder', function (): void {
    it('keeps unrelated rules in creation order', function (): void {
        expect(order_of([order_rule(2, '80'), order_rule(1, '443'), order_rule(3, '22')]))
            ->toBe(['443 any tcp', '80 any tcp', '22 any tcp']);
    });

    it('puts one address above a CIDR above anywhere on the same port', function (): void {
        expect(order_of([
            order_rule(1, '5432'),
            order_rule(2, '5432', '10.99.0.0/16'),
            order_rule(3, '7000'),
            order_rule(4, '5432', '10.99.1.7'),
        ]))->toBe(['5432 10.99.1.7 tcp', '5432 10.99.0.0/16 tcp', '5432 any tcp', '7000 any tcp']);
    });

    it('sits above a broader rule only when the ports and protocols overlap', function (): void {
        expect(order_of([
            order_rule(1, '8000:8100'),
            order_rule(2, '9000', '10.0.0.1'),
            order_rule(3, '8050', '10.0.0.1'),
            order_rule(4, '8050', '10.0.0.2', 'udp'),
            order_rule(5, '8050', '10.0.0.3', 'any'),
        ]))->toBe(['8050 10.0.0.1 tcp', '8050 10.0.0.3 any', '8000:8100 any tcp', '9000 10.0.0.1 tcp', '8050 10.0.0.2 udp']);
    });

    it('does not mix address families or sources of the same size', function (): void {
        expect(order_of([
            order_rule(1, '25', '10.0.0.0/8'),
            order_rule(2, '25', '2001:db8::1'),
            order_rule(3, '25', '192.168.0.0/16'),
        ]))->toBe(['25 10.0.0.0/8 tcp', '25 2001:db8::1 tcp', '25 192.168.0.0/16 tcp']);
    });
});
