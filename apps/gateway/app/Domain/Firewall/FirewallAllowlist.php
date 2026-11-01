<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Data\Firewall\StoreFirewallRuleData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Models\FirewallRule;
use App\Models\Node;

final readonly class FirewallAllowlist
{
    public function __construct(private NodeFirewallDesiredRules $managed, private VpnSettings $vpn, private NodeFirewallRuleCatalog $catalog) {}

    public function guard(Node $node, StoreFirewallRuleData $data): void
    {
        $ports = ['22', (string) $node->public_ssh_port, $this->vpn->port()];
        foreach ($this->managed->forNode($node) as $rule) {
            if ($rule->shape->port !== 'any') {
                $ports[] = $rule->shape->port;
            }
        }
        foreach ($node->roles()->get() as $assignment) {
            foreach ($this->catalog->ownedByRole($node, $assignment->role) as $rule) {
                if ($rule->shape->port !== 'any') {
                    $ports[] = $rule->shape->port;
                }
            }
        }
        foreach (array_unique($ports) as $port) {
            if (self::overlaps($port, $data->port)) {
                throw new ResourceOperationException('firewall.protected_port', 'The allowlist intersects an Orbit management port ('.$port.').');
            }
        }
    }

    public function guardOverlap(Node $node, StoreFirewallRuleData $data): void
    {
        foreach ($node->firewallRules()->where('name', '!=', $data->name)->get() as $rule) {
            if (($data->action === FirewallAction::Allowlist || $rule->action === FirewallAction::Allowlist)
                && $rule->protocol === $data->protocol && self::overlaps($rule->port, $data->port)) {
                throw new ResourceOperationException('firewall.allowlist_overlap', 'The allowlist overlaps firewall rule ['.$rule->name.'].', 409);
            }
        }
    }

    public static function overlaps(string $left, string $right): bool
    {
        $a = array_map(intval(...), explode(':', $left));
        $b = array_map(intval(...), explode(':', $right));

        return $a[0] <= ($b[1] ?? $b[0]) && $b[0] <= ($a[1] ?? $a[0]);
    }

    /** @return list<FirewallInspectionTarget> */
    public static function targets(FirewallRule $rule): array
    {
        $rule->loadMissing('node');
        $targets = [];
        $sources = [];
        foreach ($rule->sources as $source) {
            array_push($sources, ...($source === '0.0.0.0/0' ? ['0.0.0.0/1', '128.0.0.0/1'] : [$source]));
        }
        $sources = array_values(array_unique($sources));
        sort($sources);
        foreach ([...array_map(static fn (string $source): array => ['allow', $source], $sources), ['deny', 'any']] as [$action, $source]) {
            $targets[] = new FirewallInspectionTarget(
                $rule->node,
                new FirewallInspectionShape(
                    comment: "orbit:node:{$rule->node_id}:allowlist:{$rule->name}",
                    action: $action, direction: 'in', source: $source, destination: 'any',
                    port: $rule->port, protocol: $rule->protocol, inInterface: null, outInterface: null, family: 'v4',
                ),
                $rule->id, $rule->name,
            );
        }

        return $targets;
    }
}
