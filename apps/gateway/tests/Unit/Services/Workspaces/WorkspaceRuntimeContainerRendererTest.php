<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function makePhpWorkspace(array $appOverrides = [], array $workspaceOverrides = []): Workspace
{
    $node = createTestAppHostNode(['user' => 'orbit']);

    /** @var array<string, mixed> $appAttributes */
    $appAttributes = array_merge([
        'name' => 'demo',
        'path' => '/home/orbit/apps/demo',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ], $appOverrides);

    $app = makeWorkspaceRendererApp($node, $appAttributes);

    /** @var array<string, mixed> $workspaceAttributes */
    $workspaceAttributes = array_merge([
        'name' => 'feature-a',
        'path' => '/home/orbit/apps/demo/.worktrees/feature-a',
        'php_version' => null,
    ], $workspaceOverrides);

    $workspace = makeWorkspaceRendererWorkspace($app, $workspaceAttributes);

    $workspace->setRelation('app', $app);

    return $workspace;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeWorkspaceRendererApp(Node $node, array $attributes): App
{
    $app = App::factory()->for($node, 'node')->create($attributes);
    assert($app instanceof App, description: 'Workspace factory must return an App model.');

    return $app;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeWorkspaceRendererWorkspace(App $app, array $attributes): Workspace
{
    $workspace = Workspace::factory()->for($app, 'app')->create($attributes);
    assert($workspace instanceof Workspace, description: 'Workspace factory must return a Workspace model.');

    return $workspace;
}

function workspaceRendererForTest(): WorkspaceRuntimeContainerRenderer
{
    return new WorkspaceRuntimeContainerRenderer(
        new PhpRuntimePolicy(new PhpRuntimeCatalog),
        new OrbitContainerNames,
    );
}

it(
    'renders a FrankenPHP workspace runtime container for a PHP workspace with deterministic name, image, network, and source mount',
    function (): void {
        $workspace = makePhpWorkspace();

        $container = workspaceRendererForTest()->render($workspace);
        $mountTargets = array_column($container->mounts(), 'target');

        expect($container->name())
            ->toBe('orbit-ws-demo-feature-a')
            ->and($container->image())
            ->toBe('ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm')
            ->and($container->network())
            ->toBe('orbit-network')
            ->and($container->restartPolicy())
            ->toBe('unless-stopped')
            ->and($container->networkAliases())
            ->toContain('orbit-ws-demo-feature-a')
            ->and($container->networkAliases())
            ->toContain('ws-demo-feature-a')
            ->and($container->mounts())
            ->toContain([
                'source' => '/home/orbit/apps/demo/.worktrees/feature-a',
                'target' => '/app',
                'read_only' => false,
            ])
            ->and($container->mounts())
            ->toContain([
                'source' => '/home/orbit/apps/demo/.worktrees/feature-a',
                'target' => '/home/orbit/apps/demo/.worktrees/feature-a',
                'read_only' => false,
            ])
            ->and($mountTargets)
            ->not->toContain('/data')->and($mountTargets)
            ->not->toContain('/config')->and($container->environment())->toMatchArray([
                'XDG_CONFIG_HOME' => '/tmp/orbit-frankenphp/config',
                'XDG_DATA_HOME' => '/tmp/orbit-frankenphp/data',
            ]);
    },
);

it('mounts the owning app-dev node user packages directory at /packages', function (): void {
    $node = createTestAppHostNode(['user' => 'nckrtl']);
    $app = makeWorkspaceRendererApp($node, [
        'name' => 'nckrtl',
        'path' => '/home/nckrtl/apps/nckrtl',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $workspace = makeWorkspaceRendererWorkspace($app, [
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/nckrtl/.worktrees/feature-a',
        'php_version' => null,
    ]);

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->mounts())->toContain([
        'source' => '/home/nckrtl/packages',
        'target' => '/packages',
        'read_only' => false,
    ]);
});

it('uses the selected app instance node for workspace runtime node-local mounts', function (): void {
    $canonicalNode = createTestAppHostNode([
        'name' => 'beast',
        'platform' => 'ubuntu_24-04',
        'user' => 'nckrtl',
    ]);
    $instanceNode = createTestAppHostNode(
        attributes: [
            'name' => 'NMBP',
            'platform' => 'macos_26-5-1',
            'user' => 'nckrtl',
        ],
        settings: ['tld' => 'nmbp'],
    );
    $app = makeWorkspaceRendererApp($canonicalNode, [
        'name' => 'happie',
        'path' => '/home/nckrtl/apps/happie',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $instanceNode->id,
            node: 'NMBP',
            path: '/Users/nckrtl/apps/happie',
            document_root: 'public',
            domain: 'happie.nmbp',
        ),
    ]);
    assert($instance instanceof AppInstance);

    $workspace = makeWorkspaceRendererWorkspace($app, [
        'app_instance_id' => $instance->id,
        'name' => 'recipes',
        'path' => '/Users/nckrtl/.codex/worktrees/a59f/happie',
        'php_version' => null,
    ]);

    $mounts = workspaceRendererForTest()->render($workspace)->mounts();

    expect($mounts)
        ->toContain([
            'source' => '/Users/nckrtl/packages',
            'target' => '/packages',
            'read_only' => false,
        ])
        ->toContain([
            'source' => '/Users/nckrtl/.config/orbit/ca/root.crt',
            'target' => '/etc/orbit/ca/root.crt',
            'read_only' => true,
        ]);

    expect(in_array(
        [
            'source' => '/home/nckrtl/packages',
            'target' => '/packages',
            'read_only' => false,
        ],
        $mounts,
        strict: true,
    ))
        ->toBeFalse()
        ->and(in_array(
            [
                'source' => '/home/nckrtl/.config/orbit/ca/root.crt',
                'target' => '/etc/orbit/ca/root.crt',
                'read_only' => true,
            ],
            $mounts,
            strict: true,
        ))
        ->toBeFalse();
});

it('uses selected app instance runtime mounts for workspace containers', function (): void {
    $node = createTestAppHostNode(['name' => 'NMBP', 'platform' => 'macos_14', 'user' => 'nckrtl']);
    $app = makeWorkspaceRendererApp($node, [
        'name' => 'hauser',
        'path' => '/Users/nckrtl/apps/hauser',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver' => AppInstanceDriver::Orbit,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: 'NMBP',
            path: '/Users/nckrtl/apps/hauser',
            document_root: 'public',
            domain: 'hauser.nmbp',
        ),
    ]);
    assert($instance instanceof AppInstance);
    $instance
        ->runtimeMounts()
        ->create([
            'source' => '/Users/nckrtl/projects',
            'target' => '/projects',
            'read_only' => true,
        ]);
    $workspace = makeWorkspaceRendererWorkspace($app, [
        'name' => 'feature-a',
        'path' => '/Users/nckrtl/apps/hauser/.worktrees/feature-a',
        'app_instance_id' => $instance->id,
        'php_version' => null,
    ]);
    $workspace->setRelation('appInstance', $instance);

    $mounts = workspaceRendererForTest()->render($workspace)->mounts();

    expect($mounts)
        ->toContain([
            'source' => '/Users/nckrtl/projects',
            'target' => '/projects',
            'read_only' => true,
        ]);
});

it('uses configured runtime mounts from the selected app instance', function (): void {
    $node = createTestAppHostNode(['user' => 'nckrtl']);
    $app = makeWorkspaceRendererApp($node, [
        'name' => 'nckrtl',
        'path' => '/home/nckrtl/apps/nckrtl',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $workspace = makeWorkspaceRendererWorkspace($app, [
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/nckrtl/.worktrees/feature-a',
        'php_version' => null,
    ]);
    $workspace
        ->appInstance
        ->runtimeMounts()
        ->create([
            'source' => '/home/nckrtl/packages',
            'target' => '/home/nckrtl/packages',
            'read_only' => true,
        ]);
    $workspace->unsetRelation('appInstance');

    $mounts = workspaceRendererForTest()->render($workspace)->mounts();

    expect($mounts)
        ->toContain([
            'source' => '/home/nckrtl/packages',
            'target' => '/packages',
            'read_only' => false,
        ])
        ->and($mounts)
        ->toContain([
            'source' => '/home/nckrtl/packages',
            'target' => '/home/nckrtl/packages',
            'read_only' => true,
        ]);
});

it('does not mount the packages directory for app-prod PHP workspace runtimes', function (): void {
    $node = createTestAppHostNode(attributes: ['user' => 'orbit'], role: 'app-prod');
    $app = makeWorkspaceRendererApp($node, [
        'name' => 'demo-prod',
        'environment' => 'production',
        'path' => '/home/demo/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $workspace = makeWorkspaceRendererWorkspace($app, [
        'name' => 'feature-a',
        'path' => '/home/demo/app/.worktrees/feature-a',
        'php_version' => null,
    ]);

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->mounts())
        ->not
        ->toContain([
            'source' => '/home/orbit/packages',
            'target' => '/packages',
            'read_only' => false,
        ]);
});

it('uses the workspace php_version override when set', function (): void {
    $workspace = makePhpWorkspace(workspaceOverrides: ['php_version' => '8.4']);

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->image())->toBe('ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.4-bookworm');
});

it('inherits the app php_version when workspace php_version is null', function (): void {
    $workspace = makePhpWorkspace(appOverrides: ['php_version' => '8.4'], workspaceOverrides: ['php_version' => null]);

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->image())->toBe('ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.4-bookworm');
});

