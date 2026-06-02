<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyExecutionLaneContract;

it('separates source-dev and artifact-prod node bootstrap contracts', function (): void {
    $sourceDevEntrypointScript = E2ETopologyExecutionLaneContract::sourceDevEntrypointScript();
    $sourceDevDependencyHydrationScript = E2ETopologyExecutionLaneContract::sourceDevDependencyHydrationScript();
    $artifactProdGatewayOnlyScript = E2ETopologyExecutionLaneContract::artifactProdBootstrapScript(['gateway']);
    $artifactProdGatewayRouterScript = E2ETopologyExecutionLaneContract::artifactProdBootstrapScript(['gateway', 'router']);
    $artifactProdDatabaseScript = E2ETopologyExecutionLaneContract::artifactProdBootstrapScript(['database']);
    $artifactProdAppScript = E2ETopologyExecutionLaneContract::artifactProdBootstrapScript(['app-dev']);

    expect($sourceDevEntrypointScript)
        ->toContain('/home/orbit/orbit/apps/cli/orbit')
        ->not->toContain('orbit-binary');

    expect($sourceDevDependencyHydrationScript)
        ->toContain('apps/cli')
        ->toContain('composer')
        ->toContain('vendor/autoload.php');

    expect($artifactProdGatewayOnlyScript)
        ->toContain('orbit-binary')
        ->toContain('ORBIT_GATEWAY_IMAGE_ARCHIVE')
        ->toContain('docker load -i "${ORBIT_GATEWAY_IMAGE_ARCHIVE}"')
        ->toContain('orbit-gateway')
        ->not->toContain('/home/orbit/orbit/apps/cli/orbit')
        ->not->toContain('orbit-caddy')
        ->not->toContain('apt-get install php')
        ->not->toContain('apt install php')
        ->not->toContain('composer install');

    expect($artifactProdGatewayRouterScript)
        ->toContain('orbit-binary')
        ->toContain('ORBIT_GATEWAY_IMAGE_ARCHIVE')
        ->toContain('orbit-gateway')
        ->toContain('orbit-caddy')
        ->toContain('reverse_proxy http://orbit-gateway:8080')
        ->toContain('remote_ip')
        ->not->toContain('client_ip')
        ->not->toContain('published: 443')
        ->not->toContain('/home/orbit/orbit/apps/cli/orbit')
        ->not->toContain('apt-get install php')
        ->not->toContain('apt install php')
        ->not->toContain('composer install');

    expect($artifactProdDatabaseScript)
        ->toContain('orbit-binary')
        ->not->toContain('/home/orbit/orbit/apps/cli/orbit')
        ->not->toContain('apt-get install php')
        ->not->toContain('apt install php')
        ->not->toContain('composer install');

    expect($artifactProdAppScript)
        ->toContain('orbit-binary')
        ->toContain('apt-get install')
        ->toContain('php')
        ->toContain('composer')
        ->not->toContain('/home/orbit/orbit/apps/cli/orbit');
});

it('declares host PHP as an artifact-prod app-role-only prerequisite', function (): void {
    expect(E2ETopologyExecutionLaneContract::artifactProdRequiresHostPhp(['gateway']))->toBeFalse()
        ->and(E2ETopologyExecutionLaneContract::artifactProdRequiresHostPhp(['operator']))->toBeFalse()
        ->and(E2ETopologyExecutionLaneContract::artifactProdRequiresHostPhp(['database']))->toBeFalse()
        ->and(E2ETopologyExecutionLaneContract::artifactProdRequiresHostPhp(['websocket']))->toBeFalse()
        ->and(E2ETopologyExecutionLaneContract::artifactProdRequiresHostPhp(['s3']))->toBeFalse()
        ->and(E2ETopologyExecutionLaneContract::artifactProdRequiresHostPhp(['app-dev']))->toBeTrue()
        ->and(E2ETopologyExecutionLaneContract::artifactProdRequiresHostPhp(['app-prod']))->toBeTrue()
        ->and(E2ETopologyExecutionLaneContract::artifactProdRequiresHostPhp(['gateway', 'app-prod']))->toBeTrue();
});
