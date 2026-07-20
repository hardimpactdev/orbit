<?php

declare(strict_types=1);

use App\Enums\Apps\AppRuntimeKind;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\RuntimeClientTrustPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('applies inner TLS only to opted-in app-dev PHP apps that are not production', function (): void {
    $policy = new AppDevelopmentInnerTlsPolicy;
    $appDevNode = createTestAppHostNode(['user' => 'nckrtl']);
    $appProdNode = createTestAppHostNode(attributes: ['user' => 'orbit'], role: 'app-prod');

    $appDevPhpDefault = Project::factory()->for($appDevNode, 'node')->create([
        'name' => 'docs',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $appDevPhpOptedIn = Project::factory()->for($appDevNode, 'node')->create([
        'name' => 'api',
        'runtime' => AppRuntimeKind::Php,
        'runtime_config' => ['proxy_transport' => 'https'],
    ]);
    $appProdPhp = Project::factory()->for($appProdNode, 'node')->create([
        'name' => 'docs-prod',
        'environment' => 'production',
        'runtime' => AppRuntimeKind::Php,
        'runtime_config' => ['proxy_transport' => 'https'],
    ]);
    $appDevStatic = Project::factory()
        ->for($appDevNode, 'node')
        ->static()
        ->create([
            'name' => 'marketing',
            'runtime_config' => ['proxy_transport' => 'https'],
        ]);

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
    $app = Project::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'runtime' => AppRuntimeKind::Php,
    ]);

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

    $appDevPhp = Project::factory()->for($appDevNode, 'node')->create([
        'name' => 'docs',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $appProdPhp = Project::factory()->for($appProdNode, 'node')->create([
        'name' => 'docs-prod',
        'environment' => 'production',
        'runtime' => AppRuntimeKind::Php,
    ]);

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
    $app = Project::factory()->for($node, 'node')->create([
        'name' => 'demo',
        'runtime' => AppRuntimeKind::Php,
        'runtime_config' => ['proxy_transport' => 'https'],
    ]);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature-a',
    ]);

    expect($policy->workspaceRouteDomain($workspace))
        ->toBe('feature-a.demo.test')
        ->and($policy->appliesToWorkspace($workspace))
        ->toBeTrue();
});
