<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function makePhpApp(array $overrides = [], bool $withDefaultInstance = true): App
{
    $node = createTestAppHostNode(['user' => 'orbit']);

    $attributes = array_merge([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ], $overrides);

    $app = makeRuntimeRendererApp($node, $attributes, withDefaultInstance: false);

    if ($withDefaultInstance) {
        workerRuntimeInstance(
            $app,
            node: $node,
            path: is_string($attributes['path']) ? $attributes['path'] : null,
            documentRoot: is_string($attributes['document_root']) ? $attributes['document_root'] : null,
        );
    }

    return $app;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeRuntimeRendererApp(Node $node, array $attributes, bool $withDefaultInstance = true): App
{
    // Placement is instance-authoritative now: read the intended placement from
    // the caller's attributes and thread it onto the concrete instance instead
    // of the removed App shadow columns.
    $path = is_string($attributes['path'] ?? null)
        ? $attributes['path']
        : '/home/orbit/apps/'.($attributes['name'] ?? 'app');
    $documentRoot = is_string($attributes['document_root'] ?? null) ? $attributes['document_root'] : 'public';
    $domain = is_string($attributes['domain'] ?? null) ? $attributes['domain'] : null;

    unset(
        $attributes['node_id'],
        $attributes['environment'],
        $attributes['domain'],
        $attributes['path'],
        $attributes['document_root'],
        $attributes['adopted'],
    );

    $app = App::factory()->create($attributes);
    assert($app instanceof App);

    // The default primary Orbit instance carries placement. Multi-instance tests
    // opt out and define their own instances explicitly.
    if ($withDefaultInstance) {
        Instance::factory()->for($app, 'app')->create([
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: $path,
                document_root: $documentRoot,
                domain: $domain,
            ),
        ]);
    }

    return $app;
}

function rendererForTest(): AppRuntimeContainerRenderer
{
    return new AppRuntimeContainerRenderer(
        new PhpRuntimePolicy(new PhpRuntimeCatalog),
        new OrbitContainerNames,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function workerRuntimeInstance(
    App $app,
    array $overrides = [],
    ?Node $node = null,
    ?string $path = null,
    ?string $documentRoot = null,
): Instance {
    // This helper defines the app's single primary Orbit instance. Resolve the
    // placement from an explicit node/path, else inherit it from any existing
    // instance before we replace it, else mint a fresh node.
    $existingConfig = $app->instances()->first()?->driver_config;
    $existingConfig = $existingConfig instanceof OrbitInstanceDriverConfigData ? $existingConfig : null;

    $nodeId = $node?->id ?? $existingConfig?->node_id;
    $nodeName = $node?->name ?? $existingConfig?->node;
    $resolvedPath =
        $path ?? (is_string($existingConfig?->path) ? $existingConfig->path : '/home/orbit/apps/'.$app->name);
    $resolvedDocumentRoot =
        $documentRoot ?? (is_string($existingConfig?->document_root) ? $existingConfig->document_root : 'public');

    if ($nodeId === null) {
        /** @var Node $freshNode */
        $freshNode = Node::factory()->create();
        $nodeId = $freshNode->id;
        $nodeName = $freshNode->name;
    }

    // Clear any prior instance (e.g. the mirror makePhpApp attaches) so callers
    // that reconfigure it here do not collide on the app_id+name unique key.
    $app->instances()->delete();
    $app->unsetRelation('instances');

    return Instance::factory()->for($app)->create(array_merge([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $nodeId,
            node: $nodeName,
            path: $resolvedPath,
            document_root: $resolvedDocumentRoot,
            domain: null,
        ),
    ], $overrides));
}

it('renders a FrankenPHP app runtime container for a PHP app with deterministic name, image, network, and source mount', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);
    $mountTargets = array_column($container->mounts(), 'target');

    expect($container->name())
        ->toBe('orbit-app-docs')
        ->and($container->image())
        ->toBe('ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm')
        ->and($container->network())
        ->toBe('orbit-network')
        ->and($container->restartPolicy())
        ->toBe('unless-stopped')
        ->and($container->networkAliases())
        ->toContain('orbit-app-docs')
        ->and($container->networkAliases())
        ->toContain('app-docs')
        ->and($container->mounts())
        ->toContain([
            'source' => '/home/orbit/apps/docs',
            'target' => '/app',
            'read_only' => false,
        ])
        ->and($container->mounts())
        ->toContain([
            'source' => '/home/orbit/apps/docs',
            'target' => '/home/orbit/apps/docs',
            'read_only' => false,
        ])
        ->and($mountTargets)
        ->not->toContain('/data')->and($mountTargets)
        ->not->toContain('/config')->and($container->mounts())
        ->not->toContain([
            'source' => '/home/orbit/apps/docs/.orbit/frankenphp/data',
            'target' => '/data',
            'read_only' => false,
        ])->and($container->mounts())
        ->not->toContain([
            'source' => '/home/orbit/apps/docs/.orbit/frankenphp/config',
            'target' => '/config',
            'read_only' => false,
        ])->and($container->environment())->toMatchArray([
            'XDG_CONFIG_HOME' => '/tmp/orbit-frankenphp/config',
            'XDG_DATA_HOME' => '/tmp/orbit-frankenphp/data',
        ]);
});

