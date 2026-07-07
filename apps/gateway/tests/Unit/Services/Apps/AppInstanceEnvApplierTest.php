<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeContainerApplyOutcome;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppInstanceEnvApplier;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Ca\OrbitCaService;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->bind(
        AppRuntimeContainerManager::class,
        fn (): AppRuntimeContainerManager => new AppRuntimeContainerManager(
            app(RemoteShell::class),
            app(DockerCommandBuilder::class),
            app(OrbitCaService::class),
        ),
    );
});

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:halstead
 */
describe('AppInstanceEnvApplier', function (): void {
    it('updates the app env file on the owning node', function (): void {
        $node = Node::factory()->gateway()->create(['status' => 'active']);
        $path = storage_path('framework/testing/app-instance-env-applier');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', <<<'ENV'
            APP_NAME=Docs
            MAIL_MAILER=log
            ENV);

        $app = App::factory()->create([
            'node_id' => $node->id,
            'path' => $path,
            'runtime' => AppRuntimeKind::Static,
        ]);

        $result = app(AppInstanceEnvApplier::class)->apply($app, 'MAIL_MAILER', 'smtp');

        expect($result->envPath)
            ->toBe($path.'/.env')
            ->and($result->cacheCleared)
            ->toBeFalse()
            ->and($result->runtimeOutcome)
            ->toBeNull()
            ->and(File::get($path.'/.env'))
            ->toContain('APP_NAME=Docs')
            ->and(File::get($path.'/.env'))
            ->toContain('MAIL_MAILER=smtp');
    });

    /**
     * @mago-expect lint:cyclomatic-complexity
     * @mago-expect lint:halstead
     */
    it('clears Laravel caches and reapplies the runtime container for PHP apps', function (): void {
        request()->headers->set(ExplicitRemoteShellFallback::HEADER, ExplicitRemoteShellFallback::REQUIRED);

        [$app, $node] = appAndNodeForEnvApplierTest();
        $container = renderEnvApplierTestContainer($app);
        $shell = new AppInstanceEnvApplierRecordingRemoteShell(
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        );
        app()->instance(RemoteShell::class, $shell);

        $result = app(AppInstanceEnvApplier::class)->apply($app, 'MAIL_MAILER', 'smtp');

        expect($result->cacheCleared)
            ->toBeTrue()
            ->and($result->runtimeOutcome)
            ->toBe(AppRuntimeContainerApplyOutcome::Created)
            ->and($shell->scripts[0])
            ->not->toContain('php artisan config:clear')->and(implode("\n", $shell->scripts))
            ->not->toContain('bootstrap/cache');

        expect(implode("\n", $shell->scripts))
            ->toContain('internal:env-file')
            ->toContain('internal:app-cache:clear');
    });
});

function appAndNodeForEnvApplierTest(): array
{
    $node = Node::factory()
        ->appProd()
        ->orbitAgentCapable()
        ->create([
            'status' => 'active',
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.80',
        ]);

    $app = App::factory()->for($node, 'node')->create([
        'name' => 'billing',
        'path' => '/home/orbit/apps/billing',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);

    return [$app, $node];
}

/**
 * @return array<string, mixed>
 */
function app_instance_env_applier_env_read_response(string $contents): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'env-file.read',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'contents' => $contents,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function app_instance_env_applier_env_write_response(): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'env-file.write',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'bytes' => 10,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function app_instance_env_applier_cache_clear_response(): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'app.cache.clear',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'deleted_cache_files' => 1,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ];
}

function renderEnvApplierTestContainer(App $app): AppRuntimeContainer
{
    return new AppRuntimeContainerRenderer(
        new PhpRuntimePolicy(new PhpRuntimeCatalog),
        new OrbitContainerNames,
    )->render($app);
}

final class AppInstanceEnvApplierRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  RemoteShellResult|list<RemoteShellResult>  $results
     */
    public function __construct(RemoteShellResult|array $results)
    {
        $this->results = is_array($results) ? $results : [$results];
    }

    /** @var list<RemoteShellResult> */
    private array $results;

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        if (str_contains($script, 'id -u')) {
            return new RemoteShellResult(exitCode: 0, stdout: "1000\n1000\n", stderr: '', durationMs: 1);
        }

        if (str_contains($script, 'internal:env-file')) {
            return app_instance_env_applier_shell_success(
                str_contains($script, 'env-file.read')
                    ? ['contents' => 'MAIL_MAILER=log'.PHP_EOL]
                    : ['bytes' => 10],
            );
        }

        if (str_contains($script, 'internal:app-cache:clear')) {
            return app_instance_env_applier_shell_success(['deleted_cache_files' => 1]);
        }

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

/**
 * @param  array<string, mixed>  $data
 */
function app_instance_env_applier_shell_success(array $data): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode([
            'success' => [
                'data' => $data,
            ],
        ], JSON_THROW_ON_ERROR)
            ."\n",
        stderr: '',
        durationMs: 1,
    );
}
