<?php

declare(strict_types=1);

use App\Enums\Apps\AppRuntimeKind;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Apps\LaravelViteDevServerEnvironment;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Processes\ProcessDockerContainer;
use App\Services\Processes\ProcessDockerContainerRenderer;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function makeProcessRendererApp(array $overrides = []): App
{
    $node = createTestAppHostNode(['user' => 'orbit', 'tld' => 'beast']);

    $path = isset($overrides['path']) && is_string($overrides['path']) ? $overrides['path'] : '/home/orbit/apps/docs';
    $documentRoot = isset($overrides['document_root']) && is_string($overrides['document_root'])
        ? $overrides['document_root']
        : 'public';
    unset($overrides['path'], $overrides['document_root'], $overrides['node_id']);

    /** @var App $app */
    $app = App::factory()
        ->placedOn($node, 'development', $path, $documentRoot)
        ->create(array_merge([
            'name' => 'docs',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ], $overrides));

    return $app;
}

function makeProcessRendererProcess(App $app, array $overrides = []): Process
{
    return Process::factory()
        ->forOwner($app)
        ->create(array_merge([
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'restart_policy' => ProcessRestartPolicy::Always,
        ], $overrides));
}

function processDockerRenderer(): ProcessDockerContainerRenderer
{
    return new ProcessDockerContainerRenderer(
        new PhpRuntimePolicy(new PhpRuntimeCatalog),
        new OrbitContainerNames,
        new LaravelViteDevServerEnvironment,
    );
}

it('renders a docker process container for a main app PHP process with deterministic name, image, and network', function (): void {
    $app = makeProcessRendererApp();
    $process = makeProcessRendererProcess($app);

    $container = processDockerRenderer()->render($app, $process);

    expect($container)
        ->toBeInstanceOf(ProcessDockerContainer::class)
        ->and($container->name())
        ->toBe('orbit_docs_development_main_queue')
        ->and($container->image())
        ->toBe('ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm')
        ->and($container->network())
        ->toBe('orbit-network')
        ->and($container->workingDirectory())
        ->toBe(ProcessDockerContainer::SourceTarget)
        ->and($container->command())
        ->toBe('php artisan queue:work')
        ->and($container->networkAliases())
        ->toContain('orbit_docs_development_main_queue');
});

it('renders a docker process container for a workspace process with workspace identity', function (): void {
    $app = makeProcessRendererApp();
    $workspace = Workspace::factory()->for($app)->create([
        'name' => 'feature-x',
        'path' => '/home/orbit/apps/docs/.worktrees/feature-x',
        'php_version' => '8.5',
    ]);
    $process = makeProcessRendererProcess($app, ['name' => 'vite', 'command' => 'npm run dev -- --host=0.0.0.0']);

    $container = processDockerRenderer()->render($app, $process, $workspace);

    expect($container->name())
        ->toBe('orbit_docs_development_feature-x_vite')
        ->and($container->command())
        ->toBe('npm run dev -- --host=0.0.0.0')
        ->and($container->mounts())
        ->toContain([
            'source' => '/home/orbit/apps/docs/.worktrees/feature-x',
            'target' => ProcessDockerContainer::SourceTarget,
            'read_only' => false,
        ]);
});

it('uses an explicit managed container name from runtime config for process-backed app runtime rows', function (): void {
    $app = makeProcessRendererApp();
    $process = makeProcessRendererProcess($app, [
        'name' => 'frankenphp-docs',
        'command' => 'frankenphp',
        'runtime_config' => [
            'container_name' => 'orbit-app-docs',
        ],
    ]);

    expect(processDockerRenderer()->containerName($app, $process))->toBe('orbit-app-docs');
});

it('uses an explicit managed container name from runtime config for process-backed workspace runtime rows', function (): void {
    $app = makeProcessRendererApp();
    $workspace = Workspace::factory()->for($app)->create([
        'name' => 'feature-x',
        'path' => '/home/orbit/apps/docs/.worktrees/feature-x',
        'php_version' => '8.5',
    ]);
    $process = Process::factory()
        ->forOwner($workspace)
        ->create([
            'name' => 'frankenphp-docs-feature-x',
            'command' => 'frankenphp',
            'runtime_config' => [
                'container_name' => 'orbit-ws-docs-feature-x',
            ],
        ]);

    expect(processDockerRenderer()->containerName($app, $process, $workspace))->toBe('orbit-ws-docs-feature-x');
});

it('mounts the app source path at the runtime source target for main processes', function (): void {
    $app = makeProcessRendererApp(['path' => '/home/orbit/apps/docs/']);
    $process = makeProcessRendererProcess($app);

    $container = processDockerRenderer()->render($app, $process);

    expect($container->mounts())->toContain([
        'source' => '/home/orbit/apps/docs',
        'target' => ProcessDockerContainer::SourceTarget,
        'read_only' => false,
    ]);
});