it('uses the approved glibc-based FrankenPHP image family rather than alpine/musl', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->image())
        ->toEndWith('-bookworm')
        ->and($container->image())
        ->not->toContain('alpine')->and($container->image())
        ->not->toContain('musl');
});

it('does not render a workspace runtime container for static (non-PHP) workspaces', function (): void {
    $workspace = makePhpWorkspace(appOverrides: ['runtime' => AppRuntimeKind::Static]);

    expect(fn () => workspaceRendererForTest()->render($workspace))
        ->toThrow(InvalidArgumentException::class);
});

it('changes the spec hash when php_version changes so the manager recreates the container', function (): void {
    $renderer = workspaceRendererForTest();

    $a = $renderer->render(makePhpWorkspace(appOverrides: ['name' => 'a'], workspaceOverrides: [
        'name' => 'feature-a',
        'php_version' => '8.5',
    ]));
    $b = $renderer->render(makePhpWorkspace(appOverrides: ['name' => 'b'], workspaceOverrides: [
        'name' => 'feature-b',
        'php_version' => '8.4',
    ]));

    expect($a->specHash())->not->toBe($b->specHash());
});

it('changes the spec hash when the app-dev packages mount policy changes', function (): void {
    $renderer = workspaceRendererForTest();
    $workspace = makePhpWorkspace();

    $withPackagesMount = $renderer->render($workspace)->specHash();
    $app = $workspace->app;
    assert(
        $app instanceof App,
        description: 'Workspace must retain its app relation before packages mount hash coverage.',
    );
    $node = $app->node;
    assert(
        $node instanceof Node,
        description: 'App must retain its node relation before packages mount hash coverage.',
    );

    $node->roleAssignments()->update(['status' => 'pending']);
    $node->unsetRelation('roleAssignments');
    $app->unsetRelation('node');
    $workspace->unsetRelation('app');

    expect($withPackagesMount)->not->toBe($renderer->render($workspace)->specHash());
});

