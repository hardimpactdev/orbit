<?php

declare(strict_types=1);

use App\Services\Firewall\IptablesRuleScript;

it('renders an idempotent append with the bounded xtables lock wait', function (): void {
    $script = IptablesRuleScript::ensureAppended(
        chain: 'PREROUTING',
        ruleArguments: '-i wg0 -p udp --dport 53 -j DNAT --to-destination "${dns_ip}:53"',
        table: 'nat',
    );

    expect($script)->toBe(
        'iptables -w 5 -t nat -C PREROUTING -i wg0 -p udp --dport 53 -j DNAT --to-destination "${dns_ip}:53" >/dev/null 2>&1'
        .' || iptables -w 5 -t nat -A PREROUTING -i wg0 -p udp --dport 53 -j DNAT --to-destination "${dns_ip}:53"',
    );
});

it('renders an idempotent positioned insert for a privileged IPv6 rule', function (): void {
    $script = IptablesRuleScript::ensurePrivilegedInserted(
        chain: 'DOCKER-USER',
        position: 2,
        ruleArguments: '-p udp --dport 443 -j DROP',
        binary: 'ip6tables',
    );

    expect($script)->toBe(
        'sudo ip6tables -w 5 -C DOCKER-USER -p udp --dport 443 -j DROP >/dev/null 2>&1'
        .' || sudo ip6tables -w 5 -I DOCKER-USER 2 -p udp --dport 443 -j DROP',
    );
});

it('renders a presence check through the same bounded lock contract', function (): void {
    expect(IptablesRuleScript::assertPresent(
        chain: 'POSTROUTING',
        ruleArguments: '-p tcp -d "$dns_ip" --dport 53 -j MASQUERADE',
        table: 'nat',
    ))->toBe(
        'iptables -w 5 -t nat -C POSTROUTING -p tcp -d "$dns_ip" --dport 53 -j MASQUERADE',
    );
});