it('derives runtime environment from the instance, not a stale app environment column', function (): void {
    $node = createTestAppHostNode(['user' => 'orbit'], settings: ['tld' => 'test']);
    $app = makeRuntimeRendererApp(
        $node,
        [
            'name' => 'docs',
            // Stale app-level column claiming production; the instance is the authority.
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ],
        withDefaultInstance: false,
    );
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
            domain: 'docs.test',
        ),
    ]);

    $container = rendererForTest()->renderForInstance($app, $instance);

    // A development instance on an app-dev node gets no production container user,
    // even though the app's environment column still reads 'production'.
    expect($container->runtimeUser())->toBeNull();
});

it('mounts the owning app-dev node user packages directory at /packages', function (): void {
    $node = createTestAppHostNode(['user' => 'nckrtl']);
    $app = makeRuntimeRendererApp($node, [
        'name' => 'nckrtl',
        'path' => '/home/nckrtl/apps/nckrtl',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);

    $container = rendererForTest()->render($app);

    expect($container->mounts())->toContain([
        'source' => '/home/nckrtl/packages',
        'target' => '/packages',
        'read_only' => false,
    ]);
});

it('mounts the macos app-dev node user packages directory at /packages', function (): void {
    $node = createTestAppHostNode(['platform' => 'macos_14', 'user' => 'nckrtl']);
    $app = makeRuntimeRendererApp($node, [
        'name' => 'nckrtl',
        'path' => '/Users/nckrtl/apps/nckrtl',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);

    $container = rendererForTest()->render($app);

    expect($container->mounts())->toContain([
        'source' => '/Users/nckrtl/packages',
        'target' => '/packages',
        'read_only' => false,
    ]);
});

it('uses only runtime mounts owned by the matching app instance for app containers', function (): void {
    $node = createTestAppHostNode(['name' => 'beast', 'platform' => 'ubuntu_24-04', 'user' => 'nckrtl']);
    $app = makeRuntimeRendererApp(
        $node,
        [
            'name' => 'hauser',
            'path' => '/home/nckrtl/apps/hauser',
            'document_root' => 'public',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ],
        withDefaultInstance: false,
    );
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: 'beast',
            path: '/home/nckrtl/apps/hauser',
            document_root: 'public',
            domain: 'hauser.development',
        ),
    ]);
    assert(
        $instance instanceof Instance,
        description: 'Factory must return an app instance for runtime mount preference coverage.',
    );
    $instance
        ->runtimeMounts()
        ->create([
            'source' => '/home/nckrtl/volumes/apps',
            'target' => '/apps',
            'read_only' => true,
        ]);
    $otherInstance = Instance::factory()->for($app)->create([
        'name' => 'preview',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: 'beast',
            path: '/home/nckrtl/apps/hauser-preview',
            document_root: 'public',
            domain: 'hauser.preview',
        ),
    ]);
    assert($otherInstance instanceof Instance);
    $otherInstance
        ->runtimeMounts()
        ->create([
            'source' => '/home/nckrtl/apps',
            'target' => '/apps',
            'read_only' => true,
        ]);

    $mounts = rendererForTest()->render($app)->mounts();

    expect($mounts)
        ->toContain([
            'source' => '/home/nckrtl/packages',
            'target' => '/packages',
            'read_only' => false,
        ])
        ->and($mounts)
        ->toContain([
            'source' => '/home/nckrtl/volumes/apps',
            'target' => '/apps',
            'read_only' => true,
        ])
        ->and($mounts)
        ->not->toContain([
            'source' => '/home/nckrtl/apps',
            'target' => '/apps',
            'read_only' => true,
        ]);
});

