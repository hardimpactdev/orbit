<?php

declare(strict_types=1);

use App\Actions\Firewall\StoreFirewallRuleAction;
use App\Data\Firewall\StoreFirewallRuleData;
use App\Domain\Firewall\FirewallAction;
use App\Domain\Firewall\FirewallProtectedPorts;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function store_node(): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2',
        'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
}

describe('StoreFirewallRuleAction', function (): void {
    it('records an allow that only repeats SSH access as skipped, with no rule and no run', function (): void {
        $node = store_node();

        $operation = app(StoreFirewallRuleAction::class)->execute($node, new StoreFirewallRuleData(
            action: FirewallAction::Allow, source: 'any', protocol: 'any', port: '22', label: null,
        ));

        expect($operation->status)->toBe(OperationStatus::Succeeded)
            ->and($operation->result)->toBe(['backend' => 'active', 'skipped' => 'firewall.rule_exists'])
            ->and($node->firewallRules()->count())->toBe(0);
    });

    it('still applies an allow on a range that goes beyond a protected port', function (): void {
        $node = store_node();
        $protected = app(FirewallProtectedPorts::class);

        expect($protected->repeats($node, '22', 'any'))->toBeTrue()
            ->and($protected->repeats($node, '22', '10.0.0.1'))->toBeTrue()
            ->and($protected->repeats($node, '20:30', 'any'))->toBeFalse()
            ->and($protected->repeats($node, '5432', 'any'))->toBeFalse();
    });

    it('keeps refusing a deny on a protected port', function (): void {
        $node = store_node();

        expect(fn () => app(StoreFirewallRuleAction::class)->execute($node, new StoreFirewallRuleData(
            action: FirewallAction::Deny, source: 'any', protocol: 'any', port: '22', label: null,
        )))->toThrow(ResourceOperationException::class);
    });
});
