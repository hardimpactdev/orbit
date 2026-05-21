<?php

declare(strict_types=1);

use App\E2E\Support\E2ECommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EImage;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EProvisioningBundle;
use App\E2E\Support\E2ERun;
use App\E2E\Support\IncusProvider;
use App\E2E\Support\ProviderPool;

pest()->group('e2e-provision');

/**
 * @return array<string, mixed>
 */
function ingressProductionTopologyRoute(E2EInstance $gateway, string $domain): array
{
    $domainValue = var_export($domain, true);

    $route = E2ECommand::orbit(
        $gateway,
        'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg(<<<PHP
echo json_encode(app(\\App\\Services\\Proxy\\ProxyRouteQuery::class)
    ->toRouteEntity(\\App\\Models\\ProxyRoute::query()->where('domain', {$domainValue})->firstOrFail()), JSON_THROW_ON_ERROR);
PHP),
        "Could not read proxy route {$domain}",
    );

    return json_decode(trim($route->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

it('serves a production app on a colocated ingress node', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Blank);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'ingress-colocated');
    $bundle = null;
    $passed = false;

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionControlFromBlank($provider, $run, $bundle, $config, $key);
        [$gateway] = e2eProvisionGatewayThroughNodeNew($provider, $run, $config, $control, $key);
        [, $nodePayload] = e2eProvisionIngressAppThroughNodeNew(
            provider: $provider,
            run: $run,
            config: $config,
            control: $control,
            key: $key,
            name: 'web-1',
            roles: ['app-production', 'ingress'],
        );

        $appPayload = e2eCreateProductionApp($control, $config, $key, node: 'web-1', domain: 'docs.example.test');
        $route = ingressProductionTopologyRoute($gateway, 'docs.example.test');

        expect($nodePayload['success']['data']['roles'])
            ->sequence(
                fn ($role) => $role->role->toBe('ingress'),
                fn ($role) => $role->role->toBe('app-production'),
            )
            ->and($appPayload['success']['data']['app']['url'])->toBe('https://docs.example.test')
            ->and($route['node'])->toBe('web-1')
            ->and($route['placement'])->toBe('ingress')
            ->and($route['router'])->toMatchArray([
                'node' => 'gateway',
                'url' => 'http://10.6.0.2:80',
                'backend_pool' => [
                    ['node' => 'web-1', 'url' => 'http://10.6.0.4:80'],
                ],
            ]);

        e2eAssertDoctorHealthy($control, $config, $key, node: 'web-1', families: ['node', 'proxy']);

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});

it('serves a production app through a dedicated ingress node', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Blank);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'ingress-split');
    $bundle = null;
    $passed = false;

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionControlFromBlank($provider, $run, $bundle, $config, $key);
        [$gateway] = e2eProvisionGatewayThroughNodeNew($provider, $run, $config, $control, $key);
        e2eProvisionIngressAppThroughNodeNew(
            provider: $provider,
            run: $run,
            config: $config,
            control: $control,
            key: $key,
            name: 'edge-1',
            roles: ['ingress'],
        );
        e2eProvisionIngressAppThroughNodeNew(
            provider: $provider,
            run: $run,
            config: $config,
            control: $control,
            key: $key,
            name: 'web-1',
            roles: ['app-production'],
            ingress: 'edge-1',
        );

        $appPayload = e2eCreateProductionApp($control, $config, $key, node: 'web-1', domain: 'docs.example.test');
        $route = ingressProductionTopologyRoute($gateway, 'docs.example.test');

        expect($appPayload['success']['data']['app']['node'])->toBe('web-1')
            ->and($route['node'])->toBe('edge-1')
            ->and($route['placement'])->toBe('ingress')
            ->and($route['router'])->toMatchArray([
                'node' => 'gateway',
                'url' => 'http://10.6.0.2:80',
                'backend_pool' => [
                    ['node' => 'web-1', 'url' => 'http://10.6.0.5:80'],
                ],
            ]);

        e2eAssertDoctorHealthy($control, $config, $key, node: 'edge-1', families: ['node', 'proxy']);
        e2eAssertDoctorHealthy($control, $config, $key, node: 'web-1', families: ['node', 'proxy']);

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});
