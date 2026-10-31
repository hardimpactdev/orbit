<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Actions\Firewall\ListLiveFirewallRulesAction;
use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Routes\CustomProxyProcessListener;
use App\Domain\Routes\CustomProxyUpstream;
use App\Infrastructure\Firewall\DesiredFirewallRule;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Models\Node;
use App\Models\Route;

final readonly class PublicProcessRouteFirewall
{
    public function __construct(
        private ListLiveFirewallRulesAction $rules,
    ) {}

    public function matches(Route $route, Node $ingress): bool
    {
        $process = $route->customProxy?->process;
        $owner = $process?->owner;
        if ($process === null || ! $owner instanceof Node) {
            return false;
        }
        $upstream = new CustomProxyProcessListener()->resolvePublic($process, $ingress);
        $observed = $this->rules->execute($owner);
        if ($observed['backend_status'] !== 'active') {
            return false;
        }

        return array_any($observed['live'], fn (array $rule): bool => $rule['match'] === 'exact' && $this->allows($rule, $upstream, $owner, $ingress));
    }

    /** Pure managed intent. Swamp checks these exact shapes against the Process Node's fresh UFW snapshot.
     * @return array{node_id:int,expected:list<array<string,mixed>>}
     */
    public function expectation(Route $route, Node $ingress): array
    {
        $process = $route->customProxy?->process;
        $owner = $process?->owner;
        if ($process === null || ! $owner instanceof Node) {
            throw new \UnexpectedValueException('A public Process Route requires its recorded Node.');
        }
        $upstream = new CustomProxyProcessListener()->resolvePublic($process, $ingress);
        $shapes = array_map(static fn (DesiredFirewallRule $rule): UfwRuleShape => $rule->shape, app(NodeFirewallDesiredRules::class)->forNode($owner));
        foreach ($owner->firewallRules()->where('status', 'active')->get() as $rule) {
            $shape = FirewallInspectionTarget::fromRule($rule)->shape;
            $shapes[] = new UfwRuleShape($shape->comment, $shape->action, $shape->direction, $shape->source, $shape->destination, $shape->port, $shape->protocol, $shape->inInterface, $shape->outInterface, $shape->family);
        }
        $expected = [];
        foreach ($shapes as $shape) {
            $wire = $shape->toAgentInput();
            if (in_array($wire['family'], [null, 'v4'], true) && $this->allows([...$wire, 'interface' => $shape->inInterface], $upstream, $owner, $ingress)) {
                $expected[] = [...$wire, 'family' => 'v4'];
            }
        }

        return ['node_id' => $owner->id, 'expected' => $expected];
    }

    /** @param array<string,mixed> $rule */
    private function allows(array $rule, CustomProxyUpstream $upstream, Node $owner, Node $ingress): bool
    {
        if ($upstream->host === $owner->wireguard_ip) {
            return $rule['comment'] === 'orbit:wireguard-members';
        }

        return $rule['action'] === 'allow' && $rule['source'] === $ingress->lan_ip
            && in_array($rule['destination'], ['any', $upstream->host], true)
            && $rule['port'] === (string) $upstream->port && $rule['protocol'] === 'tcp'
            && $rule['interface'] === null;
    }
}