it('changes the spec hash when a configured mount is added to the selected app instance', function (): void {
    $renderer = workspaceRendererForTest();
    $workspace = makePhpWorkspace();

    $withoutConfiguredMount = $renderer->render($workspace)->specHash();
    $instance = $workspace->appInstance;
    assert(
        $instance instanceof AppInstance,
        description: 'Workspace must keep its app instance relation for configured mount hash coverage.',
    );

    $instance
        ->runtimeMounts()
        ->create([
            'source' => '/home/orbit/packages',
            'target' => '/home/orbit/packages',
            'read_only' => true,
        ]);
    $instance->unsetRelation('runtimeMounts');
    $workspace->unsetRelation('appInstance');

    expect($withoutConfiguredMount)->not->toBe($renderer->render($workspace)->specHash());
});

it('changes the spec hash when selected app instance runtime mounts change', function (): void {
    $renderer = workspaceRendererForTest();
    $node = createTestAppHostNode(['name' => 'NMBP', 'platform' => 'macos_14', 'user' => 'nckrtl']);
    $app = makeWorkspaceRendererApp($node, [
        'name' => 'hauser',
        'path' => '/Users/nckrtl/apps/hauser',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver' => AppInstanceDriver::Orbit,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: 'NMBP',
            path: '/Users/nckrtl/apps/hauser',
            document_root: 'public',
            domain: 'hauser.nmbp',
        ),
    ]);
    assert(
        $instance instanceof AppInstance,
        description: 'Factory must return an app instance for selected-instance hash coverage.',
    );
    $workspace = makeWorkspaceRendererWorkspace($app, [
        'name' => 'feature-a',
        'path' => '/Users/nckrtl/apps/hauser/.worktrees/feature-a',
        'app_instance_id' => $instance->id,
        'php_version' => null,
    ]);
    $workspace->setRelation('appInstance', $instance);

    $withoutInstanceMount = $renderer->render($workspace)->specHash();

    $instance
        ->runtimeMounts()
        ->create([
            'source' => '/Users/nckrtl/projects',
            'target' => '/projects',
            'read_only' => true,
        ]);
    $instance->unsetRelation('runtimeMounts');
    $workspace->unsetRelation('appInstance');

    expect($withoutInstanceMount)->not->toBe($renderer->render($workspace)->specHash());
});

