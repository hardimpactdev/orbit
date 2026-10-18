<?php

declare(strict_types=1);

use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Shared\ResourceOperationException;

describe(ReservedPrivateHostname::class, function (): void {
    it('reserves the platform private hostnames, then the collector hostname that proxycli contributes', function (): void {
        expect(app(ReservedPrivateHostname::class)->names())->toBe(['gateway.orbit', 'metrics.orbit', 'reverb.orbit', 'analytics.orbit', 'collector.cli-proxy-api.orbit']);
    });

    it('reserves the collector hostname and leaves the apex free', function (): void {
        expect(ProxyCliHostname::Value)
            ->toBe('collector.cli-proxy-api.orbit')
            ->and(ProxyCliHostname::Apex)
            ->toBe('cli-proxy-api.orbit')
            ->and(app(ReservedPrivateHostname::class)->names())
            ->toContain(ProxyCliHostname::Value)
            ->not
            ->toContain(ProxyCliHostname::Apex);

        app(ReservedPrivateHostname::class)->assertAvailable(ProxyCliHostname::Apex);
    });

    it('refuses reserved private hostnames', function (string $domain): void {
        expect(fn () => app(ReservedPrivateHostname::class)->assertAvailable($domain))
            ->toThrow(function (ResourceOperationException $exception) use ($domain): void {
                expect($exception->errorCode)
                    ->toBe('route.domain_conflict')
                    ->and($exception->getMessage())
                    ->toBe("Route domain [{$domain}] is reserved.");
            });
    })->with([
        'gateway' => ['gateway.orbit'],
        'metrics' => ['metrics.orbit'],
        'reverb' => ['reverb.orbit'],
        'analytics' => ['analytics.orbit'],
        'proxycli collector' => [ProxyCliHostname::Value],
    ]);

    it('allows other private hostnames', function (string $domain): void {
        app(ReservedPrivateHostname::class)->assertAvailable($domain);

        expect($domain)->not->toBeEmpty();
    })->with([
        'executor.orbit',
        'grafana.internal',
        'foo.bar',
        'something.test',
        'collector apex' => [ProxyCliHostname::Apex],
        'retired collector name' => ['collector.proxycli.orbit'],
    ]);

});
