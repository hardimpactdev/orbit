<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;

/** Immutable public enrollment input. It performs no SSH or private-material reads. */
final readonly class NodeEnrollmentInput
{
    public function __construct(private NodeEnrollmentToolInput $tools, private SwampSshExecutionInput $ssh,
        private AgentDelivery $observer, private NodeNetworkInput $network, private NodeWireGuardCommandInput $hub,
        private NodeRoleFirewallInput $firewall) {}

    /** @param list<RoleName> $roles
     * @return array<string,mixed>
     */
    public function capture(Node $actual, NodeEnrollmentCandidate $candidate, string $mode, string $bootstrapUser,
        string $fingerprint, ?string $expectedArchitecture, array $roles): array
    {
        if ($actual->id !== $candidate->nodeId || ! $actual->exists) {
            throw new \InvalidArgumentException('Enrollment capture requires the actual reserved Node.');
        }
        $identity = $this->ssh->identity();
        $artifacts = array_map(static fn (array $artifact): array => array_intersect_key($artifact, array_flip(['url', 'sha256'])), $this->observer->artifacts());
        $tools = $mode === 'linux' ? $this->tools->capture($candidate, $roles) : null;
        $packages = $tools === null ? [] : array_column($tools['apt']['packages'], 'name');

        return ['schema_version' => 1, 'node_id' => $actual->id, 'mode' => $mode,
            'candidate' => ['node_id' => $candidate->nodeId, 'name' => $candidate->name, 'user' => $candidate->user,
                'platform' => $candidate->platform, 'architecture' => $candidate->architecture,
                'wireguard_ip' => $candidate->wireguardIp, 'public_ssh_host' => $candidate->publicSshHost,
                'public_ssh_port' => $candidate->publicSshPort, 'cluster_id' => $candidate->clusterId, 'tld' => $candidate->tld,
                'lan_ip' => $candidate->lanIp, 'wireguard_endpoint_override' => $candidate->wireguardEndpointOverride,
                'dns_server_override' => $candidate->dnsServerOverride],
            'ssh_identity' => $identity,
            'bootstrap' => ['node_id' => $actual->id, 'platform' => $candidate->platform,
                'expected_architecture' => $expectedArchitecture,
                'target' => ['node_id' => $actual->id, 'host' => $candidate->publicSshHost, 'port' => $candidate->publicSshPort,
                    'user' => $bootstrapUser, 'fingerprint' => $fingerprint],
                'managed_user' => $candidate->user, 'address' => $candidate->wireguardIp, 'artifacts' => $artifacts,
                'command' => ['user' => $candidate->user, 'public_key' => $identity['public_key'],
                    'releases' => UbuntuRelease::supportedCodenames(), 'packages' => $packages, 'dns_policy' => 'preserve']],
            'tools' => $tools,
            'prior_ssh' => $mode === 'tld_only' ? $this->ssh->forNode($actual) : null,
            'bootstrap_firewall' => $mode === 'linux' ? $this->firewall->publicSsh($actual, true) : null,
            'prior_trust' => $mode === 'linux' ? $this->firewall->trust($actual) : null,
            'network' => $mode !== 'linux' ? null : $this->network->forAdmitted($actual, $candidate->wireguardIp,
                $candidate->wireguardEndpointOverride, $candidate->dnsServerOverride, $candidate->tld, $roles === [] && ! $actual->roles()->exists()),
            'hub' => $mode !== 'linux' ? null : $this->hub->capture($actual, $candidate->wireguardIp, $candidate->name),
        ];
    }
}
