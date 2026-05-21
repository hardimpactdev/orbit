<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RuntimeException;

final readonly class E2EWireGuardMesh
{
    /**
     * @param  array<string, array{address: string, private_key: string, public_key: string, pre_shared_key: string}>  $peers
     */
    public function __construct(
        private string $gatewayProviderIp,
        private string $wgEasyPublicKey,
        private array $peers,
    ) {}

    public static function standard(
        string $gatewayProviderIp,
        string $wgEasyPublicKey,
        string $gatewayHostPrivateKey,
        string $gatewayHostPublicKey,
        string $controlPrivateKey,
        string $controlPublicKey,
        ?string $devPrivateKey = null,
        ?string $devPublicKey = null,
        ?string $prodPrivateKey = null,
        ?string $prodPublicKey = null,
        ?string $agentPrivateKey = null,
        ?string $agentPublicKey = null,
    ): self {
        $peers = [
            'gateway' => self::peerRecord('10.6.0.2', $gatewayHostPrivateKey, $gatewayHostPublicKey),
            'control' => self::peerRecord('10.6.0.3', $controlPrivateKey, $controlPublicKey),
        ];

        if ($devPrivateKey !== null && $devPublicKey !== null) {
            $peers['dev'] = self::peerRecord('10.6.0.4', $devPrivateKey, $devPublicKey);
        }

        if ($prodPrivateKey !== null && $prodPublicKey !== null) {
            $peers['prod'] = self::peerRecord('10.6.0.5', $prodPrivateKey, $prodPublicKey);
        }

        if ($agentPrivateKey !== null && $agentPublicKey !== null) {
            $peers['agent'] = self::peerRecord('10.6.0.6', $agentPrivateKey, $agentPublicKey);
        }

        return new self($gatewayProviderIp, $wgEasyPublicKey, $peers);
    }

    public function addressFor(string $role): string
    {
        return $this->peer($role)['address'];
    }

    public function gatewayHostConfig(): string
    {
        return $this->peerConfig('gateway');
    }

    public function peerConfig(string $role): string
    {
        $peer = $this->peer($role);

        return implode("\n", [
            '[Interface]',
            "PrivateKey = {$peer['private_key']}",
            "Address = {$peer['address']}/24",
            '',
            '[Peer]',
            "PublicKey = {$this->wgEasyPublicKey}",
            "PresharedKey = {$peer['pre_shared_key']}",
            'AllowedIPs = 10.6.0.0/24',
            "Endpoint = {$this->gatewayProviderIp}:51820",
            'PersistentKeepalive = 25',
            '',
        ]);
    }

    public function installRole(E2EInstance $instance, string $role): void
    {
        $config = $this->peerConfig($role);

        E2ECommand::exec(
            $instance,
            sprintf(
                <<<'SH'
set -euo pipefail
sudo apt-get -o DPkg::Lock::Timeout=300 update -qq >/dev/null
sudo DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -qq wireguard-tools >/dev/null
sudo install -d -m 0700 /etc/wireguard
cat <<'ORBIT_WG_CONFIG' | sudo tee /etc/wireguard/wg-orbit.conf >/dev/null
%s
ORBIT_WG_CONFIG
sudo chmod 0600 /etc/wireguard/wg-orbit.conf
sudo wg-quick down wg-orbit >/dev/null 2>&1 || true
sudo wg-quick up wg-orbit
sudo systemctl enable wg-quick@wg-orbit
SH,
                $config,
            ),
            "Could not install wg-orbit on {$instance->name()}",
            timeoutSeconds: 180,
        );
    }

    /**
     * @param  list<string>  $peerRoles
     */
    public function verifyRole(E2EInstance $instance, string $role, array $peerRoles): void
    {
        $commands = [
            'set -euo pipefail',
            'ip link show wg-orbit >/dev/null',
            'wg show wg-orbit >/dev/null',
            sprintf('ip -o address show dev wg-orbit | grep -F %s', escapeshellarg($this->addressFor($role).'/24')),
        ];

        foreach ($peerRoles as $peerRole) {
            $commands[] = sprintf('ping -c 1 -W 2 %s >/dev/null', escapeshellarg($this->addressFor($peerRole)));
        }

        E2ECommand::exec(
            $instance,
            implode("\n", $commands),
            "Could not verify wg-orbit on {$instance->name()}",
            timeoutSeconds: 60,
        );
    }

    /**
     * @return list<array{name: string, private_key: string, public_key: string, pre_shared_key: string, address: string}>
     */
    public function wgEasyPeers(): array
    {
        $records = [];

        foreach ($this->peers as $name => $peer) {
            $records[] = [
                'name' => $name,
                'private_key' => $peer['private_key'],
                'public_key' => $peer['public_key'],
                'pre_shared_key' => $peer['pre_shared_key'],
                'address' => $peer['address'],
            ];
        }

        return $records;
    }

    /**
     * @return array{address: string, private_key: string, public_key: string, pre_shared_key: string}
     */
    private function peer(string $role): array
    {
        return $this->peers[$role] ?? throw new RuntimeException("WireGuard role [{$role}] is not present in this mesh.");
    }

    /**
     * @return array{address: string, private_key: string, public_key: string, pre_shared_key: string}
     */
    private static function peerRecord(string $address, string $privateKey, string $publicKey): array
    {
        return [
            'address' => $address,
            'private_key' => $privateKey,
            'public_key' => $publicKey,
            'pre_shared_key' => self::preSharedKeyFor($publicKey),
        ];
    }

    private static function preSharedKeyFor(string $publicKey): string
    {
        return base64_encode(hash('sha256', "orbit-e2e-{$publicKey}", binary: true));
    }
}
