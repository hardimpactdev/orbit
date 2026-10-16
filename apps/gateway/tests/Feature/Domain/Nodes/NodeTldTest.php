<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeTld;
use App\Domain\WireGuard\VpnSettings;

describe(NodeTld::class, function (): void {
    it('accepts lowercase DNS labels, also under the private VPN domain', function (string $tld): void {
        expect(NodeTld::isValid($tld))->toBeTrue();
    })->with(['test', '.App-Dev.Orbit', 'app-dev.orbit']);

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
    it('refuses ancestors of the VPN domain without rejecting other suffixes', function (): void {
        app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'vpn.corp');

        expect(NodeTld::isValid('corp'))->toBeFalse()
            ->and(NodeTld::isValid('orp'))->toBeTrue()
            ->and(NodeTld::isValid('test'))->toBeTrue();
    });

});
