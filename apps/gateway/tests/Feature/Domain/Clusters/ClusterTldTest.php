<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterTld;
use App\Domain\WireGuard\VpnSettings;

describe(ClusterTld::class, function (): void {
    it('accepts one lowercase DNS label', function (string $tld): void {
        expect(ClusterTld::isValid($tld))->toBeTrue();
    })->with(['test', ' Lab ', 'dev-1']);

    it('refuses a value that is not one DNS label', function (string $tld): void {
        expect(ClusterTld::isValid($tld))->toBeFalse();
    })->with(['', 'dev.orbit', 'not valid', '-dev']);

    it('refuses the private space', function (string $tld): void {
        expect(ClusterTld::isValid($tld))->toBeFalse();
    })->with(['orbit', ' ORBIT ']);

    it('refuses both the private space and the configured private VPN domain', function (): void {
        app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'corp');

        expect(ClusterTld::isValid('corp'))->toBeFalse()
            ->and(ClusterTld::isValid('orbit'))->toBeFalse()
            ->and(ClusterTld::isValid('test'))->toBeTrue();
    });
    it('refuses ancestors of the VPN domain without rejecting other suffixes', function (): void {
        app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'vpn.corp');

        expect(ClusterTld::isValid('corp'))->toBeFalse()
            ->and(ClusterTld::isValid('orp'))->toBeTrue()
            ->and(ClusterTld::isValid('test'))->toBeTrue();
    });

});
