<?php

declare(strict_types=1);

it('resolves app proxy routes only through their persisted instance relationship', function (): void {
    $proxyServicePath = dirname(__DIR__, 2).'/app/Services/Proxy';
    $targetResolver = file_get_contents("{$proxyServicePath}/AppProxyRouteTargetResolver.php");
    $ownershipResolver = file_get_contents("{$proxyServicePath}/InstanceProxyRouteOwnershipResolver.php");

    expect($targetResolver)
        ->toBeString()
        ->toContain('InstanceProxyRouteOwnershipResolver')
        ->toContain('return $this->ownership->resolve($route);')
        ->and($ownershipResolver)
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
        dirname(__DIR__, 2).'/app/Actions/Apps/EnsureAppProxyRoute.php',
        dirname(__DIR__, 2).'/app/Services/Analytics/AnalyticsRouteRegistrar.php',
        dirname(__DIR__, 2).'/app/Services/WebSockets/WebSocketRouteRegistrar.php',
    ] as $path) {
        $source = file_get_contents($path);

        expect($source)
            ->toBeString()
            ->not->toContain('config->instance_id')
            ->not->toContain("data_get(\$route->config, 'instance_id')")
            ->not->toContain("data_get(\$existingRoute->config, 'instance_id')");
    }

    $appRouteWriter = file_get_contents(dirname(__DIR__, 2).'/app/Actions/Apps/EnsureAppProxyRoute.php');

    expect($appRouteWriter)
        ->toBeString()
        ->not->toContain('appPrimaryInstance')
        ->not->toContain('routeInstance(')
        ->not->toContain('instances->first');
});

it('carries instance identity through analytics intent comparisons and transient hashes', function (): void {
    $payloadFactory = file_get_contents(dirname(__DIR__, 2).'/app/Services/Analytics/AppAnalyticsPayloadFactory.php');
    $registrar = file_get_contents(dirname(__DIR__, 2).'/app/Services/Analytics/AnalyticsRouteRegistrar.php');

    expect($payloadFactory)
        ->toBeString()
        ->toContain(
            "['node_id', 'app_id', 'workspace_id', 'instance_id', 'owner_type', 'kind', 'config', 'source_hash']",
        )
        ->and($registrar)
        ->toBeString()
        ->toContain("'instance_id' => \$instance->id,");
});
