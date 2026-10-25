<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Ssh\SshConnection;
use App\Models\Node;
use Closure;
use Throwable;

final readonly class NativeWireGuardPeerConverger implements RecoverableWireGuardPeerConverger, WireGuardPeerConverger
{
    public function __construct(
        private VpnConfigurationRepository $configuration,
        private GatewayPeerProjectionManager $gatewayPeers,
        private AdmissionAgentRunner $agents,
    ) {}

    public function converge(Node $node, SshConnection $connection, bool $rolelessOperator = false): void
    {
        $vpn = $this->configuration->forPeer($node);
        $domains = $rolelessOperator ? [$vpn->domain] : app(PeerDnsPolicy::class)->domains($node);
        $publicKey = $this->preflightPeerKey($node, $connection, false);
        $this->publishPeer($node, $connection, $publicKey, false, $rolelessOperator, $vpn, $domains);
    }

    public function convergeRecoverably(Node $node, SshConnection $connection, Closure $completion, bool $rolelessOperator = false): void
    {
        $vpn = $this->configuration->forPeer($node);
        $domains = $rolelessOperator ? [$vpn->domain] : app(PeerDnsPolicy::class)->domains($node);
        $publicKey = $this->preflightPeerKey($node, $connection, true);
        // Publication restores its own failures. A lost result requires inspection, not another mutation.
        $this->publishPeer($node, $connection, $publicKey, true, $rolelessOperator, $vpn, $domains);
        $finalize = $connection;

        try {
            $completion(static function (SshConnection $verified) use (&$finalize): void {
                $finalize = $verified;
            });
            $this->run($node, $finalize, 'wireguard.commit-peer', ['public_key' => $publicKey]);
        } catch (Throwable $throwable) {
            $this->run($node, $finalize, 'wireguard.rollback-peer');

            throw $throwable;
        }
    }

    private function preflightPeerKey(Node $node, SshConnection $connection, bool $existing): string
    {
        $publicKey = $this->run($node, $connection, 'wireguard.prepare-peer-key', ['existing' => $existing])['public_key'] ?? null;

        if (! is_string($publicKey) || preg_match('/^[A-Za-z0-9+\/]{43}=$/D', $publicKey) !== 1) {
            throw new NodeProvisioningException(
                step: 'wireguard-peer-key',
                errorCode: 'vpn.peer_key_failed',
                message: "The agent returned no valid WireGuard public key for node [{$node->name}].",
            );
        }

        if ($existing && $node->wireguard_public_key !== $publicKey) {
            throw new NodeProvisioningException(
                step: 'wireguard-peer-key',
                errorCode: 'vpn.peer_key_mismatch',
                message: "The existing WireGuard key does not match node [{$node->name}].",
            );
        }

        return $publicKey;
    }

    /** @param non-empty-list<string> $domains */
    private function publishPeer(Node $node, SshConnection $connection, string $publicKey, bool $retain, bool $rolelessOperator, VpnConfiguration $vpn, array $domains): void
    {
        $this->configuration->assignPeerKey($node, $publicKey);
        $this->gatewayPeers->converge($node);
        $this->run($node, $connection, 'wireguard.publish-peer', [
            'server_public_key' => $vpn->serverPublicKey,
            'peer_public_key' => $publicKey,
            'endpoint' => $vpn->endpoint,
            'address' => $vpn->peerAddress,
            'subnet' => $vpn->subnet,
            'dns_server' => $vpn->dnsServer,
            'domains' => $domains,
            'operator_dns' => $rolelessOperator ? explode('/', $vpn->serverAddress, 2)[0] : null,
            'retain' => $retain,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Node $node, SshConnection $connection, string $command, array $input = []): array
    {
        [$step, $code] = match ($command) {
            'wireguard.prepare-peer-key' => ['wireguard-peer-key', 'vpn.peer_key_failed'],
            'wireguard.commit-peer' => ['wireguard-peer-commit', 'vpn.peer_commit_failed'],
            'wireguard.rollback-peer' => ['wireguard-rollback', 'vpn.peer_rollback_failed'],
            default => ['wireguard-peer-install', 'vpn.peer_config_failed'],
        };

        try {
            return $this->agents->runOver($node, $connection, $command, $input);
        } catch (AgentCommandRefused $refusal) {
            if ($command === 'wireguard.publish-peer' || $command === 'wireguard.prepare-peer-key') {
                [$step, $code] = match ($refusal->errorCode) {
                    'wireguard.peer_key_missing' => ['wireguard-peer-key', 'vpn.peer_key_missing'],
                    'wireguard.peer_recovery_pending' => ['wireguard-peer-transaction', 'vpn.peer_recovery_pending'],
                    'wireguard.peer_dns_uplink_missing' => ['wireguard-peer-dns-uplink', 'vpn.peer_dns_uplink_missing'],
                    'wireguard.peer_state_unsupported' => ['wireguard-peer-state', 'vpn.peer_state_unsupported'],
                    'wireguard.peer_rollback_failed' => ['wireguard-rollback', 'vpn.peer_rollback_failed'],
                    default => [$step, $code],
                };
            }

            throw new NodeProvisioningException($step, $code, "WireGuard peer step [{$step}] failed on node [{$node->name}].", previous: $refusal, result: AgentStep::output($refusal));
        } catch (AgentRunnerFailure $failure) {
            throw new NodeProvisioningException($step, $code, "WireGuard peer step [{$step}] could not finish on node [{$node->name}].", previous: $failure, result: $failure->result);
        }
    }
}
