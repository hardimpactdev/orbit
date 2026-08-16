<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\RuntimeClientTrustPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Attach an Orbit instance as the placement source for a policy fixture.
 * A null domain resolves to a development environment; a non-empty domain on a
 * non app-dev node resolves to production.
 */
function attachInnerTlsInstance(App $app, Node $node, ?string $domain = null): Instance
{
    return Instance::factory()->for($app, 'app')->create([
        'name' => $domain === null ? 'development' : 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: "/home/{$node->user}/apps/{$app->name}",
            document_root: 'public',
            domain: $domain,
        ),
    ]);
}

uses(TestCase::class);
uses(RefreshDatabase::class);

it('applies inner TLS only to opted-in app-dev PHP apps that are not production', function (): void {
    $policy = new AppDevelopmentInnerTlsPolicy;
    $appDevNode = createTestAppHostNode(['user' => 'nckrtl']);
    $appProdNode = createTestAppHostNode(attributes: ['user' => 'orbit'], role: 'app-prod');

    $appDevPhpDefault = App::factory()->create([
        'name' => 'docs',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $appDevPhpOptedIn = App::factory()->create([
        'name' => 'api',
        'runtime' => AppRuntimeKind::Php,
        'runtime_config' => ['proxy_transport' => 'https'],
    ]);
    $appProdPhp = App::factory()->create([
        'name' => 'docs-prod',
        'runtime' => AppRuntimeKind::Php,
        'runtime_config' => ['proxy_transport' => 'https'],
    ]);
    $appDevStatic = App::factory()
        ->static()
        ->create([
            'name' => 'marketing',
            'runtime_config' => ['proxy_transport' => 'https'],
        ]);

    attachInnerTlsInstance($appDevPhpDefault, $appDevNode);
    attachInnerTlsInstance($appDevPhpOptedIn, $appDevNode);
    attachInnerTlsInstance($appProdPhp, $appProdNode, 'docs-prod.example.com');
    attachInnerTlsInstance($appDevStatic, $appDevNode);

    expect($policy->appliesToApp($appDevPhpDefault))
        ->toBeFalse()
        ->and($policy->appliesToApp($appDevPhpOptedIn))
        ->toBeTrue()
        ->and($policy->appliesToApp($appProdPhp))
        ->toBeFalse()
        ->and($policy->appliesToApp($appDevStatic))
        ->toBeFalse();
});

it('describes runtime TLS mounts and upstream transport for an app-dev route domain', function (): void {
    $policy = new AppDevelopmentInnerTlsPolicy;
    $node = createTestAppHostNode(['user' => 'nckrtl', 'tld' => 'test']);
    $app = App::factory()->create([
        'name' => 'docs',
        'runtime' => AppRuntimeKind::Php,
    ]);
    attachInnerTlsInstance($app, $node);

    $domain = $policy->appRouteDomain($app);

    expect($domain)
        ->toBe('docs.test')
        ->and($policy->runtimeTlsEnvironment($domain))
        ->toBe([
            'SERVER_NAME' => 'https://docs.test:8443',
            'CADDY_SERVER_EXTRA_DIRECTIVES' => 'tls /etc/orbit/runtime-tls/tls.crt /etc/orbit/runtime-tls/tls.key',
        ])
        ->and($policy->runtimeTlsMounts($node, $domain))
        ->toBe([
            [
                'source' => '/home/nckrtl/.config/orbit/certs/docs.test.crt',
                'target' => '/etc/orbit/runtime-tls/tls.crt',
                'read_only' => true,
            ],
            [
                'source' => '/home/nckrtl/.config/orbit/certs/docs.test.key',
                'target' => '/etc/orbit/runtime-tls/tls.key',
                'read_only' => true,
            ],
        ])
        ->and($policy->runtimeUpstreamTlsConfig($node, $domain))
        ->toBe([
            'trusted_by_gateway_ca' => true,
            'ca_path' => '/etc/orbit/ca/root.crt',
            'server_name' => 'docs.test',
        ])
        ->and($policy->runtimeUpstreamTransportDirectives($policy->runtimeUpstreamTlsConfig($node, $domain)))
        ->toBe(<<<'CADDY'
                    transport http {
                        tls_trust_pool file /etc/orbit/ca/root.crt
                        tls_server_name docs.test
                    }

            CADDY);
});

it('describes runtime client trust for app-dev PHP apps that are not production', function (): void {
    $policy = new RuntimeClientTrustPolicy;
    $appDevNode = createTestAppHostNode(['user' => 'nckrtl']);
    $appProdNode = createTestAppHostNode(attributes: ['user' => 'orbit'], role: 'app-prod');

    $appDevPhp = App::factory()->create([
        'name' => 'docs',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $appProdPhp = App::factory()->create([
        'name' => 'docs-prod',
        'runtime' => AppRuntimeKind::Php,
    ]);

    attachInnerTlsInstance($appDevPhp, $appDevNode);
    attachInnerTlsInstance($appProdPhp, $appProdNode, 'docs-prod.example.com');

    expect($policy->mountsForApp($appDevPhp))
        ->toBe([[
            'source' => '/home/nckrtl/.config/orbit/ca/root.crt',
            'target' => '/etc/orbit/ca/root.crt',
            'read_only' => true,
        ]])
        ->and($policy->mountsForApp($appProdPhp))
        ->toBeEmpty()
        ->and($policy->phpIniForApp($appDevPhp))
        ->toBe([
            'openssl.cafile' => '/etc/orbit/ca/root.crt',
            'curl.cainfo' => '/etc/orbit/ca/root.crt',
        ])
        ->and($policy->environmentForApp($appDevPhp))
        ->toBe([
            'SSL_CERT_FILE' => '/etc/orbit/ca/root.crt',
            'CURL_CA_BUNDLE' => '/etc/orbit/ca/root.crt',
        ]);
});

it('describes workspace route domains and inherits the app-dev inner TLS policy from the parent app', function (): void {
    $policy = new AppDevelopmentInnerTlsPolicy;
    $node = createTestAppHostNode(['user' => 'orbit', 'tld' => 'test']);
    $app = App::factory()->create([
        'name' => 'demo',
        'runtime' => AppRuntimeKind::Php,
        'runtime_config' => ['proxy_transport' => 'https'],
    ]);
    attachInnerTlsInstance($app, $node);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature-a',
    ]);

    expect($policy->workspaceRouteDomain($workspace))
        ->toBe('feature-a.demo.test')
        ->and($policy->appliesToWorkspace($workspace))
        ->toBeTrue();
});

it('lets the workspace instance, not a stale app environment column, decide inner TLS', function (): void {
    $policy = new AppDevelopmentInnerTlsPolicy;
    $node = createTestAppHostNode(['user' => 'orbit', 'tld' => 'test']);
    // The development workspace instance is the placement authority, so inner
    // TLS must apply regardless of any other (e.g. production) app instance.
    $app = App::factory()->create([
        'name' => 'demo',
        'runtime' => AppRuntimeKind::Php,
        'runtime_config' => ['proxy_transport' => 'https'],
    ]);
    $devInstance = Instance::factory()->for($app, 'app')->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/demo',
            document_root: 'public',
        ),
    ]);
    $workspace = Workspace::factory()
        ->for($app, 'app')
        ->for($devInstance, 'instance')
        ->create([
            'name' => 'feature-a',
        ]);

    expect($policy->appliesToWorkspace($workspace))->toBeTrue();
});
