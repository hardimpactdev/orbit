<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Services\Firewall\IptablesRuleScript;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class GatewayDirectFirewallInstaller
{
    public function install(string $wireguardCidr = '10.6.0.0/24', string $wireguardInterface = 'wg-orbit'): void
    {
        $result = Process::timeout(120)
            ->input($this->script($wireguardCidr, $wireguardInterface))
            ->run('bash -s');

        if ($result->successful()) {
            return;
        }

        $message = trim($result->errorOutput().' '.$result->output());

        throw new RuntimeException(
            'Failed to apply gateway-direct firewall rules: '.($message !== '' ? $message : 'unknown error'),
        );
    }

    public function script(string $wireguardCidr = '10.6.0.0/24', string $wireguardInterface = 'wg-orbit'): string
    {
        $wireguardCidr = trim($wireguardCidr);
        $wireguardInterface = trim($wireguardInterface);

        if ($wireguardCidr === '' || $wireguardInterface === '') {
            throw new RuntimeException('Gateway-direct firewall requires a WireGuard CIDR and interface.');
        }

        $allowWireGuardTcp = IptablesRuleScript::ensurePrivilegedInserted(
            chain: 'DOCKER-USER',
            position: 1,
            ruleArguments: '-i "$WG_IFACE" -p tcp --dport 443 -j RETURN',
        );
        $allowWireGuardUdp = IptablesRuleScript::ensurePrivilegedInserted(
            chain: 'DOCKER-USER',
            position: 2,
            ruleArguments: '-i "$WG_IFACE" -p udp --dport 443 -j RETURN',
        );
        $allowWireGuardCidrTcp = IptablesRuleScript::ensurePrivilegedInserted(
            chain: 'DOCKER-USER',
            position: 3,
            ruleArguments: '-s "$WG_CIDR" -p tcp --dport 443 -j RETURN',
        );
        $allowWireGuardCidrUdp = IptablesRuleScript::ensurePrivilegedInserted(
            chain: 'DOCKER-USER',
            position: 4,
            ruleArguments: '-s "$WG_CIDR" -p udp --dport 443 -j RETURN',
        );
        $dropPublicTcp = IptablesRuleScript::ensurePrivilegedAppended(
            chain: 'DOCKER-USER',
            ruleArguments: '-p tcp --dport 443 -j DROP',
        );
        $dropPublicUdp = IptablesRuleScript::ensurePrivilegedAppended(
            chain: 'DOCKER-USER',
            ruleArguments: '-p udp --dport 443 -j DROP',
        );
        $dropPublicIpv6Tcp = IptablesRuleScript::ensurePrivilegedInserted(
            chain: 'DOCKER-USER',
            position: 1,
            ruleArguments: '-p tcp --dport 443 -j DROP',
            binary: 'ip6tables',
        );
        $dropPublicIpv6Udp = IptablesRuleScript::ensurePrivilegedInserted(
            chain: 'DOCKER-USER',
            position: 2,
            ruleArguments: '-p udp --dport 443 -j DROP',
            binary: 'ip6tables',
        );

        return <<<SH
            set -euo pipefail
            WG_IFACE={$this->quote($wireguardInterface)}
            WG_CIDR={$this->quote($wireguardCidr)}

            if ! command -v iptables >/dev/null 2>&1; then
                if command -v nft >/dev/null 2>&1; then
                    echo "unsupported Docker nftables firewall backend for gateway-direct exposure; Docker iptables firewall backend is required until nftables ingress rules are implemented" >&2
                else
                    echo "iptables is required for gateway-direct Docker ingress firewall rules" >&2
                fi
                exit 78
            fi

            if ! sudo iptables -w 5 -N DOCKER-USER >/dev/null 2>&1; then
                if ! sudo iptables -w 5 -S DOCKER-USER >/dev/null 2>&1; then
                    if command -v nft >/dev/null 2>&1; then
                        echo "unsupported Docker nftables firewall backend for gateway-direct exposure; Docker iptables firewall backend is required until nftables ingress rules are implemented" >&2
                    else
                        echo "failed to initialize Docker DOCKER-USER chain for gateway-direct exposure" >&2
                    fi
                    exit 78
                fi
            fi

            {$allowWireGuardTcp}
            {$allowWireGuardUdp}
            {$allowWireGuardCidrTcp}
            {$allowWireGuardCidrUdp}
            {$dropPublicTcp}
            {$dropPublicUdp}

            if command -v ip6tables >/dev/null 2>&1; then
                sudo ip6tables -w 5 -N DOCKER-USER >/dev/null 2>&1 || true
                {$dropPublicIpv6Tcp}
                {$dropPublicIpv6Udp}
            fi

            if command -v ufw >/dev/null 2>&1; then
                sudo ufw allow in on "\$WG_IFACE" proto tcp from "\$WG_CIDR" to any port 443 >/dev/null || true
                sudo ufw allow in on "\$WG_IFACE" proto udp from "\$WG_CIDR" to any port 443 >/dev/null || true
                sudo ufw deny in proto tcp from 0.0.0.0/0 to any port 443 >/dev/null || true
                sudo ufw deny in proto udp from 0.0.0.0/0 to any port 443 >/dev/null || true
            fi
            SH;
    }

    private function quote(string $value): string
    {
        return escapeshellarg($value);
    }
}
