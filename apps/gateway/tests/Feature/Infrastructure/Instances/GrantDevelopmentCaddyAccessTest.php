<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\Instances\NativeDevelopmentSourceAccess;

it('leaves out sites that have no checkout to grant access to', function (): void {
    $site = static fn (string $checkoutPath, ?string $localHttpUpstream = null, bool $unavailable = false, string $environment = 'development'): DevelopmentSite => new DevelopmentSite(
        nodeId: 1,
        nodeAddress: '10.44.0.7',
        scope: 'node-1',
        checkoutPath: $checkoutPath,
        documentRoot: 'public',
        phpVersion: '8.5',
        domain: 'example.test',
        localHttpUpstream: $localHttpUpstream,
        unavailable: $unavailable,
        environment: $environment,
    );

    $walked = NativeDevelopmentSourceAccess::walkedSites(collect([
        $site('/apps/served'),
        $site(''),
        $site('/apps/proxied', 'http://127.0.0.1:4788'),
        $site('/apps/unavailable', unavailable: true),
        $site('/apps/production', environment: 'production'),
    ]));

    expect($site('/apps/proxied', 'http://127.0.0.1:4788')->isProxy())->toBeTrue()
        ->and($walked->pluck('checkoutPath')->all())->toBe(['/apps/served']);
});