it('maps the process restart policy to the matching docker restart policy', function (
    ProcessRestartPolicy $policy,
    string $expected,
): void {
    $app = makeProcessRendererApp();
    $process = makeProcessRendererProcess($app, ['restart_policy' => $policy]);

    $container = processDockerRenderer()->render($app, $process);

    expect($container->restartPolicy())->toBe($expected);
})->with([
    'never' => [ProcessRestartPolicy::Never, 'no'],
    'on_failure' => [ProcessRestartPolicy::OnFailure, 'on-failure'],
    'always' => [ProcessRestartPolicy::Always, 'unless-stopped'],
]);

it('keeps always-restart docker processes persistent on app-prod nodes', function (): void {
    $node = createTestAppHostNode(['user' => 'orbit'], 'app-prod');
    $app = App::factory()
        ->placedOn($node, 'development', '/home/orbit/apps/docs')
        ->create([
            'name' => 'docs',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
    $process = makeProcessRendererProcess($app);

    expect(processDockerRenderer()->render($app, $process)->restartPolicy())->toBe('always');
});

it('populates the runtime unit environment contract for the docker process runtime', function (): void {
    $app = makeProcessRendererApp();
    $process = makeProcessRendererProcess($app);

    $environment = processDockerRenderer()->render($app, $process)->environment();

    expect($environment)
        ->toHaveKey('PATH')
        ->toHaveKey('HOME')
        ->toHaveKey('APP_URL')
        ->toHaveKey('VITE_APP_URL')
        ->toHaveKey('VITE_VALET_HOST')
        ->toHaveKey('VITE_DEV_SERVER_KEY')
        ->toHaveKey('VITE_DEV_SERVER_CERT')
        ->toHaveKey('ORBIT_APP')
        ->toHaveKey('ORBIT_PHP_VERSION');

    expect($environment['ORBIT_APP'])
        ->toBe('docs')
        ->and($environment['ORBIT_PHP_VERSION'])
        ->toBe('8.5')
        ->and($environment['APP_URL'])
        ->toBe('https://docs.beast')
        ->and($environment['VITE_APP_URL'])
        ->toBe('https://docs.beast')
        ->and($environment['VITE_VALET_HOST'])
        ->toBe('docs.beast')
        ->and($environment['VITE_DEV_SERVER_KEY'])
        ->toBe('/etc/orbit/certs/docs.beast.key')
        ->and($environment['VITE_DEV_SERVER_CERT'])
        ->toBe('/etc/orbit/certs/docs.beast.crt');

    expect(processDockerRenderer()->render($app, $process)->mounts())
        ->toContain([
            'source' => '/home/orbit/.config/orbit/certs/docs.beast.key',
            'target' => '/etc/orbit/certs/docs.beast.key',
            'read_only' => true,
        ])
        ->toContain([
            'source' => '/home/orbit/.config/orbit/certs/docs.beast.crt',
            'target' => '/etc/orbit/certs/docs.beast.crt',
            'read_only' => true,
        ]);
});

it('includes ORBIT_WORKSPACE in the environment when rendering a workspace process container', function (): void {
    $app = makeProcessRendererApp();
    $workspace = Workspace::factory()->for($app)->create(['name' => 'feature-x', 'php_version' => '8.5']);
    $process = makeProcessRendererProcess($app);

    $environment = processDockerRenderer()->render($app, $process, $workspace)->environment();

    expect($environment['ORBIT_WORKSPACE'])
        ->toBe('feature-x')
        ->and($environment['APP_URL'])
        ->toBe('https://feature-x.docs.beast')
        ->and($environment['VITE_DEV_SERVER_KEY'])
        ->toBe('/etc/orbit/certs/feature-x.docs.beast.key')
        ->and($environment['VITE_DEV_SERVER_CERT'])
        ->toBe('/etc/orbit/certs/feature-x.docs.beast.crt');

    expect(processDockerRenderer()->render($app, $process, $workspace)->mounts())
        ->toContain([
            'source' => '/home/orbit/.config/orbit/certs/feature-x.docs.beast.key',
            'target' => '/etc/orbit/certs/feature-x.docs.beast.key',
            'read_only' => true,
        ])
        ->toContain([
            'source' => '/home/orbit/.config/orbit/certs/feature-x.docs.beast.crt',
            'target' => '/etc/orbit/certs/feature-x.docs.beast.crt',
            'read_only' => true,
        ]);
});

it('exposes labels with the spec hash so the manager can detect drift', function (): void {
    $app = makeProcessRendererApp();
    $process = makeProcessRendererProcess($app);

    $container = processDockerRenderer()->render($app, $process);

    expect($container->labels())
        ->toMatchArray([
            'orbit.managed' => 'true',
            'orbit.container.kind' => 'process-runtime',
            'orbit.app' => 'docs',
            'orbit.process' => 'queue',
        ])
        ->and($container->labels()[ProcessDockerContainer::SpecHashLabel] ?? null)
        ->toBe($container->specHash());
});

it('tags workspace process containers with the workspace label', function (): void {
    $app = makeProcessRendererApp();
    $workspace = Workspace::factory()->for($app)->create(['name' => 'feature-x', 'php_version' => '8.5']);
    $process = makeProcessRendererProcess($app);

    $labels = processDockerRenderer()->render($app, $process, $workspace)->labels();

    expect($labels['orbit.workspace'] ?? null)->toBe('feature-x');
});

it('produces a stable spec hash for identical inputs across calls', function (): void {
    $app = makeProcessRendererApp();
    $process = makeProcessRendererProcess($app);

    $first = processDockerRenderer()->render($app, $process)->specHash();
    $second = processDockerRenderer()->render($app, $process)->specHash();

    expect($first)->toBe($second);
});

it('changes the spec hash when the process command changes so the manager recreates the container', function (): void {
    $app = makeProcessRendererApp();

    $queue = processDockerRenderer()->render($app, makeProcessRendererProcess($app, [
        'name' => 'one',
        'command' => 'php artisan queue:work',
    ]));
    $horizon = processDockerRenderer()->render($app, makeProcessRendererProcess($app, [
        'name' => 'two',
        'command' => 'php artisan horizon',
    ]));

    expect($queue->specHash())->not->toBe($horizon->specHash());
});

it('throws when the owning app is not a PHP app and therefore has no runtime image', function (): void {
    $app = makeProcessRendererApp(['runtime' => AppRuntimeKind::Static]);
    $process = makeProcessRendererProcess($app);

    expect(fn () => processDockerRenderer()->render($app, $process))
        ->toThrow(InvalidArgumentException::class);
});

it(
    'renders the process command through an in-container shell so the command string is parsed by sh, not exec-d as a literal binary',
    function (): void {
        $app = makeProcessRendererApp();
        $process = makeProcessRendererProcess($app, ['command' => 'php artisan queue:work --sleep=3']);

        $command = new DockerCommandBuilder()->runDetached(processDockerRenderer()->render($app, $process));

        // Docker run shape: ... --entrypoint 'sh' ... IMAGE '-lc' '<process command>'.
        // Without this shape Docker would try to exec a single binary literally
        // named "php artisan queue:work --sleep=3", which does not exist.
        expect($command)
            ->toContain("--workdir '/app'")
            ->and($command)
            ->toContain("--entrypoint 'sh'")
            ->and($command)
            ->toMatch(
                "/'ghcr\\.io\\/hardimpactdev\\/orbit-frankenphp:[^']+' '-lc' 'php artisan queue:work --sleep=3'/",
            );
    },
);

it('emits docker create (not docker run -d) when the manager builds the idle creation script', function (): void {
    $app = makeProcessRendererApp();
    $process = makeProcessRendererProcess($app);

    $container = processDockerRenderer()->render($app, $process);
    $builder = new DockerCommandBuilder;

    $created = $builder->createIdle($container);
    $run = $builder->runDetached($container);

    // process:add without --start must render the container idle. The
    // `docker create` form is identical to `docker run -d` except for the
    // verb, so the same args (image, --entrypoint sh, -lc <cmd>) follow.
    expect($created)
        ->toStartWith('docker create')
        ->and($created)
        ->not
        ->toStartWith('docker run')
        ->and($created)
        ->toContain("--entrypoint 'sh'")
        ->and($created)
        ->toContain("'-lc' 'php artisan queue:work'")
        ->and(substr($created, strlen('docker create')))
        ->toBe(substr($run, strlen('docker run -d')));
});

it('renders structured service ports for node owned managed service docker processes', function (): void {
    $node = Node::factory()->create([
        'name' => 'beast',
        'wireguard_address' => '10.6.0.7',
    ]);
    $app = makeProcessRendererApp();
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'mailpit',
            'command' => '/mailpit',
            'runtime_config' => [
                'image' => 'axllent/mailpit:latest',
                'ports' => [
                    ['published' => 1025, 'target' => 1025, 'protocol' => 'tcp'],
                ],
            ],
        ]);

    $container = processDockerRenderer()->render($app, $process);

    expect($container->ports())
        ->toBe([
            ['host' => null, 'published' => 1025, 'target' => 1025, 'protocol' => 'tcp'],
        ])
        ->and($container->publishedPorts())
        ->toBe(['1025:1025']);
});

it('does not publish ports for app owned docker process containers', function (): void {
    $app = makeProcessRendererApp();
    $process = makeProcessRendererProcess($app, [
        'runtime_config' => [
            'ports' => [
                ['published' => 1025, 'target' => 1025, 'protocol' => 'tcp'],
            ],
        ],
    ]);

    expect(processDockerRenderer()->render($app, $process)->publishedPorts())->toBe([]);
});

it('escapes shell metacharacters in the process command so the in-container shell still parses them through -c', function (): void {
    $app = makeProcessRendererApp();
    $process = makeProcessRendererProcess($app, [
        'command' => "php artisan queue:work && echo 'done' > /tmp/log",
    ]);

    $command = new DockerCommandBuilder()->runDetached(processDockerRenderer()->render($app, $process));

    // The full process command, including shell operators, must reach `sh -lc`
    // intact so the in-container shell — not the host — interprets `&&` and
    // the redirection. escapeshellarg quotes the embedded single-quote.
    expect($command)->toContain("'-lc' 'php artisan queue:work && echo '\\''done'\\'' > /tmp/log'");
});