it('renders concrete app instance runtime containers with instance identity and node config', function (): void {
    $beast = createTestAppHostNode(['name' => 'beast', 'platform' => 'ubuntu_24-04', 'user' => 'nckrtl']);
    $nmbp = createTestAppHostNode(['name' => 'nmbp', 'platform' => 'darwin', 'user' => 'nckrtl', 'tld' => 'nmbp']);
    $app = makeRuntimeRendererApp($beast, [
        'name' => 'hauser',
        'path' => '/home/nckrtl/apps/hauser',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'nmbp',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $nmbp->id,
            node: 'nmbp',
            path: '/Users/nckrtl/apps/hauser',
            document_root: 'public',
            domain: 'hauser.nmbp',
        ),
    ]);
    $instance
        ->runtimeMounts()
        ->create([
            'source' => '/Users/nckrtl/apps',
            'target' => '/apps',
            'read_only' => true,
        ]);

    $container = rendererForTest()->renderForInstance($app, $instance);

    expect($container->name())
        ->toBe('orbit-app-hauser-nmbp')
        ->and($container->appSlug())
        ->toBe('hauser-nmbp')
        ->and($container->labels()['orbit.app'])
        ->toBe('hauser-nmbp')
        ->and($container->environment()['SERVER_NAME'])
        ->toBe(':8080')
        ->and($container->mounts())
        ->toContain([
            'source' => '/Users/nckrtl/apps/hauser',
            'target' => '/app',
            'read_only' => false,
        ])
        ->and($container->mounts())
        ->toContain([
            'source' => '/Users/nckrtl/apps',
            'target' => '/apps',
            'read_only' => true,
        ])
        ->and(rendererForTest()->phpIniHostPathForInstance($app, $instance))
        ->toBe('/Users/nckrtl/.config/orbit/apps/hauser-nmbp.ini');
});

it('does not use runtime mounts from another app instance when the matching instance has none', function (): void {
    $node = createTestAppHostNode(['name' => 'beast', 'platform' => 'ubuntu_24-04', 'user' => 'nckrtl']);
    $app = makeRuntimeRendererApp(
        $node,
        [
            'name' => 'hauser',
            'path' => '/home/nckrtl/apps/hauser',
            'document_root' => 'public',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ],
        withDefaultInstance: false,
    );
    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: 'beast',
            path: '/home/nckrtl/apps/hauser',
            document_root: 'public',
            domain: 'hauser.development',
        ),
    ]);
    $otherInstance = Instance::factory()->for($app)->create([
        'name' => 'preview',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: 'beast',
            path: '/home/nckrtl/apps/hauser-preview',
            document_root: 'public',
            domain: 'hauser.preview',
        ),
    ]);
    assert($otherInstance instanceof Instance);
    $otherInstance
        ->runtimeMounts()
        ->create([
            'source' => '/home/nckrtl/apps',
            'target' => '/apps',
            'read_only' => true,
        ]);

    $mounts = rendererForTest()->render($app)->mounts();

    expect($mounts)->not->toContain([
        'source' => '/home/nckrtl/apps',
        'target' => '/apps',
        'read_only' => true,
    ]);
});

it('renders configured app instance runtime mounts after built-in mounts', function (): void {
    $node = createTestAppHostNode(['user' => 'nckrtl']);
    $app = makeRuntimeRendererApp(
        $node,
        [
            'name' => 'nckrtl',
            'path' => '/home/nckrtl/apps/nckrtl',
            'document_root' => 'public',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ],
        withDefaultInstance: false,
    );
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/nckrtl/apps/nckrtl',
            document_root: 'public',
            domain: 'nckrtl.test',
        ),
    ]);
    assert($instance instanceof Instance);
    $instance
        ->runtimeMounts()
        ->create([
            'source' => '/home/nckrtl/packages',
            'target' => '/home/nckrtl/packages',
            'read_only' => true,
        ]);

    $mounts = rendererForTest()->render($app)->mounts();

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

