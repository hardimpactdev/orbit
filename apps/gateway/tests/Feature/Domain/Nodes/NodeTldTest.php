<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeTld;
use App\Domain\WireGuard\VpnSettings;

describe(NodeTld::class, function (): void {
    it('accepts lowercase DNS labels outside private zones', function (string $tld): void {
        expect(NodeTld::isValid($tld))->toBeTrue();
    })->with(['test', '.App-Dev.Test', 'app-dev.test']);

    it('refuses a value that is not DNS labels', function (string $tld): void {
        expect(NodeTld::isValid($tld))->toBeFalse();
    })->with(['', 'not valid', 'dev..orbit']);

    it('refuses the private space', function (string $tld): void {
        expect(NodeTld::isValid($tld))->toBeFalse();
    })->with(['orbit', '.ORBIT', ' orbit ']);

    it('refuses both the private space and the configured private VPN domain', function (): void {
        app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'corp');

        expect(NodeTld::isValid('corp'))->toBeFalse()
            ->and(NodeTld::isValid('orbit'))->toBeFalse()
            ->and(NodeTld::isValid('test'))->toBeTrue();
    });

    it('refuses private zone overlap without rejecting unrelated suffixes', function (string $domain): void {
        app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: $domain);

        expect(NodeTld::isValid('corp'))->toBeFalse()
            ->and(NodeTld::isValid('VPN.CORP'))->toBeFalse()
            ->and(NodeTld::isValid('n1.vpn.corp'))->toBeFalse()
            ->and(NodeTld::isValid('beast.orbit'))->toBeFalse()
            ->and(NodeTld::isValid('dev.corp'))->toBeTrue()
            ->and(NodeTld::isValid('orp'))->toBeTrue()
            ->and(NodeTld::isValid('test'))->toBeTrue()
            ->and(NodeTld::isValid('notorbit'))->toBeTrue()
            ->and(NodeTld::isValid('notvpn.corp'))->toBeTrue()
            ->and(NodeTld::isValid('vpn.corp.test'))->toBeTrue();
    })->with(['vpn.corp', 'VPN.CORP', 'vpn.corp.', 'VPN.CORP.']);

    it('refuses multi-label ancestors of a rooted VPN domain', function (): void {
        app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'deep.vpn.corp.');

        expect(NodeTld::isValid('vpn.corp'))->toBeFalse()
            ->and(NodeTld::isValid('corp'))->toBeFalse()
            ->and(NodeTld::isValid('deep.vpn.corp'))->toBeFalse()
            ->and(NodeTld::isValid('n1.deep.vpn.corp'))->toBeFalse()
            ->and(NodeTld::isValid('n1.vpn.corp'))->toBeTrue();
    });
});
