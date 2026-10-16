<?php

declare(strict_types=1);

use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\Routes\ContributedReservedHostnames;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Shared\ResourceOperationException;

final class ReservedPrivateHostnameTestTakesCore implements ContributedReservedHostnames
{
    public function hostnames(): array
    {
        return ['reverb.orbit'];
    }
}

final class ReservedPrivateHostnameTestTakesCollector implements ContributedReservedHostnames
{
    public function hostnames(): array
    {
        return [ProxyCliHostname::Value];
    }
}

describe(ReservedPrivateHostname::class, function (): void {
    it('reserves the platform private hostnames, then the collector hostname that proxycli contributes', function (): void {
        expect(ReservedPrivateHostname::names())->toBe(['gateway.orbit', 'metrics.orbit', 'reverb.orbit', 'analytics.orbit', 'collector.cli-proxy-api.orbit'])
            ->and(ReservedPrivateHostname::contributed())->toBe([ProxyCliHostname::Value]);
    });

    it('reserves the collector hostname and leaves the apex free', function (): void {
        expect(ProxyCliHostname::Value)
            ->toBe('collector.cli-proxy-api.orbit')
            ->and(ProxyCliHostname::Apex)
            ->toBe('cli-proxy-api.orbit')
            ->and(ReservedPrivateHostname::names())
            ->toContain(ProxyCliHostname::Value)
            ->not
            ->toContain(ProxyCliHostname::Apex);

        ReservedPrivateHostname::assertAvailable(ProxyCliHostname::Apex);
    });

    it('refuses reserved private hostnames', function (string $domain): void {
        expect(fn () => ReservedPrivateHostname::assertAvailable($domain))
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
        ReservedPrivateHostname::assertAvailable($domain);

        expect($domain)->not->toBeEmpty();
    })->with([
        'executor.orbit',
        'grafana.internal',
        'foo.bar',
        'something.test',
        'collector apex' => [ProxyCliHostname::Apex],
        'retired collector name' => ['collector.proxycli.orbit'],
    ]);

    it('refuses a contributed hostname that core or another contribution already reserves', function (string $contribution, string $hostname): void {
        app()->tag([$contribution], ContributedReservedHostnames::class);

        expect(fn (): array => ReservedPrivateHostname::names())
            ->toThrow(LogicException::class, "The contribution [{$contribution}] reserves the hostname [{$hostname}], which is already reserved.");
    })->with([
        'a core hostname' => [ReservedPrivateHostnameTestTakesCore::class, 'reverb.orbit'],
        'another contribution' => [ReservedPrivateHostnameTestTakesCollector::class, 'collector.cli-proxy-api.orbit'],
    ]);
});