it('does not mount the packages directory for app-prod PHP app runtimes', function (): void {
    $node = createTestAppHostNode(attributes: ['user' => 'orbit'], role: 'app-prod');
    $app = makeRuntimeRendererApp($node, [
        'name' => 'docs-prod',
        'path' => '/home/docs/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);

    $container = rendererForTest()->render($app);

    expect($container->mounts())
        ->not
        ->toContain([
            'source' => '/home/orbit/packages',
            'target' => '/packages',
            'read_only' => false,
        ])
        ->and($container->restartPolicy())
        ->toBe('always');
});

it('renders a production app runtime user from the app source owner but leaves development containers on the node user', function (): void {
    $productionNode = createTestAppHostNode(['user' => 'orbit'], 'app-prod');
    $productionApp = makeRuntimeRendererApp($productionNode, [
        'name' => 'docs-prod',
        'path' => '/home/docs/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);

    $developmentApp = makePhpApp([
        'name' => 'docs-dev',
        'path' => '/home/docs/app',
    ]);

    $renderer = rendererForTest();

    expect($renderer->render($productionApp)->runtimeUser())
        ->toBe('docs')
        ->and($renderer->render($developmentApp)->runtimeUser())
        ->toBeNull();
});

it('renders the selected PHP image when php_version differs', function (): void {
    $app = makePhpApp(['php_version' => '8.4']);

    $container = rendererForTest()->render($app);

    expect($container->image())->toBe('ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.4-bookworm');
});

it('uses the approved glibc-based FrankenPHP image family rather than alpine/musl', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect($container->image())
        ->toEndWith('-bookworm')
        ->and($container->image())
        ->not->toContain('alpine')->and($container->image())
        ->not->toContain('musl');
});

it('does not render an app runtime container for static apps', function (): void {
    $app = makePhpApp(['runtime' => AppRuntimeKind::Static]);

    expect(fn () => rendererForTest()->render($app))
        ->toThrow(InvalidArgumentException::class);
});

it('changes the spec hash when php_version changes so the manager recreates the container', function (): void {
    $renderer = rendererForTest();

    $php85 = $renderer->render(makePhpApp(['name' => 'a', 'php_version' => '8.5']));
    $php84 = $renderer->render(makePhpApp(['name' => 'b', 'php_version' => '8.4']));

    expect($php85->specHash())->not->toBe($php84->specHash());
});

it('changes the spec hash when the app-dev packages mount policy changes', function (): void {
    $renderer = rendererForTest();
    $node = createTestAppHostNode(['user' => 'orbit']);
    $app = makeRuntimeRendererApp($node, [
        'name' => 'docs-dev',
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);

    $withPackagesMount = $renderer->render($app)->specHash();

    $node->roleAssignments()->update(['status' => 'pending']);
    $node->unsetRelation('roleAssignments');
    $app->unsetRelation('instances');

    expect($withPackagesMount)->not->toBe($renderer->render($app)->specHash());
});

it('does not change the spec hash when another app instance runtime mount changes', function (): void {
    $renderer = rendererForTest();
    $node = createTestAppHostNode(['user' => 'orbit']);
    $app = makePhpApp(['name' => 'docs-dev'], withDefaultInstance: false);
    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
            domain: 'docs-dev.test',
        ),
    ]);
    $otherInstance = Instance::factory()->for($app)->create([
        'name' => 'preview',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: '/home/orbit/apps/docs-preview',
            document_root: 'public',
            domain: 'docs-dev.preview',
        ),
    ]);
    assert($otherInstance instanceof Instance);

    $withoutConfiguredMount = $renderer->render($app)->specHash();

    $otherInstance
        ->runtimeMounts()
        ->create([
            'source' => '/home/orbit/packages',
            'target' => '/home/orbit/packages',
            'read_only' => true,
        ]);
    $otherInstance->unsetRelation('runtimeMounts');
    $app->unsetRelation('instances');

    expect($withoutConfiguredMount)->toBe($renderer->render($app)->specHash());
});

