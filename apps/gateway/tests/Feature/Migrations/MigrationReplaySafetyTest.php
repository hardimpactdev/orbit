<?php

declare(strict_types=1);

use App\Models\LocalGatewaySettings;
use App\Services\Apps\AppProxyRouteRuntimeUpstreamBackfill;
use App\Services\Proxy\InstanceProxyRouteOwnershipResolver;

it('pins the AppProxyRouteRuntimeUpstreamBackfill surface the persist-ownership migration still calls', function (): void {
    expect(class_exists(AppProxyRouteRuntimeUpstreamBackfill::class))->toBeTrue();

    $method = new ReflectionMethod(AppProxyRouteRuntimeUpstreamBackfill::class, 'run');
    $connection = $method->getParameters()[0] ?? null;
    $connectionType = $connection?->getType();

    expect($method->getNumberOfParameters())
        ->toBe(1)
        ->and($connection?->getName())
        ->toBe('connection')
        ->and($connectionType)
        ->toBeInstanceOf(ReflectionNamedType::class)
        ->and($connectionType?->getName())
        ->toBe('string')
        ->and($connectionType?->allowsNull())
        ->toBeTrue()
        ->and($connection?->isDefaultValueAvailable())
        ->toBeTrue()
        ->and($connection?->getDefaultValue())
        ->toBeNull()
        ->and($method->getReturnType())
        ->toBeInstanceOf(ReflectionNamedType::class)
        ->and($method->getReturnType()?->getName())
        ->toBe('void');

    $source = persist_proxy_route_instance_ownership_migration_source();

    expect($source)
        ->toContain('use App\\Services\\Apps\\AppProxyRouteRuntimeUpstreamBackfill;')
        ->toContain('app(AppProxyRouteRuntimeUpstreamBackfill::class)->run(DB::getDefaultConnection())')
        ->not->toContain('use App\\Services\\Proxy\\InstanceProxyRouteOwnershipResolver;');
});

it('pins the InstanceProxyRouteOwnershipResolver methods copied into the persist-ownership migration', function (): void {
    expect(class_exists(InstanceProxyRouteOwnershipResolver::class))->toBeTrue();

    $expectedKind = new ReflectionMethod(InstanceProxyRouteOwnershipResolver::class, 'expectedKind');
    $isDirectOwner = new ReflectionMethod(InstanceProxyRouteOwnershipResolver::class, 'isDirectOwner');

    expect($expectedKind->isStatic())
        ->toBeTrue()
        ->and($expectedKind->getNumberOfParameters())
        ->toBe(1)
        ->and($expectedKind->getParameters()[0]->getName())
        ->toBe('ownerType')
        ->and($expectedKind->getReturnType()?->allowsNull())
        ->toBeTrue()
        ->and($isDirectOwner->isStatic())
        ->toBeTrue()
        ->and($isDirectOwner->getNumberOfParameters())
        ->toBe(1)
        ->and($isDirectOwner->getParameters()[0]->getName())
        ->toBe('ownerType')
        ->and(InstanceProxyRouteOwnershipResolver::expectedKind('app'))
        ->toBe('app')
        ->and(InstanceProxyRouteOwnershipResolver::expectedKind('workspace'))
        ->toBe('workspace')
        ->and(InstanceProxyRouteOwnershipResolver::expectedKind('app-analytics'))
        ->toBe('proxy')
        ->and(InstanceProxyRouteOwnershipResolver::expectedKind('app-websocket'))
        ->toBe('proxy')
        ->and(InstanceProxyRouteOwnershipResolver::expectedKind('unknown'))
        ->toBeNull()
        ->and(InstanceProxyRouteOwnershipResolver::isDirectOwner('app'))
        ->toBeTrue()
        ->and(InstanceProxyRouteOwnershipResolver::isDirectOwner('app-analytics'))
        ->toBeTrue()
        ->and(InstanceProxyRouteOwnershipResolver::isDirectOwner('app-websocket'))
        ->toBeTrue()
        ->and(InstanceProxyRouteOwnershipResolver::isDirectOwner('workspace'))
        ->toBeFalse();
});

it('freezes empty runtime requirements instead of importing InstanceRuntimeRequirementsData', function (): void {
    $source = (string) file_get_contents(database_path(
        'migrations/2026_08_16_120000_drop_app_placement_shadow_columns.php',
    ));

    expect($source)
        ->not
        ->toContain('use App\\Data\\Apps\\InstanceRuntimeRequirementsData;')
        ->toContain("'php_extensions' => []");
});

it('freezes the local gateway singleton key instead of importing LocalGatewaySettings', function (): void {
    $source = (string) file_get_contents(database_path(
        'migrations/2026_08_17_120000_enforce_singleton_local_gateway_settings.php',
    ));

    expect($source)
        ->not
        ->toContain('use App\\Models\\LocalGatewaySettings;')
        ->toContain("private const string SINGLETON_KEY = 'default'")
        ->and(LocalGatewaySettings::SINGLETON_KEY)
        ->toBe('default');
});

function persist_proxy_route_instance_ownership_migration_source(): string
{
    $paths = glob(database_path('migrations/*_persist_proxy_route_instance_ownership.php'));

    expect($paths)->toBeArray()->not->toBeEmpty();

    return (string) file_get_contents($paths[0]);
}
