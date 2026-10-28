<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Domain\WireGuard\WireGuardEndpoint;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Config;

/** Captures public peer configuration only; the hub private key stays in its Kit A source. */
final readonly class PrepareNodeNetworkCommand implements OperationGatewayCommand
{
    public function __construct(private VpnSettings $vpn, private PeerDnsPolicy $dns, private NodeRoleFirewallInput $firewall, private OperationStore $store) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['roleless_operator'],
            'properties' => ['roleless_operator' => ['type' => 'boolean']]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if (($operation->state['node_network_prepared'] ?? false) === true) {
            return ['prepared' => true];
        }
        if (($input['roleless_operator'] ?? null) !== ($operation->input['roleless_operator'] ?? null)) {
            throw $this->invalid();
        }
        $node = Node::query()->findOrFail($operation->node_id);
        $hub = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->sole();
        $subnet = Ipv4Subnet::from($this->vpn->subnet());
        $publicPath = rtrim(Config::string('orbit.home'), '/').'/wireguard/public.key';
        $publicKey = is_file($publicPath) ? trim((string) file_get_contents($publicPath)) : '';
        $endpoint = $node->wireguard_endpoint_override ?? $this->vpn->endpoint() ?? WireGuardEndpoint::format($hub->public_ssh_host, (int) $this->vpn->port());
        if (preg_match('/\A[A-Za-z0-9+\/]{43}=\z/D', $publicKey) !== 1 || ! WireGuardEndpoint::isValid($endpoint)
            || ! is_string($node->wireguard_ip) || ! $subnet->containsUsableAddress($node->wireguard_ip)
            || ! is_string($hub->wireguard_ip) || ! $subnet->containsUsableAddress($hub->wireguard_ip)) {
            throw $this->invalid();
        }
        $roleless = $input['roleless_operator'] === true;
        $snapshot = ['hub_id' => $hub->id, 'existing_key' => $node->wireguard_public_key, 'architecture' => $node->architecture,
            'peer' => ['server_public_key' => $publicKey, 'endpoint' => $endpoint, 'address' => $node->wireguard_ip.'/'.$subnet->prefixLength(),
                'subnet' => $subnet->value(), 'dns_server' => $this->dns->server($node, $hub->wireguard_ip),
                'domains' => $roleless ? [$this->vpn->domain()] : $this->dns->domains($node),
                'operator_dns' => $roleless ? $hub->wireguard_ip : null, 'retain' => $node->wireguard_public_key !== null],
            'trust' => $this->firewall->trust($node)];
        $child = ['key' => 'network-hub', 'kind' => 'node.wireguard_hub_converge', 'node_id' => $hub->id,
            'input' => ['context_node_id' => $node->id, 'excluded_node_id' => null]];
        $this->store->change($operation, ['input' => [...$operation->input, '_network' => $snapshot],
            'state' => [...($operation->state ?? []), 'node_network_prepared' => true, 'child_intents' => [$child]]]);

        return ['prepared' => true];
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('vpn.configuration_invalid', 'The public WireGuard peer configuration is invalid.', 422);
    }
}
