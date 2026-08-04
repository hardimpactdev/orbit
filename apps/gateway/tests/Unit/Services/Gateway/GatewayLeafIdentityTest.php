<?php

declare(strict_types=1);

use App\Services\Gateway\GatewayLeafIdentity;
use Tests\TestCase;

uses(TestCase::class);

describe('GatewayLeafIdentity', function (): void {
    it('defaults the browser Gateway hostname to gateway.orbit', function (): void {
        config()->set('orbit.gateway.hostname', null);

        expect(GatewayLeafIdentity::browserHostname())
            ->toBe(GatewayLeafIdentity::DefaultBrowserHostname)
            ->and(GatewayLeafIdentity::ShortHost)
            ->toBe('gateway');

        config()->set('orbit.gateway.hostname', '');

        expect(GatewayLeafIdentity::browserHostname())
            ->toBe(GatewayLeafIdentity::DefaultBrowserHostname);
    });

    it('reads the configured browser Gateway hostname', function (): void {
        config()->set('orbit.gateway.hostname', 'API.Orbit.Test');

        expect(GatewayLeafIdentity::browserHostname())->toBe('api.orbit.test');
    });

    it('builds short-host additional SANs with browser hostname and WireGuard IP', function (): void {
        config()->set('orbit.gateway.hostname', 'gateway.orbit');

        expect(GatewayLeafIdentity::additionalSansForShortHost('10.6.0.2'))
            ->toBe(['gateway.orbit', '10.6.0.2']);
    });

    it('builds WireGuard-IP additional SANs with short host and browser hostname', function (): void {
        config()->set('orbit.gateway.hostname', 'gateway.orbit');

        expect(GatewayLeafIdentity::additionalSansForWireguardIp())
            ->toBe(['gateway', 'gateway.orbit']);
    });

    it('rejects invalid browser Gateway hostnames', function (string $invalid): void {
        config()->set('orbit.gateway.hostname', $invalid);

        expect(fn () => GatewayLeafIdentity::browserHostname())
            ->toThrow(RuntimeException::class, 'Invalid browser Gateway hostname');
    })->with([
        'ipv4' => '10.6.0.2',
        'ipv6' => '::1',
        'empty label' => 'gateway..orbit',
        'leading dot' => '.gateway.orbit',
        'trailing dot' => 'gateway.orbit.',
        'label starts with hyphen' => '-gateway.orbit',
        'label ends with hyphen' => 'gateway-.orbit',
        'whitespace' => 'gateway .orbit',
        'scheme url' => 'https://gateway.orbit',
        'path form' => 'gateway.orbit/api',
        'port form' => 'gateway.orbit:443',
        'underscore label' => 'gateway_host.orbit',
    ]);
});
