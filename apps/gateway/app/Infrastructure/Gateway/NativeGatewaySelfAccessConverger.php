<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Gateway\GatewaySelfAccessConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Throwable;

/** Authorize self-access through the agent, then retain the returned host pin in the control plane. */
final readonly class NativeGatewaySelfAccessConverger implements GatewaySelfAccessConverger
{
    public function __construct(
        private AgentCommandRunner $agents,
        private KnownHostsStore $knownHosts,
        private SshKeyProvider $sshKeys,
    ) {}

    public function converge(Node $node): string
    {
        try {
            $address = $node->wireguard_ip;
            if (! is_string($address) || $address === '') {
                throw new \RuntimeException('The Gateway has no WireGuard address.');
            }
            $result = $this->agents->run($node, 'gateway.converge-self-access', [
                'user' => $node->user,
                'public_key' => trim($this->sshKeys->publicKey()),
            ]);
            if (($result['key_type'] ?? null) !== 'ssh-ed25519'
                || ! is_string($result['value'] ?? null) || $result['value'] === ''
                || ! is_string($result['fingerprint'] ?? null)
                || preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/D', $result['fingerprint']) !== 1) {
                throw new \RuntimeException('The agent returned an invalid Gateway host identity.');
            }
            $this->knownHosts->put($address, 22, new HostKey($result['key_type'], $result['value'], $result['fingerprint']));

            return $result['fingerprint'];
        } catch (Throwable $exception) {
            throw new NodeProvisioningException('gateway-self-access', 'gateway.self_access_failed', 'The Gateway SSH self-access could not be prepared.', previous: $exception);
        }
    }
}
