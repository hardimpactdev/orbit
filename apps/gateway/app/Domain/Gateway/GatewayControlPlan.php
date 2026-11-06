<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\Nodes\GatewayRuntimeFiles;
use App\Domain\Nodes\NodeWireGuardHubInput;
use App\Domain\Nodes\SwampNode;
use App\Domain\Nodes\SwampRoleUnits;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AppDev\VpnDnsmasqBackendListen;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\GatewayReleases\SwampGrantRenderer;

/**
 * What a new Gateway's own Swamp needs on its machine (docs/cli/tasks/vm-fleet-build.mmd, "Converge itself"): the serve and
 * worker units for the machine's checkout, and the grants of the release. No secret is in it: the tokens are minted on the
 * machine and stay there.
 */
final readonly class GatewayControlPlan
{
    public function __construct(private SwampGrantRenderer $grants) {}

    /**
     * @param  string  $checkout  The `swamp/` folder of the release on this machine, the Swamp repository serve reads.
     * @return array{user:string,node_id:int,repository:string,units:array{serve:string,worker:string},recorder:list<array{name:string,unit:string}>,grants:array<string,string>,hub:array{config:string,key_file:string,peer_directory:string}}
     */
    public function make(string $checkout): array
    {
        $node = SwampNode::node();

        return [
            'user' => $node->user,
            'node_id' => $node->id,
            // Where the Gateway reaches `swamp.orbit`: the published Route listens on the WireGuard address, not on loopback.
            'address' => $node->wireguard_ip,
            // The backend of the private DNS the Gateway serves as the hub: dnsmasq on loopback, beside systemd-resolved, as node.add writes it.
            'dns_backend' => VpnDnsmasqBackendListen::apply("# Managed by Orbit: private DNS backend.\n"),
            // The rules the machine starts its firewall with: the recovery SSH and the trust of the network members, from the catalog every Node
            // uses, and the port the hub listens on, which only an operator rule opens. The first role converge retires the recovery rule.
            'firewall' => ['hub_port' => app(VpnSettings::class)->port(),
                'rules' => array_map(static fn (UfwManagedRule $rule): array => ['ufw', ...array_slice($rule->arguments, 2)], app(NodeFirewallRuleCatalog::class)->forNode($node))],
            'repository' => $checkout,
            'units' => SwampRoleUnits::for($node->user, $node->id, $checkout),
            // The scheduler and Horizon that record finished runs: the Gateway's first run, its own Node converge, comes before its role.
            'recorder' => app(GatewayRuntimeFiles::class)->recorder($node),
            'grants' => $this->grants->render($checkout),
            // The hub of the Gateway's own private network. Its private key is made on the machine and stays there.
            'hub' => ['config' => NodeWireGuardHubInput::config($node), 'key_file' => NodeWireGuardHubInput::KeyFile,
                'peer_directory' => NodeWireGuardHubInput::PeerDirectory],
        ];
    }
}