it('renders opcache directives from the PHP runtime policy', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->phpIni())->toMatchArray([
        'opcache.enable' => '1',
        'opcache.enable_cli' => '1',
        'opcache.memory_consumption' => '256',
        'opcache.max_accelerated_files' => '20000',
    ]);
});

it('renders realpath cache directives from the PHP runtime policy', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->phpIni())->toMatchArray([
        'realpath_cache_size' => '4096K',
        'realpath_cache_ttl' => '600',
    ]);
});

it('renders app-dev FrankenPHP thread pool settings for classic workspace runtimes', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->environment())->toMatchArray([
        'FRANKENPHP_CONFIG' => "max_threads auto\nmax_idle_time 1h",
    ]);
});

it('does not render app-dev FrankenPHP thread pool settings for app-prod workspace runtimes', function (): void {
    $node = createTestAppHostNode(['user' => 'orbit'], 'app-prod');
    $app = makeWorkspaceRendererApp($node, [
        'name' => 'demo-prod',
        'environment' => 'production',
        'path' => '/home/demo/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $workspace = makeWorkspaceRendererWorkspace($app, [
        'name' => 'feature-a',
        'path' => '/home/demo/app/.worktrees/feature-a',
        'php_version' => null,
    ]);

    $container = workspaceRendererForTest()->render($workspace);

    expect(array_key_exists('FRANKENPHP_CONFIG', $container->environment()))->toBeFalse();
});

it('omits opcache.preload from rendered php ini when the workspace has no preload script', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace);

    expect(array_key_exists('opcache.preload', $container->phpIni()))->toBeFalse();
});

it('renders opcache.preload when a preload script is provided', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace, preloadPath: '/app/bootstrap/cache/preload.php');

    expect($container->phpIni()['opcache.preload'])->toBe('/app/bootstrap/cache/preload.php');
});

it('renders FrankenPHP-consumed SERVER_NAME and SERVER_ROOT so the configured root is actually served', function (): void {
    $renderer = workspaceRendererForTest();

    $publicRoot = $renderer->render(makePhpWorkspace(appOverrides: [
        'name' => 'a',
        'document_root' => 'public',
    ], workspaceOverrides: ['name' => 'feature-a']));
    $webRoot = $renderer->render(makePhpWorkspace(appOverrides: [
        'name' => 'b',
        'document_root' => 'web',
    ], workspaceOverrides: ['name' => 'feature-b']));
    $projectRoot = $renderer->render(makePhpWorkspace(appOverrides: [
        'name' => 'c',
        'document_root' => '.',
    ], workspaceOverrides: ['name' => 'feature-c']));

    expect($publicRoot->environment())
        ->toMatchArray([
            'SERVER_NAME' => ':80',
            'SERVER_ROOT' => '/app/public',
            'ORBIT_APP_DOCUMENT_ROOT' => 'public',
        ])
        ->and($webRoot->environment())
        ->toMatchArray([
            'SERVER_NAME' => ':80',
            'SERVER_ROOT' => '/app/web',
        ])
        ->and($projectRoot->environment())
        ->toMatchArray([
            'SERVER_NAME' => ':80',
            'SERVER_ROOT' => '/app',
        ])
        ->and($publicRoot->specHash())
        ->not->toBe($webRoot->specHash())->and($publicRoot->specHash())
        ->not->toBe($projectRoot->specHash());
});

it('exposes ORBIT_APP, ORBIT_WORKSPACE, and ORBIT_PHP_VERSION env to the container', function (): void {
    $workspace = makePhpWorkspace(appOverrides: ['php_version' => '8.4'], workspaceOverrides: ['php_version' => '8.5']);

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->environment())->toMatchArray([
        'ORBIT_APP' => 'demo',
        'ORBIT_WORKSPACE' => 'feature-a',
        'ORBIT_PHP_VERSION' => '8.5',
    ]);
});

