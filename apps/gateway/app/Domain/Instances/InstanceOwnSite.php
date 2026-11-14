<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Models\Node;
use LogicException;

/**
 * A container's way to its own site (docs/reference/development-containers.md#the-own-site-from-inside). Caddy answers a clone's
 * own site on the Instance bridge's gateway address, on 443 and on the development listener, where the dev-port table sends the
 * development band before any input filter. So two host layers admit only those two ports in from the bridge: the bridge ACL, whose
 * egress is what containers send, and UFW's input. Caddy itself admits only the clone that the site belongs to. A container's NIC
 * ACL sees the port before the redirect: it admits 443 through the clone's access plan and the development band (`nicEgress`).
 */
final readonly class InstanceOwnSite
{
    /** Each port, with the comment of its UFW rule: a withdrawal deletes a rule by comment. */
    public const array Ports = [443 => 'orbit:instances-own-site', InstanceDevPorts::Listener => 'orbit:instances-own-dev'];

    /** The description of a clone's NIC ACL egress rule to its own site on 443; `env:sync` reconciles the rule by it. */
    public const string SiteDescription = 'Orbit own site';

    /** The description of a clone's NIC ACL egress rule to its own development ports; `env:sync` reconciles the rule by it. */
    public const string DevDescription = 'Orbit own development ports';

    /** @return array{acl: list<array<string, string>>, rules: list<array<string, mixed>>} */
    public static function for(string $gateway): array
    {
        return [
            'acl' => self::bridgeAcl($gateway),
            'rules' => array_map(static fn (UfwManagedRule $rule): array => $rule->shape->toAgentInput(), self::rules($gateway)),
        ];
    }

    /**
     * The bridge ACL's egress rows: TCP from the bridge to the gateway address, on each port.
     *
     * @return list<array<string, string>>
     */
    public static function bridgeAcl(string $gateway): array
    {
        return array_map(static fn (int $port): array => ['action' => 'allow', 'destination' => $gateway.'/32', 'protocol' => 'tcp',
            'destination_port' => (string) $port, 'state' => 'enabled'], array_keys(self::Ports));
    }

    /**
     * The egress row of a clone's own NIC ACL toward its own development ports: the development band of the dev-port table, the ports a
     * client names before the redirect. The ACL and the redirect take the band from one constant, so they cannot drift. The clone's
     * own site on 443 is a target of its access plan (InstanceContainer::ownSiteAccesses).
     *
     * @return array<string, string>
     */
    public static function nicEgress(Node $node): array
    {
        $gateway = InstanceHostIntent::gateway($node) ?? throw new LogicException("Node [{$node->name}] has no Instance bridge.");

        return ['action' => 'allow', 'destination' => $gateway.'/32', 'protocol' => 'tcp',
            'destination_port' => InstanceDevPorts::BridgeBand, 'state' => 'enabled', 'description' => self::DevDescription];
    }

    /**
     * TCP in on the Instance bridge to its gateway address, on each port.
     *
     * @return list<UfwManagedRule>
     */
    public static function rules(string $gateway): array
    {
        $bridge = InstanceHostIntent::Network;
        $rules = [];
        foreach (self::Ports as $port => $comment) {
            $rules[] = new UfwManagedRule(new UfwRuleShape($comment, 'allow', 'in', 'any', $gateway, (string) $port, 'tcp', $bridge, null, 'v4'),
                ['sudo', 'ufw', 'allow', 'in', 'on', $bridge, 'proto', 'tcp', 'from', 'any', 'to', $gateway, 'port', (string) $port, 'comment', $comment]);
        }

        return $rules;
    }
}