it('changes the spec hash when matching app instance runtime mounts change', function (): void {
    $renderer = rendererForTest();
    $node = createTestAppHostNode(['name' => 'beast', 'platform' => 'ubuntu_24-04', 'user' => 'nckrtl']);
    $app = makeRuntimeRendererApp(
        $node,
        [
            'name' => 'hauser',
            'path' => '/home/nckrtl/apps/hauser',
            'document_root' => 'public',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ],
        withDefaultInstance: false,
    );
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: 'beast',
            path: '/home/nckrtl/apps/hauser',
            document_root: 'public',
            domain: 'hauser.development',
        ),
    ]);
    assert(
        $instance instanceof Instance,
        description: 'Factory must return an app instance for runtime mount hash coverage.',
    );

    $withoutInstanceMount = $renderer->render($app)->specHash();

    $instance
        ->runtimeMounts()
        ->create([
            'source' => '/home/nckrtl/apps',
            'target' => '/apps',
            'read_only' => true,
        ]);
    $instance->unsetRelation('runtimeMounts');
    $app->unsetRelation('instances');

    expect($withoutInstanceMount)->not->toBe($renderer->render($app)->specHash());
});

it('renders opcache directives from the PHP runtime policy', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect($container->phpIni())->toMatchArray([
        'opcache.enable' => '1',
        'opcache.enable_cli' => '1',
        'opcache.memory_consumption' => '256',
        'opcache.max_accelerated_files' => '20000',
    ]);
});

it('renders realpath cache directives from the PHP runtime policy', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect($container->phpIni())->toMatchArray([
        'realpath_cache_size' => '4096K',
        'realpath_cache_ttl' => '600',
    ]);
});

it('omits opcache.preload from rendered php ini when the app has no preload script', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect(array_key_exists('opcache.preload', $container->phpIni()))->toBeFalse();
});

it('renders opcache.preload when a preload script is provided', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app, preloadPath: '/app/bootstrap/cache/preload.php');

    expect($container->phpIni()['opcache.preload'])->toBe('/app/bootstrap/cache/preload.php');
});

it('renders FrankenPHP-consumed SERVER_NAME and SERVER_ROOT so the configured root is actually served', function (): void {
    $renderer = rendererForTest();

    $publicRoot = $renderer->render(makePhpApp(['name' => 'a', 'document_root' => 'public']));
    $webRoot = $renderer->render(makePhpApp(['name' => 'b', 'document_root' => 'web']));
    $projectRoot = $renderer->render(makePhpApp(['name' => 'c', 'document_root' => '.']));

    expect($publicRoot->environment())
        ->toMatchArray([
            'SERVER_NAME' => ':8080',
            'SERVER_ROOT' => '/app/public',
            'ORBIT_APP_DOCUMENT_ROOT' => 'public',
        ])
        ->and($webRoot->environment())
        ->toMatchArray([
            'SERVER_NAME' => ':8080',
            'SERVER_ROOT' => '/app/web',
        ])
        ->and($projectRoot->environment())
        ->toMatchArray([
            'SERVER_NAME' => ':8080',
            'SERVER_ROOT' => '/app',
        ])
        ->and($publicRoot->specHash())
        ->not->toBe($webRoot->specHash())->and($publicRoot->specHash())
        ->not->toBe($projectRoot->specHash());
});

it('uses the internal app-dev runtime upstream on HTTP port 8080 by default', function (): void {
    $app = makePhpApp(['name' => 'docs']);

    expect(rendererForTest()->upstreamUrl($app))->toBe('http://orbit-app-docs:8080');
});

it('renders app-dev PHP runtimes with Orbit CA trust pool mount and PHP client trust ini', function (): void {
    $node = createTestAppHostNode(['user' => 'nckrtl', 'tld' => 'test']);
    $app = makeRuntimeRendererApp(
        $node,
        [
            'name' => 'craft-starterkit-react',
            'path' => '/home/nckrtl/apps/craft-starterkit-react',
            'document_root' => 'public',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ],
        withDefaultInstance: false,
    );
    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/nckrtl/apps/craft-starterkit-react',
            document_root: 'public',
        ),
    ]);
    $app->unsetRelation('instances');

    $container = rendererForTest()->render($app);

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
            'craft-starterkit-react.test' => 'host-gateway',
        ]);
});

