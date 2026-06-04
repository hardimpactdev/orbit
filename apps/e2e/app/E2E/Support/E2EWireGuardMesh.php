<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RuntimeException;

final readonly class E2EWireGuardMesh
{
    public const array FIXED_KEYS = [
        'wg-easy' => [
            'private_key' => 'gADoQ5V3F4I2dB/8rTgANci1pm8TUdNXCWsmKBwsunA=',
            'public_key' => '1xH+B+7zwqZZkW9/iSlHGIzQwH81wNpf1PFhxqyJ3ls=',
        ],
        'gateway' => [
            'private_key' => 'MJmvfHlurBj4OTEw46bQv+AAFwRBODuDA9Rm4YYlAG4=',
            'public_key' => 'SQpu6tHDgr00hOBHprDUQw1sdgklQ8eSDVf+2n8qh0I=',
        ],
        'operator' => [
            'private_key' => 'yCln6NjLMSdRRDQEbZ/sBKv/WbwFXVJAaLd1YNywdUY=',
            'public_key' => 'KjOM+E7qg8RPtRSo6W26BURMQtOMkNiukQ4FdfMA/ic=',
        ],
        'dev' => [
            'private_key' => 'UHWrVrPjNEaq7pfUO9h0WXzpL2YeE9AUzaDK5c8Tnkg=',
            'public_key' => 'R+4OKkrVchK+WjCPup9gWvY4n6QuJ4Oksq7XVhn8kGo=',
        ],
        'prod' => [
            'private_key' => 'ANQAsS1qU2GVRFMDx1n/q7GvL2A8fOPHfreshpfHsE4=',
            'public_key' => '/lYf0ofa2OCH9WVm92ws3rRHQMp+EQKeYX9tymKIDHE=',
        ],
        'agent' => [
            'private_key' => 'aJT77NdCCE7cbV6U0i+nFx5mcUogS7Kduy0LvU5UaFc=',
            'public_key' => '/KO7fXMbJY1BFiL6yG2yvh/OlrFY8uEFW5R4NlGdUHQ=',
        ],
        'ingress' => [
            'private_key' => 'MFAmqpmR2BRHZRUCpdbGs7F815Z06+XKIZIW0T4X3lc=',
            'public_key' => 'SSJrFh5rrB8fIShlKYG4VhWfNyMPxlqA4g1x1eFyTFQ=',
        ],
        'websocket' => [
            'private_key' => '0EJNXFkinJ5tmbVZH1yS6pvxDZhGolb+6KlyN8mXY38=',
            'public_key' => 'yeHZ1tfpspgsnlbfvjeJrgzYwu6MFdNvgGeJNkaC0C4=',
        ],
    ];

    /**
     * @param  array<string, array{address: string, private_key: string, public_key: string, pre_shared_key: string}>  $peers
     */
    public function __construct(
        private string $gatewayProviderIp,
        private string $wgEasyPublicKey,
        private array $peers,
    ) {}

    public static function fixed(string $gatewayProviderIp): self
    {
        return self::standard(
            gatewayProviderIp: $gatewayProviderIp,
            wgEasyPublicKey: self::FIXED_KEYS['wg-easy']['public_key'],
            gatewayHostPrivateKey: self::FIXED_KEYS['gateway']['private_key'],
            gatewayHostPublicKey: self::FIXED_KEYS['gateway']['public_key'],
            operatorPrivateKey: self::FIXED_KEYS['operator']['private_key'],
            operatorPublicKey: self::FIXED_KEYS['operator']['public_key'],
            devPrivateKey: self::FIXED_KEYS['dev']['private_key'],
            devPublicKey: self::FIXED_KEYS['dev']['public_key'],
            prodPrivateKey: self::FIXED_KEYS['prod']['private_key'],
            prodPublicKey: self::FIXED_KEYS['prod']['public_key'],
            agentPrivateKey: self::FIXED_KEYS['agent']['private_key'],
            agentPublicKey: self::FIXED_KEYS['agent']['public_key'],
            ingressPrivateKey: self::FIXED_KEYS['ingress']['private_key'],
            ingressPublicKey: self::FIXED_KEYS['ingress']['public_key'],
            websocketPrivateKey: self::FIXED_KEYS['websocket']['private_key'],
            websocketPublicKey: self::FIXED_KEYS['websocket']['public_key'],
        );
    }

    public static function standard(
        string $gatewayProviderIp,
        string $wgEasyPublicKey,
        string $gatewayHostPrivateKey,
        string $gatewayHostPublicKey,
        string $operatorPrivateKey,
        string $operatorPublicKey,
        ?string $devPrivateKey = null,
        ?string $devPublicKey = null,
        ?string $prodPrivateKey = null,
        ?string $prodPublicKey = null,
        ?string $agentPrivateKey = null,
        ?string $agentPublicKey = null,
        ?string $ingressPrivateKey = null,
        ?string $ingressPublicKey = null,
        ?string $websocketPrivateKey = null,
        ?string $websocketPublicKey = null,
    ): self {
        $peers = [
            'gateway' => self::peerRecord('10.6.0.2', $gatewayHostPrivateKey, $gatewayHostPublicKey),
            'operator' => self::peerRecord('10.6.0.3', $operatorPrivateKey, $operatorPublicKey),
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

        if ($ingressPrivateKey !== null && $ingressPublicKey !== null) {
            $peers['ingress'] = self::peerRecord('10.6.0.7', $ingressPrivateKey, $ingressPublicKey);
        }

        if ($websocketPrivateKey !== null && $websocketPublicKey !== null) {
            $peers['websocket'] = self::peerRecord('10.6.0.8', $websocketPrivateKey, $websocketPublicKey);
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
command -v wg >/dev/null 2>&1 || { echo 'wg is missing from the prepared Incus artifact. Rebuild the base image and prepared topology.' >&2; exit 1; }
command -v wg-quick >/dev/null 2>&1 || { echo 'wg-quick is missing from the prepared Incus artifact. Rebuild the base image and prepared topology.' >&2; exit 1; }
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
        $checkCommands = [
            'set -euo pipefail',
            'ip link show wg-orbit >/dev/null',
            'wg show wg-orbit >/dev/null',
            sprintf('ip -o address show dev wg-orbit | grep -F %s', escapeshellarg($this->addressFor($role).'/24')),
        ];

        foreach ($peerRoles as $peerRole) {
            $checkCommands[] = sprintf('ping -c 1 -W 2 %s >/dev/null', escapeshellarg($this->addressFor($peerRole)));
        }

        $checks = $this->indentShell(implode("\n", $checkCommands));
        $script = implode("\n", [
            'set -uo pipefail',
            'deadline=$((SECONDS+60))',
            '',
            'while true; do',
            '    if (',
            $checks,
            '    ) >/tmp/orbit-wg-verify.log 2>&1; then',
            '        exit 0',
            '    fi',
            '',
            '    if [ "$SECONDS" -ge "$deadline" ]; then',
            '        cat /tmp/orbit-wg-verify.log >&2 || true',
            '        exit 1',
            '    fi',
            '',
            '    sleep 2',
            'done',
        ]);

        E2ECommand::exec(
            $instance,
            $script,
            "Could not verify wg-orbit on {$instance->name()}",
            timeoutSeconds: 75,
        );
    }

    private function indentShell(string $script): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => "        {$line}",
            explode("\n", $script),
        ));
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
