<?php

declare(strict_types=1);

it('resolves app proxy routes only through their persisted instance relationship', function (): void {
    $proxyServicePath = app_path('Services/Proxy');
    $resolver = file_get_contents("{$proxyServicePath}/AppProxyRouteTargetResolver.php");

    expect($resolver)
        ->toBeString()
        ->toContain("loadMissing('instance.app')")
        ->not->toContain('ConfiguredInstance')
        ->not->toContain('DomainInstance')
        ->not->toContain('instances')->and("{$proxyServicePath}/AppProxyRouteConfiguredInstanceResolver.php")
        ->not->toBeFile()->and("{$proxyServicePath}/AppProxyRouteConfiguredInstanceSelector.php")
        ->not->toBeFile()->and("{$proxyServicePath}/AppProxyRouteDomainInstanceResolver.php")
        ->not->toBeFile();
});

it('does not query JSON config instance identities in proxy route write paths', function (): void {
    foreach ([
        app_path('Actions/Apps/EnsureAppProxyRoute.php'),
        app_path('Services/Analytics/AnalyticsRouteRegistrar.php'),
        app_path('Services/WebSockets/WebSocketRouteRegistrar.php'),
    ] as $path) {
        $source = file_get_contents($path);

        expect($source)
            ->toBeString()
            ->not->toContain('config->instance_id')
            ->not->toContain("data_get(\$route->config, 'instance_id')")
            ->not->toContain("data_get(\$existingRoute->config, 'instance_id')");
    }

    $appRouteWriter = file_get_contents(app_path('Actions/Apps/EnsureAppProxyRoute.php'));

    expect($appRouteWriter)
        ->toBeString()
        ->not->toContain('appPrimaryInstance')
        ->not->toContain('routeInstance(')
        ->not->toContain('instances->first');
});
