<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use App\Services\Firewall\IptablesRuleScript;
use InvalidArgumentException;

final class VpnDnsForwardingScript
{
    public static function install(string $dnsService, string $wireguardInterface): string
    {
        $commands = [];

        foreach (self::rules($wireguardInterface) as $rule) {
            $commands[] = IptablesRuleScript::ensureAppended(
                chain: $rule['chain'],
                ruleArguments: $rule['arguments'],
                table: 'nat',
            );
        }

        return self::script($dnsService, $commands);
    }

    public static function probe(string $dnsService, string $wireguardInterface): string
    {
        $commands = [];

        foreach (self::rules($wireguardInterface) as $rule) {
            $commands[] = IptablesRuleScript::assertPresent(
                chain: $rule['chain'],
                ruleArguments: $rule['arguments'],
                table: 'nat',
            );
        }

        return self::script($dnsService, $commands);
    }

    /**
     * @return list<array{chain: string, arguments: string}>
     */
    private static function rules(string $wireguardInterface): array
    {
        if (preg_match('/\A[a-zA-Z0-9_.:-]+\z/', $wireguardInterface) !== 1) {
            throw new InvalidArgumentException('WireGuard interface contains unsupported characters.');
        }

        return [
            [
                'chain' => 'PREROUTING',
                'arguments' => "-i {$wireguardInterface} -p udp --dport 53 -j DNAT --to-destination \"\${dns_ip}:53\"",
            ],
            [
                'chain' => 'PREROUTING',
                'arguments' => "-i {$wireguardInterface} -p tcp --dport 53 -j DNAT --to-destination \"\${dns_ip}:53\"",
            ],
            [
                'chain' => 'POSTROUTING',
                'arguments' => '-p udp -d "$dns_ip" --dport 53 -j MASQUERADE',
            ],
            [
                'chain' => 'POSTROUTING',
                'arguments' => '-p tcp -d "$dns_ip" --dport 53 -j MASQUERADE',
            ],
        ];
    }

    /**
     * @param  list<string>  $commands
     */
    private static function script(string $dnsService, array $commands): string
    {
        $rules = implode("\n", $commands);

        return sprintf(
            <<<'SH'
                set -eu

                dns_ip="$(getent hosts %s | awk '{ print $1; exit }')"

                if [ -z "$dns_ip" ]; then
                    echo "Unable to resolve %s on the shared Swarm network" >&2
                    exit 1
                fi

                %s
                SH,
            escapeshellarg($dnsService),
            $dnsService,
            $rules,
        );
    }
}
