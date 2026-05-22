<?php

declare(strict_types=1);

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function makePhpApp(array $overrides = []): App
{
    $node = Node::factory()->create(['user' => 'orbit']);

    return App::factory()->for($node, 'node')->create(array_merge([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ], $overrides));
}

function rendererForTest(): AppRuntimeContainerRenderer
{
    return new AppRuntimeContainerRenderer(
        new PhpRuntimePolicy(new PhpRuntimeCatalog),
        new OrbitContainerNames,
    );
}

it('renders a FrankenPHP app runtime container for a PHP app with deterministic name, image, network, and source mount', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect($container->name())->toBe('orbit-app-docs')
        ->and($container->image())->toBe('dunglas/frankenphp:1-php8.5-bookworm')
        ->and($container->network())->toBe('orbit-network')
        ->and($container->restartPolicy())->toBe('unless-stopped')
        ->and($container->networkAliases())->toContain('orbit-app-docs')
        ->and($container->networkAliases())->toContain('app-docs')
        ->and($container->mounts())->toContain([
            'source' => '/home/orbit/apps/docs',
            'target' => '/app',
            'read_only' => false,
        ]);
});

it('renders the selected PHP image when php_version differs', function (): void {
    $app = makePhpApp(['php_version' => '8.4']);

    $container = rendererForTest()->render($app);

    expect($container->image())->toBe('dunglas/frankenphp:1-php8.4-bookworm');
});

it('uses the approved glibc-based FrankenPHP image family rather than alpine/musl', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect($container->image())->toEndWith('-bookworm')
        ->and($container->image())->not->toContain('alpine')
        ->and($container->image())->not->toContain('musl');
});

it('does not render an app runtime container for static apps', function (): void {
    $app = makePhpApp(['runtime_kind' => AppRuntimeKind::Static]);

    expect(fn () => rendererForTest()->render($app))
        ->toThrow(InvalidArgumentException::class);
});

it('changes the spec hash when php_version changes so the manager recreates the container', function (): void {
    $renderer = rendererForTest();

    $php85 = $renderer->render(makePhpApp(['name' => 'a', 'php_version' => '8.5']));
    $php84 = $renderer->render(makePhpApp(['name' => 'b', 'php_version' => '8.4']));

    expect($php85->specHash())->not->toBe($php84->specHash());
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

    expect($publicRoot->environment())->toMatchArray([
        'SERVER_NAME' => ':80',
        'SERVER_ROOT' => '/app/public',
        'ORBIT_APP_DOCUMENT_ROOT' => 'public',
    ])
        ->and($webRoot->environment())->toMatchArray([
            'SERVER_NAME' => ':80',
            'SERVER_ROOT' => '/app/web',
        ])
        ->and($projectRoot->environment())->toMatchArray([
            'SERVER_NAME' => ':80',
            'SERVER_ROOT' => '/app',
        ])
        ->and($publicRoot->specHash())->not->toBe($webRoot->specHash())
        ->and($publicRoot->specHash())->not->toBe($projectRoot->specHash());
});

it('exposes the document-root env on the rendered docker run command so the configured root reaches FrankenPHP', function (): void {
    $app = makePhpApp(['document_root' => 'web']);
    $container = rendererForTest()->render($app);

    $command = (new DockerCommandBuilder)->runDetached($container);

    expect($command)->toContain("--env 'SERVER_NAME=:80'")
        ->and($command)->toContain("--env 'SERVER_ROOT=/app/web'");
});

it('exposes labels with the spec hash so the manager can detect drift', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect($container->labels())->toMatchArray([
        'orbit.managed' => 'true',
        'orbit.container.kind' => 'app-runtime',
    ])
        ->and($container->labels()['orbit.app.spec_hash'] ?? null)->toBe($container->specHash());
});
