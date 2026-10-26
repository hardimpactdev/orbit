<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\WireGuard\WireGuardPeerDnsRepairer;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

/** Moves a managed peer's DNS selection to the policy through `wireguard.repair-peer-dns` (docs/reference/private-dns.md). */
final readonly class NativeWireGuardPeerDnsRepairer implements WireGuardPeerDnsRepairer
{
    public function __construct(
        private VpnConfigurationRepository $configuration,
        private AgentCommandRunner $agents,
    ) {}

    public function repair(Node $node): void
    {
        $policy = app(PeerDnsPolicy::class);
        if (! $policy->manages($node)) {
            throw new NodeProvisioningException('wireguard-peer-dns', 'vpn.peer_dns_unmanaged', "Node [{$node->name}] does not use the managed peer DNS policy.");
        }
        $vpn = $this->configuration->forPeer($node);

        try {
            $this->agents->run($node, 'wireguard.repair-peer-dns', [
                'dns_server' => $vpn->dnsServer,
                'public_key' => (string) $node->wireguard_public_key,
                'domains' => $policy->domains($node),
            ]);

            return;
        } catch (AgentCommandRefused $refused) {
            $code = $refused->errorCode;
            $result = AgentStep::output($refused);
            $previous = $refused;
        } catch (AgentRunnerFailure $failure) {
            $code = null;
            $result = $failure->result ?? new CommandResult(1, '', $failure->getMessage(), 0, false);
            $previous = $failure;
        }

        [$step, $errorCode, $message] = match ($code) {
            'wireguard.peer_dns_busy' => [
                'wireguard-peer-lock',
                'vpn.peer_dns_busy',
                "Another WireGuard peer operation is active on node [{$node->name}].",
            ],
            'wireguard.peer_recovery_pending' => [
                'wireguard-peer-transaction',
                'vpn.peer_recovery_pending',
                "WireGuard peer recovery is still pending for node [{$node->name}].",
            ],
            'wireguard.peer_dns_uplink_missing' => [
                'wireguard-peer-dns-uplink',
                'vpn.peer_dns_uplink_missing',
                "No usable non-Orbit DNS server is configured on node [{$node->name}].",
            ],
            'wireguard.peer_dns_state_unsupported' => [
                'wireguard-peer-state',
                'vpn.peer_dns_state_unsupported',
                "The existing WireGuard DNS state is not repairable on node [{$node->name}].",
            ],
            'wireguard.peer_dns_candidate_invalid' => [
                'wireguard-peer-dns-candidate',
                'vpn.peer_dns_candidate_invalid',
                "Could not validate the repaired DNS configuration on node [{$node->name}].",
            ],
            'wireguard.peer_dns_apply_failed' => [
                'wireguard-peer-dns-apply',
                'vpn.peer_dns_apply_failed',
                "Could not apply repaired DNS configuration on node [{$node->name}].",
            ],
            'wireguard.peer_dns_recovery_failed' => [
                'wireguard-peer-dns-recovery',
                'vpn.peer_dns_recovery_failed',
                "Could not restore the preceding DNS configuration on node [{$node->name}].",
            ],
            default => [
                'wireguard-peer-dns-repair',
                'vpn.peer_dns_repair_failed',
                "Could not repair DNS configuration on node [{$node->name}].",
            ],
        };

        throw new NodeProvisioningException(
            step: $step,
            errorCode: $errorCode,
            message: $message,
            previous: $previous,
            result: $result,
        );
    }
}
