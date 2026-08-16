<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppWorkerReadiness;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(
        RunsInternalCommands::class,
        app(RemoteLocalExecutor::class),
    );
});

/**
 * @return array{app: App, instance: Instance}
 */
function makeReadinessTarget(array $overrides = []): array
{
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'user' => 'orbit',
            'wireguard_address' => '10.6.0.61',
        ]);

    $attributes = array_merge([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ], $overrides);
    // The App is logical-only; placement (path/document_root) lives on the
    // concrete instance below.
    $app = App::factory()->create([
        'name' => $attributes['name'],
        'php_version' => $attributes['php_version'],
        'runtime' => $attributes['runtime'],
    ]);

    return [
        'app' => $app,
        'instance' => Instance::factory()->for($app, 'app')->create([
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: $attributes['path'],
                document_root: $attributes['document_root'],
            ),
        ]),
    ];
}

function readinessProbe(string $stdout): AppWorkerReadiness
{
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.61:9477/v1/commands' => Http::response(readinessProbeResponse($stdout)),
    ]);

    return app(AppWorkerReadiness::class);
}

/**
 * @return array<string, mixed>
 */
function readinessProbeResponse(string $stdout): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'app-worker-readiness.probe',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'stdout' => $stdout,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ];
}

function readinessProbeWasSent(Request $request, string $path, string $workerFile): bool
{
    return (
        $request->url() === 'http://10.6.0.61:9477/v1/commands'
        && $request['binary'] === 'orbit'
        && $request['argv'][0] === 'internal:app-worker-readiness:probe'
        && $request['argv'][1] === $path
        && $request['argv'][2] === $workerFile
    );
}

afterEach(function (): void {
    Http::preventStrayRequests(false);
});

describe('AppWorkerReadiness service', function (): void {
    it('refuses worker mode for static apps', function (): void {
        ['app' => $app, 'instance' => $instance] = makeReadinessTarget([
            'runtime' => AppRuntimeKind::Static,
        ]);

        $result = readinessProbe('')->assess($app, $instance);

        expect($result->ready)
            ->toBeFalse()
            ->and($result->code)
            ->toBe('instance.worker_unsupported_runtime')
            ->and($result->missing)
            ->toBe(['runtime=php']);
    });

    it('returns app.worker_unknown_node when the app has no owning node relation', function (): void {
        // Build a logical App without saving so its instance has no owning node.
        $app = new App;
        $app->name = 'docs';
        $app->php_version = '8.5';
        $app->runtime = AppRuntimeKind::Php;
        $instance = Instance::factory()->for($app, 'app')->make();

        $result = readinessProbe('')->assess($app, $instance);

        expect($result->ready)
            ->toBeFalse()
            ->and($result->code)
            ->toBe('instance.worker_unknown_node')
            ->and($result->missing)
            ->toBe(['owning_node'])
            ->and($result->message)
            ->toContain("Instance 'docs.development' has no owning node");
    });

    it('returns app.worker_missing_path when the app has an empty source path', function (): void {
        ['app' => $app, 'instance' => $instance] = makeReadinessTarget(['path' => '']);

        $result = readinessProbe('')->assess($app, $instance);

        expect($result->ready)
            ->toBeFalse()
            ->and($result->code)
            ->toBe('instance.worker_missing_path')
            ->and($result->missing)
            ->toBe(['app_path']);
    });

    it('reports missing vendor/laravel/octane when the probe omits the installed token', function (): void {
        ['app' => $app, 'instance' => $instance] = makeReadinessTarget();
        $stdout = "frankenphp-worker-file:present\nfrankenphp:configured\n";

        $result = readinessProbe($stdout)->assess($app, $instance);

        expect($result->ready)->toBeFalse()->and($result->missing)->toContain('vendor/laravel/octane');
    });

    it('reports the document-root-relative worker file path when the probe omits the worker-file token', function (): void {
        ['app' => $app, 'instance' => $instance] = makeReadinessTarget();
        $stdout = "octane:installed\nfrankenphp:configured\n";

        $result = readinessProbe($stdout)->assess($app, $instance);

        expect($result->ready)->toBeFalse()->and($result->missing)->toContain('public/frankenphp-worker.php');
    });

    it('reports the configured document_root in the missing worker file path, not always public/', function (): void {
        ['app' => $app, 'instance' => $instance] = makeReadinessTarget(['document_root' => 'web']);
        $stdout = "octane:installed\nfrankenphp:configured\n";

        $result = readinessProbe($stdout)->assess($app, $instance);

        expect($result->ready)
            ->toBeFalse()
            ->and($result->missing)
            ->toContain('web/frankenphp-worker.php')
            ->and($result->missing)
            ->not
            ->toContain('public/frankenphp-worker.php')
            ->and($result->meta['worker_file'] ?? null)
            ->toBe('web/frankenphp-worker.php');
    });

    it('reports the app-root-relative worker file when document_root is empty or "."', function (): void {
        ['app' => $app, 'instance' => $instance] = makeReadinessTarget(['document_root' => '.']);
        $stdout = "octane:installed\nfrankenphp:configured\n";

        $result = readinessProbe($stdout)->assess($app, $instance);

        expect($result->ready)->toBeFalse()->and($result->missing)->toContain('frankenphp-worker.php');
    });

    it('reports missing octane.server=frankenphp when the probe omits the configured token', function (): void {
        ['app' => $app, 'instance' => $instance] = makeReadinessTarget();
        $stdout = "octane:installed\nfrankenphp-worker-file:present\n";

        $result = readinessProbe($stdout)->assess($app, $instance);

        expect($result->ready)->toBeFalse()->and($result->missing)->toContain('octane.server=frankenphp');
    });

    it('passes only when every required token is present', function (): void {
        ['app' => $app, 'instance' => $instance] = makeReadinessTarget();
        $stdout = "octane:installed\nfrankenphp-worker-file:present\nfrankenphp:configured\n";

        $result = readinessProbe($stdout)->assess($app, $instance);

        expect($result->ready)->toBeTrue();

        Http::assertSent(fn (Request $request): bool => readinessProbeWasSent(
            request: $request,
            path: '/home/orbit/apps/docs',
            workerFile: 'public/frankenphp-worker.php',
        ));
    });
});
