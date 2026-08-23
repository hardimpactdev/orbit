<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\RemoteShell;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Firewall\FirewallRuleShapeCanonicalizer;

final class PublicSshDenyInstaller implements SecurityInstaller
{
    private const string WireGuardAllowName = 'orbit-wireguard-ssh-allow-v4';

    private const string WireGuardAllowReason = 'Orbit node security baseline permits SSH only through WireGuard.';

    private const string PublicDenyReason = 'Orbit node security baseline denies public SSH after bootstrap.';

    public function installFor(Node $node, ?RemoteShell $provisioningShell = null): InstallReport
    {
        $this->declareRules($node);

        $result = app(SecurityInstallerTransport::class)->run($node, $provisioningShell, $this->script(), 120);

        return new InstallReport(
            successful: $result->successful(),
            summary: $result->successful()
                ? 'Denied public SSH ingress.'
                : 'Failed to deny public SSH ingress.',
            details: [
                'exit_code' => $result->exitCode,
            ],
        );
    }

    public function declareRules(Node $node): void
    {
        FirewallRule::query()->updateOrCreate(
            [
                'node_id' => $node->id,
                'name' => self::WireGuardAllowName,
            ],
            [
                'direction' => 'incoming',
                'action' => 'allow',
                'source' => '10.6.0.0/24',
                'destination' => null,
                'port' => '22',
                'protocol' => 'tcp',
                'reason' => self::WireGuardAllowReason,
                'source_hash' => hash('sha256', "{$node->id}:wireguard-ssh-allow:v4"),
                'address_family' => 'v4',
                'interface' => 'wireguard',
                'owner' => 'node-security',
            ],
        );

        foreach (['v4' => '0.0.0.0/0', 'v6' => '::/0'] as $family => $source) {
            FirewallRule::query()->updateOrCreate(
                [
                    'node_id' => $node->id,
                    'name' => "orbit-public-ssh-deny-{$family}",
                ],
                [
                    'direction' => 'incoming',
                    'action' => 'deny',
                    'source' => $source,
                    'destination' => null,
                    'port' => '22',
                    'protocol' => 'tcp',
                    'reason' => self::PublicDenyReason,
                    'source_hash' => hash('sha256', "{$node->id}:public-ssh-deny:{$family}"),
                    'address_family' => $family,
                    'interface' => 'public',
                    'owner' => 'node-security',
                ],
            );
        }
    }

    public function script(): string
    {
        $wireGuardComment = escapeshellarg(FirewallRuleShapeCanonicalizer::managedComment(
            self::WireGuardAllowReason,
            self::WireGuardAllowName,
        ) ?? self::WireGuardAllowReason);
        $publicDenyV4Comment = escapeshellarg(FirewallRuleShapeCanonicalizer::managedComment(
            self::PublicDenyReason,
            'orbit-public-ssh-deny-v4',
        ) ?? self::PublicDenyReason);
        $publicDenyV6Comment = escapeshellarg(FirewallRuleShapeCanonicalizer::managedComment(
            self::PublicDenyReason,
            'orbit-public-ssh-deny-v6',
        ) ?? self::PublicDenyReason);

        return <<<SH_WRAP
            set -euo pipefail
            if ! command -v ufw >/dev/null 2>&1; then
                sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
                sudo DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -qq ufw
            fi
            UFW_INACTIVE=0
            if sudo ufw status 2>/dev/null | grep -qi '^Status: inactive'; then
                UFW_INACTIVE=1
            fi
            WG_IFACE=""
            if ip -o link show wg-orbit >/dev/null 2>&1; then
                WG_IFACE="wg-orbit"
            else
                WG_IFACE="\$(ip -o link show type wireguard 2>/dev/null | awk -F': ' '{print \$2; exit}')"
            fi
            if [ -n "\$WG_IFACE" ]; then
                sudo ufw allow in on "\$WG_IFACE" proto tcp from 10.6.0.0/24 to any port 22 comment {$wireGuardComment} >/dev/null
            else
                echo "Could not resolve WireGuard interface." >&2
                exit 1
            fi
            PUBLIC_IFACE="\$(ip route show default 0.0.0.0/0 2>/dev/null | awk '{print \$5; exit}')"
            if [ -z "\$PUBLIC_IFACE" ]; then
                PUBLIC_IFACE="\$(ip -o -4 route show to default 2>/dev/null | awk '{print \$5; exit}')"
            fi
            if [ -z "\$PUBLIC_IFACE" ]; then
                echo "Could not resolve public network interface." >&2
                exit 1
            fi
            sudo ufw deny in on "\$PUBLIC_IFACE" proto tcp from 0.0.0.0/0 to any port 22 comment {$publicDenyV4Comment} >/dev/null
            if [ -e /proc/sys/net/ipv6/conf/all/disable_ipv6 ] && [ "\$(cat /proc/sys/net/ipv6/conf/all/disable_ipv6)" = "0" ]; then
                sudo ufw deny in on "\$PUBLIC_IFACE" proto tcp from ::/0 to any port 22 comment {$publicDenyV6Comment} >/dev/null || true
            fi
            if [ "\$UFW_INACTIVE" = "1" ]; then
                echo "UFW is inactive; public SSH deny rules were staged but UFW was not enabled." >&2
            fi
            SH_WRAP;
    }
}
