<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Domain\WireGuard\WireGuardEndpoint;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use Illuminate\Support\Facades\Config;

/** Captures public peer configuration only; the hub private key stays in its shared private source. */
final readonly class NodeNetworkInput
{
    public function __construct(private VpnSettings $vpn, private PeerDnsPolicy $dns, private NodeRoleFirewallInput $firewall) {}

    /** @return array<string,mixed> */
    public function capture(Node $node, bool $rolelessOperator = false): array
    {
        if (! is_string($node->wireguard_ip)) {
            throw $this->invalid();
        }

        return $this->forAdmitted($node, $node->wireguard_ip, $node->wireguard_endpoint_override,
            $node->dns_server_override, $node->tld, $rolelessOperator);
    }

    /** Public parent capture from admitted values; prior Node/key identity remains authoritative.
     * @return array<string,mixed>
     */
    public function forAdmitted(Node $node, string $address, ?string $endpointOverride, ?string $dnsOverride,
        ?string $tld, bool $rolelessOperator = false): array
    {
        $hub = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->sole();
        $subnet = Ipv4Subnet::from($this->vpn->subnet());
        $publicPath = rtrim(Config::string('orbit.home'), '/').'/wireguard/public.key';
        $publicKey = is_file($publicPath) ? trim((string) file_get_contents($publicPath)) : '';
        $endpoint = $endpointOverride ?? $this->vpn->endpoint() ?? WireGuardEndpoint::format($hub->public_ssh_host, (int) $this->vpn->port());
        if (preg_match('/\A[A-Za-z0-9+\/]{43}=\z/D', $publicKey) !== 1 || ! WireGuardEndpoint::isValid($endpoint)
            || ! $subnet->containsUsableAddress($address)
            || ! is_string($hub->wireguard_ip) || ! $subnet->containsUsableAddress($hub->wireguard_ip)) {
            throw $this->invalid();
        }
        $roleless = $rolelessOperator;
        $candidate = app(RouteRenderContext::class)->enrollment();
        $observerTarget = $candidate?->nodeId === $node->id ? $candidate : $node;
        $snapshot = ['schema_version' => 1, 'node_id' => $node->id, 'agent_sha256' => app(AgentDelivery::class)->pin($observerTarget), 'hub_id' => $hub->id, 'existing_key' => $node->wireguard_public_key, 'architecture' => $node->architecture,
            'peer' => ['server_public_key' => $publicKey, 'endpoint' => $endpoint, 'address' => $address.'/'.$subnet->prefixLength(),
                'subnet' => $subnet->value(), 'dns_server' => $this->dns->serverForOverride($dnsOverride, $hub->wireguard_ip),
                'domains' => $roleless ? [$this->vpn->domain()] : $this->dns->domainsForAdmittedNode($node, $tld),
                'operator_dns' => $roleless ? $hub->wireguard_ip : null, 'retain' => $node->wireguard_public_key !== null],
            'trust' => $this->firewall->trust($node, $address)];

        return $snapshot;
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('vpn.configuration_invalid', 'The public WireGuard peer configuration is invalid.', 422);
    }
}