it('renders app-dev workspace runtimes with Orbit CA trust pool mount and PHP client trust ini', function (): void {
    $node = createTestAppHostNode(['user' => 'nckrtl', 'tld' => 'test']);
    $app = makeWorkspaceRendererApp($node, [
        'name' => 'demo',
        'path' => '/home/nckrtl/apps/demo',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $workspace = makeWorkspaceRendererWorkspace($app, [
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'php_version' => null,
    ]);

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->mounts())
        ->toContain([
            'source' => '/home/nckrtl/.config/orbit/ca/root.crt',
            'target' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
            'read_only' => true,
        ])
        ->and($container->environment())
        ->toMatchArray([
            'SSL_CERT_FILE' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
            'CURL_CA_BUNDLE' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
        ])
        ->and($container->phpIni())
        ->toMatchArray([
            'openssl.cafile' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
            'curl.cainfo' => AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath,
        ])
        ->and($container->extraHosts())
        ->toBe([
            'feature-a.demo.test' => 'host-gateway',
        ]);
});

it('does not render runtime client trust for app-prod workspace runtimes', function (): void {
    $node = createTestAppHostNode(['user' => 'orbit'], 'app-prod');
    $app = makeWorkspaceRendererApp($node, [
        'name' => 'demo-prod',
        'environment' => 'production',
        'path' => '/home/demo/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $workspace = makeWorkspaceRendererWorkspace($app, [
        'name' => 'feature-a',
        'path' => '/home/demo/app/.worktrees/feature-a',
        'php_version' => null,
    ]);

    $container = workspaceRendererForTest()->render($workspace);

    expect(collect($container->mounts())->pluck('target'))
        ->not
        ->toContain(AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath)
        ->and(array_key_exists('SSL_CERT_FILE', $container->environment()))
        ->toBeFalse()
        ->and(array_key_exists('CURL_CA_BUNDLE', $container->environment()))
        ->toBeFalse()
        ->and(array_key_exists('openssl.cafile', $container->phpIni()))
        ->toBeFalse()
        ->and(array_key_exists('curl.cainfo', $container->phpIni()))
        ->toBeFalse()
        ->and($container->extraHosts())
        ->toBeEmpty()
        ->and(array_key_exists('extra_hosts', $container->spec()))
        ->toBeFalse();
});

it('renders app-dev workspace runtimes with inner HTTPS on 8443, site cert mounts, and FrankenPHP TLS directives', function (): void {
    $node = createTestAppHostNode(['user' => 'nckrtl', 'tld' => 'test']);
    $app = makeWorkspaceRendererApp($node, [
        'name' => 'demo',
        'path' => '/home/nckrtl/apps/demo',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
        'runtime_config' => ['proxy_transport' => 'https'],
    ]);
    $workspace = makeWorkspaceRendererWorkspace($app, [
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'php_version' => null,
    ]);

    $container = workspaceRendererForTest()->render($workspace);

    expect(workspaceRendererForTest()->upstreamUrl($workspace))
        ->toBe('https://orbit-ws-demo-feature-a:8443')
        ->and($container->environment())
        ->toMatchArray([
            'SERVER_NAME' => 'https://feature-a.demo.test:8443',
            'CADDY_SERVER_EXTRA_DIRECTIVES' => 'tls /etc/orbit/runtime-tls/tls.crt /etc/orbit/runtime-tls/tls.key',
        ])
        ->and($container->mounts())
        ->toContain([
            'source' => '/home/nckrtl/.config/orbit/certs/feature-a.demo.test.crt',
            'target' => '/etc/orbit/runtime-tls/tls.crt',
            'read_only' => true,
        ])
        ->and($container->mounts())
        ->toContain([
            'source' => '/home/nckrtl/.config/orbit/certs/feature-a.demo.test.key',
            'target' => '/etc/orbit/runtime-tls/tls.key',
            'read_only' => true,
        ]);
});

it('exposes the document-root env on the rendered docker run command so the configured root reaches FrankenPHP', function (): void {
    $workspace = makePhpWorkspace(appOverrides: ['document_root' => 'web']);
    $container = workspaceRendererForTest()->render($workspace);

    $command = new DockerCommandBuilder()->runDetached($container);

    expect($command)
        ->toContain("--env 'SERVER_NAME=:80'")
        ->and($command)
        ->toContain("--env 'SERVER_ROOT=/app/web'")
        ->and($command)
        ->toContain("--env 'XDG_CONFIG_HOME=/tmp/orbit-frankenphp/config'")
        ->and($command)
        ->toContain("--env 'XDG_DATA_HOME=/tmp/orbit-frankenphp/data'")
        ->and($command)
        ->not->toContain('CADDY_SERVER_EXTRA_DIRECTIVES')->and($command)
        ->not->toContain('target=/data')->and($command)
        ->not->toContain('target=/config');
});

it('exposes labels with the spec hash so the manager can detect drift', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->labels())
        ->toMatchArray([
            'orbit.managed' => 'true',
            'orbit.container.kind' => 'workspace-runtime',
            'orbit.app' => 'demo',
            'orbit.workspace' => 'feature-a',
        ])
        ->and($container->labels()['orbit.workspace.spec_hash'] ?? null)
        ->toBe($container->specHash());
});
