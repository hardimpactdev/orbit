<?php

declare(strict_types=1);

use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Shared\ResourceOperationException;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostname;

describe(ReservedPrivateHostname::class, function (): void {
    it('reserves the platform private hostnames, then the collector hostname that ai-router contributes', function (): void {
        expect(app(ReservedPrivateHostname::class)->names())->toBe(['gateway.orbit', 'metrics.orbit', 'reverb.orbit', 'analytics.orbit', 'collector.ai-router.orbit']);
    });

    it('reserves the collector hostname and leaves the apex free', function (): void {
        expect(AiRouterHostname::Value)
            ->toBe('collector.ai-router.orbit')
            ->and(AiRouterHostname::Apex)
            ->toBe('ai-router.orbit')
            ->and(app(ReservedPrivateHostname::class)->names())
            ->toContain(AiRouterHostname::Value)
            ->not
            ->toContain(AiRouterHostname::Apex);

        app(ReservedPrivateHostname::class)->assertAvailable(AiRouterHostname::Apex);
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
        'ai-router collector' => [AiRouterHostname::Value],
    ]);

    it('allows other private hostnames', function (string $domain): void {
        app(ReservedPrivateHostname::class)->assertAvailable($domain);

        expect($domain)->not->toBeEmpty();
    })->with([
        'executor.orbit',
        'grafana.internal',
        'foo.bar',
        'something.test',
        'collector apex' => [AiRouterHostname::Apex],
        'retired collector name' => ['collector.cli-proxy-api.orbit'],
    ]);

});