it('does not render runtime client trust for app-prod PHP runtimes', function (): void {
    $node = createTestAppHostNode(['user' => 'orbit'], role: 'app-prod');
    $app = makeRuntimeRendererApp($node, [
        'name' => 'docs-prod',
        'path' => '/home/docs/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);

    $container = rendererForTest()->render($app);

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

it('renders app-dev PHP runtimes with inner HTTPS on 8443, site cert mounts, and FrankenPHP TLS directives', function (): void {
    $node = createTestAppHostNode(['user' => 'nckrtl', 'tld' => 'test']);
    $app = makeRuntimeRendererApp($node, [
        'name' => 'docs',
        'path' => '/home/nckrtl/apps/docs',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
        'runtime_config' => ['proxy_transport' => 'https'],
    ]);

    $container = rendererForTest()->render($app);

    expect(rendererForTest()->upstreamUrl($app))
        ->toBe('https://orbit-app-docs:8443')
        ->and($container->environment())
        ->toMatchArray([
            'SERVER_NAME' => 'https://docs.test:8443',
            'CADDY_SERVER_EXTRA_DIRECTIVES' => 'tls /etc/orbit/runtime-tls/tls.crt /etc/orbit/runtime-tls/tls.key',
        ])
        ->and($container->mounts())
        ->toContain([
            'source' => '/home/nckrtl/.config/orbit/certs/docs.test.crt',
            'target' => '/etc/orbit/runtime-tls/tls.crt',
            'read_only' => true,
        ])
        ->and($container->mounts())
        ->toContain([
            'source' => '/home/nckrtl/.config/orbit/certs/docs.test.key',
            'target' => '/etc/orbit/runtime-tls/tls.key',
            'read_only' => true,
        ]);
});

it('keeps app-prod PHP runtimes on plain HTTP port 8080 without inner TLS mounts', function (): void {
    $node = createTestAppHostNode(['user' => 'orbit'], 'app-prod');
    $app = makeRuntimeRendererApp($node, [
        'name' => 'docs-prod',
        'path' => '/home/docs/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);

    $container = rendererForTest()->render($app);

    expect(rendererForTest()->upstreamUrl($app))
        ->toBe('http://orbit-app-docs-prod:8080')
        ->and($container->environment()['SERVER_NAME'])
        ->toBe(':8080')
        ->and(array_key_exists('CADDY_SERVER_EXTRA_DIRECTIVES', $container->environment()))
        ->toBeFalse()
        ->and(collect($container->mounts())->pluck('target'))
        ->not->toContain('/etc/orbit/runtime-tls/tls.crt');
});

it('exposes the document-root env on the rendered docker run command so the configured root reaches FrankenPHP', function (): void {
    $app = makePhpApp(['document_root' => 'web']);
    $container = rendererForTest()->render($app);

    $command = new DockerCommandBuilder()->runDetached($container);

    expect($command)
        ->toContain("--env 'SERVER_NAME=:8080'")
        ->and($command)
        ->toContain("--env 'SERVER_ROOT=/app/web'")
        ->and($command)
        ->toContain("--env 'XDG_CONFIG_HOME=/tmp/orbit-frankenphp/config'")
        ->and($command)
        ->toContain("--env 'XDG_DATA_HOME=/tmp/orbit-frankenphp/data'")
        ->and($command)
        ->not->toContain('CADDY_SERVER_EXTRA_DIRECTIVES')->and($command)
        ->not->toContain('/home/orbit/apps/docs/.orbit/frankenphp')->and($command)
        ->not->toContain('target=/data')->and($command)
        ->not->toContain('target=/config')->and($command)
        ->not->toContain(' --publish ');
});

it('exposes labels with the spec hash so the manager can detect drift', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect($container->labels())
        ->toMatchArray([
            'orbit.managed' => 'true',
            'orbit.container.kind' => 'app-runtime',
        ])
        ->and($container->labels()['orbit.app.spec_hash'] ?? null)
        ->toBe($container->specHash());
});

it('does not render any worker-mode runtime config when worker_enabled is false', function (): void {
    $app = makePhpApp();
    $instance = workerRuntimeInstance($app);

    $container = rendererForTest()->renderForInstance($app, $instance);

    expect($container->environment()['FRANKENPHP_CONFIG'] ?? null)
        ->toBe("max_threads auto\nmax_idle_time 1h")
        ->and(array_key_exists('MAX_REQUESTS', $container->environment()))
        ->toBeFalse();
});

it('does not include any FRANKENPHP_CONFIG worker directive in the docker run command when worker mode is off', function (): void {
    $app = makePhpApp();
    $instance = workerRuntimeInstance($app);
    $container = rendererForTest()->renderForInstance($app, $instance);

    $command = new DockerCommandBuilder()->runDetached($container);

    expect($command)
        ->toContain("FRANKENPHP_CONFIG=max_threads auto\nmax_idle_time 1h")
        ->and($command)
        ->not->toContain('worker /app')->and($command)
        ->not->toContain('MAX_REQUESTS');
});

it('does not render app-dev FrankenPHP thread pool settings for app-prod classic runtimes', function (): void {
    $node = createTestAppHostNode(['user' => 'orbit'], 'app-prod');
    $app = makeRuntimeRendererApp($node, [
        'name' => 'docs-prod',
        'path' => '/home/docs/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);

    $container = rendererForTest()->render($app);

    expect(array_key_exists('FRANKENPHP_CONFIG', $container->environment()))->toBeFalse();
});

it('runs a release-aware production runtime from the active live application root', function (): void {
    $node = createTestAppHostNode(['user' => 'orbit'], 'app-prod');
    $app = makeRuntimeRendererApp($node, [
        'name' => 'docs-prod',
        'path' => '/home/docs/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $instance = workerRuntimeInstance($app, [
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/docs/app',
            document_root: 'live/public',
            domain: 'docs.example.com',
        ),
    ]);

    $container = rendererForTest()->renderForInstance($app, $instance);

    expect($container->workingDirectory())
        ->toBe('/app/live')
        ->and($container->environment())
        ->toMatchArray([
            'APP_BASE_PATH' => '/app/live',
            'SERVER_ROOT' => '/app/live/public',
        ])
        ->and($container->spec()['working_directory'] ?? null)
        ->toBe('/app/live');
});

it('renders the FrankenPHP worker block against public/frankenphp-worker.php with workers=auto', function (): void {
    $app = makePhpApp();
    $instance = workerRuntimeInstance($app, [
        'worker_enabled' => true,
        'worker_config' => [
            'workers' => 'auto',
            'max_requests' => 500,
        ],
    ]);

    $container = rendererForTest()->renderForInstance($app, $instance);

    expect($container->environment())->toMatchArray([
        'FRANKENPHP_CONFIG' => "max_threads auto\nmax_idle_time 1h\nworker {\n\tfile /app/public/frankenphp-worker.php\n}",
        'MAX_REQUESTS' => '500',
    ]);
});

it('renders the block-form `worker` directive with num when worker_config.workers is an integer', function (): void {
    $app = makePhpApp();
    $instance = workerRuntimeInstance($app, [
        'worker_enabled' => true,
        'worker_config' => [
            'workers' => 4,
            'max_requests' => 1000,
        ],
    ]);

    $container = rendererForTest()->renderForInstance($app, $instance);

    expect($container->environment())->toMatchArray([
        'FRANKENPHP_CONFIG' => "max_threads auto\nmax_idle_time 1h\nworker {\n\tfile /app/public/frankenphp-worker.php\n\tnum 4\n}",
        'MAX_REQUESTS' => '1000',
    ]);
});

it(
    'does not emit any OCTANE_* or MAX_CONSECUTIVE_FAILURES env vars; FrankenPHP and Laravel only read FRANKENPHP_CONFIG and MAX_REQUESTS',
    function (): void {
        $app = makePhpApp();
        $instance = workerRuntimeInstance($app, [
            'worker_enabled' => true,
            'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
        ]);

        $container = rendererForTest()->renderForInstance($app, $instance);

        expect(array_key_exists('OCTANE_SERVER', $container->environment()))
            ->toBeFalse()
            ->and(array_key_exists('OCTANE_WORKERS', $container->environment()))
            ->toBeFalse()
            ->and(array_key_exists('OCTANE_MAX_REQUESTS', $container->environment()))
            ->toBeFalse()
            ->and(array_key_exists('OCTANE_MAX_CONSECUTIVE_FAILURES', $container->environment()))
            ->toBeFalse()
            ->and(array_key_exists('MAX_CONSECUTIVE_FAILURES', $container->environment()))
            ->toBeFalse();
    },
);

it('points the worker directive at the configured document root, not always /app/public', function (): void {
    $app = makePhpApp([
        'document_root' => 'web',
    ]);
    $instance = workerRuntimeInstance($app, [
        'worker_enabled' => true,
        'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
    ]);

    $container = rendererForTest()->renderForInstance($app, $instance);

    expect($container->environment()['FRANKENPHP_CONFIG'])
        ->toBe("max_threads auto\nmax_idle_time 1h\nworker {\n\tfile /app/web/frankenphp-worker.php\n}");
});

it(
    'exposes the worker directive and MAX_REQUESTS env on the rendered docker run command so FrankenPHP and the Laravel worker actually consume them',
    function (): void {
        $app = makePhpApp();
        $instance = workerRuntimeInstance($app, [
            'worker_enabled' => true,
            'worker_config' => ['workers' => 4, 'max_requests' => 500],
        ]);
        $container = rendererForTest()->renderForInstance($app, $instance);

        $command = new DockerCommandBuilder()->runDetached($container);

        expect($command)
            ->toContain(
                "FRANKENPHP_CONFIG=max_threads auto\nmax_idle_time 1h\nworker {\n\tfile /app/public/frankenphp-worker.php\n\tnum 4\n}",
            )
            ->and($command)
            ->toContain("--env 'MAX_REQUESTS=500'");
    },
);

it('changes the spec hash when worker mode toggles on the same app so the manager recreates the container', function (): void {
    $renderer = rendererForTest();
    $app = makePhpApp(['name' => 'toggle-app']);
    $instance = workerRuntimeInstance($app);

    // Classic mode (worker disabled is the factory default).
    $classic = $renderer->renderForInstance($app, $instance);

    // Flip only the worker toggle on the same app identity — name, node,
    // path, php_version, document_root all stay identical so the spec hash
    // differs strictly because of the worker fields.
    $instance->worker_enabled = true;
    $instance->worker_config = [
        'workers' => 'auto',
        'max_requests' => 500,
    ];

    $worker = $renderer->renderForInstance($app, $instance);

    expect($classic->name())
        ->toBe($worker->name())
        ->and($classic->appSlug())
        ->toBe($worker->appSlug())
        ->and($classic->image())
        ->toBe($worker->image())
        ->and($classic->specHash())
        ->not->toBe($worker->specHash());
});

it('uses worker config defaults when worker_enabled is true and worker_config is empty', function (): void {
    $app = makePhpApp();
    $instance = workerRuntimeInstance($app, [
        'worker_enabled' => true,
        'worker_config' => null,
    ]);

    $container = rendererForTest()->renderForInstance($app, $instance);

    expect($container->environment())->toMatchArray([
        'FRANKENPHP_CONFIG' => "max_threads auto\nmax_idle_time 1h\nworker {\n\tfile /app/public/frankenphp-worker.php\n}",
        'MAX_REQUESTS' => '500',
    ]);
});

it('renders the runtime source mount from the instance placement, not a stale app path column', function (): void {
    $node = createTestAppHostNode(['user' => 'orbit']);
    // Stale/opposite app path column; the instance placement is authoritative.
    $app = makeRuntimeRendererApp(
        $node,
        [
            'name' => 'docs',
            'path' => '/stale/wrong-path',
            'document_root' => 'public',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ],
        withDefaultInstance: false,
    );
    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
        ),
    ]);

    $container = rendererForTest()->render($app);

    expect(collect($container->mounts())->pluck('source'))
        ->toContain('/home/orbit/apps/docs')
        ->not->toContain('/stale/wrong-path');
});
