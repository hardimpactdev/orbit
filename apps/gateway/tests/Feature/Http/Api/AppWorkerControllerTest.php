<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const APP_WORKER_CALLER_WG_IP = '10.6.0.99';

beforeEach(function (): void {});

function createWorkerControllerCaller(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_WORKER_CALLER_WG_IP,
        'wireguard_address' => APP_WORKER_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantWorkerAccess(Node $caller, Node $appNode, array $permissions): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function create_worker_app_instance(
    Project $app,
    Node $node,
    string $name = 'development',
    array $overrides = [],
): AppInstance {
    return AppInstance::factory()->for($app)->create(array_merge([
        'name' => $name,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $app->path,
            document_root: $app->document_root,
            domain: $app->domain,
        ),
    ], $overrides));
}

/**
 * @param  list<RemoteShellResult>  $results
 */
function bindWorkerControllerShell(array $results = []): void
{
    app()->instance(RemoteShell::class, new class($results) implements RemoteShell {
        public function __construct(
            public array $results,
        ) {}

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return (
                array_shift($this->results) ?? new RemoteShellResult(
                    exitCode: 0,
                    stdout: "octane:installed\nfrankenphp-worker-file:present\nfrankenphp:configured\n",
                    stderr: '',
                    durationMs: 1,
                )
            );
        }
    });
}

describe('AppWorkerController', function (): void {
    it('returns the worker payload for a freshly created app via show', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['instance:read']);
        $app = Project::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
        create_worker_app_instance($app, $node);
        bindWorkerControllerShell();

        $response = $this->call(
            'GET',
            '/api/instances/docs/worker',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.project', 'docs')
            ->assertJsonPath('success.data.instance', 'development')
            ->assertJsonPath('success.data.worker_enabled', false)
            ->assertJsonPath('success.data.worker_config', null);
    });

    it('enables worker mode and stores worker_config', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['instance:worker']);
        $app = Project::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $instance = create_worker_app_instance($app, $node);
        bindWorkerControllerShell();

        $response = $this->call(
            'POST',
            '/api/instances/docs/worker/enable',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.worker_enabled', true)
            ->assertJsonPath('success.data.worker_config.workers', 'auto')
            ->assertJsonPath('success.data.worker_config.max_requests', 500)
            ->assertJsonMissingPath('success.data.worker_config.max_consecutive_failures');

        expect($instance->refresh()->worker_enabled)->toBeTrue();
    });

    it('refuses to enable worker mode when readiness fails and leaves state unchanged', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['instance:worker']);
        $app = Project::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $instance = create_worker_app_instance($app, $node);
        bindWorkerControllerShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);

        $response = $this->call(
            'POST',
            '/api/instances/docs/worker/enable',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP],
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'instance.worker_readiness_failed');

        expect($instance->refresh()->worker_enabled)->toBeFalse();
    });

    it('disables worker mode and keeps the stored worker_config', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['instance:worker']);
        $app = Project::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $instance = create_worker_app_instance($app, $node, overrides: [
            'worker_enabled' => true,
            'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
        ]);
        bindWorkerControllerShell();

        $response = $this->call(
            'POST',
            '/api/instances/docs/worker/disable',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.worker_enabled', false)
            ->assertJsonPath('success.data.worker_config.workers', 'auto');

        $instance->refresh();
        expect($instance->worker_enabled)
            ->toBeFalse()
            ->and($instance->worker_config)
            ->toMatchArray(['workers' => 'auto', 'max_requests' => 500]);
    });

    it('rejects worker mutations when the caller lacks the instance:worker permission', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['instance:read']);
        $app = Project::factory()->for($node, 'node')->create(['name' => 'docs', 'runtime' => AppRuntimeKind::Php]);
        create_worker_app_instance($app, $node);
        bindWorkerControllerShell();

        $response = $this->call(
            'POST',
            '/api/instances/docs/worker/enable',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'instance:worker');
    });

    it('returns 404 when the targeted instance is missing', function (): void {
        createWorkerControllerCaller();
        bindWorkerControllerShell();

        $response = $this->call(
            'GET',
            '/api/instances/missing/worker',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP],
        );

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'instance.not_found');
    });

    it('resolves the worker payload by exact app name even when another app holds the same value as a domain', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['instance:read']);

        // App "alpha" carries the colliding domain. If the controller path
        // short-circuits on a domain match, it would return alpha instead
        // of the docs.example.com-named app.
        $alpha = Project::factory()->for($node, 'node')->create([
            'name' => 'alpha',
            'domain' => 'docs.example.com',
            'runtime' => AppRuntimeKind::Php,
        ]);
        create_worker_app_instance($alpha, $node);
        $named = Project::factory()->for($node, 'node')->create([
            'name' => 'docs.example.com',
            'domain' => 'other.example.com',
            'runtime' => AppRuntimeKind::Php,
        ]);
        create_worker_app_instance($named, $node);
        bindWorkerControllerShell();

        $response = $this->call(
            'GET',
            '/api/instances/docs.example.com/worker',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.project', 'docs.example.com');
    });

    it('requires a concrete instance when a bare project selector is ambiguous', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['instance:read']);
        $app = Project::factory()->for($node, 'node')->create(['name' => 'docs']);
        create_worker_app_instance($app, $node, name: 'development');
        create_worker_app_instance($app, $node, name: 'production');

        $response = $this->call(
            'GET',
            '/api/instances/docs/worker',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'instance')
            ->assertJsonPath('error.meta.reason', 'instance_required')
            ->assertJsonPath('error.meta.instances', ['development', 'production']);
    });

    it('requires a concrete selector without exposing a hidden sibling', function (): void {
        $caller = createWorkerControllerCaller();
        $visibleNode = createTestAppHostNode(['name' => 'app-visible', 'host' => '10.6.0.7']);
        $hiddenNode = createTestAppHostNode(['name' => 'app-hidden', 'host' => '10.6.0.8']);
        grantWorkerAccess($caller, $visibleNode, ['instance:read']);
        $app = Project::factory()->for($visibleNode, 'node')->create(['name' => 'docs']);
        create_worker_app_instance($app, $visibleNode, name: 'development');
        create_worker_app_instance($app, $hiddenNode, name: 'production');
        bindWorkerControllerShell();

        $response = $this->call(
            'GET',
            '/api/instances/docs/worker',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'instance_required');

        expect($response->json('error.meta'))
            ->not->toHaveKey('instances')->and($response->content())
            ->not->toContain('production');
    });

    it('does not reveal whether an unauthorized explicit sibling exists', function (): void {
        $caller = createWorkerControllerCaller();
        $visibleNode = createTestAppHostNode(['name' => 'app-visible', 'host' => '10.6.0.7']);
        $hiddenNode = createTestAppHostNode(['name' => 'app-hidden', 'host' => '10.6.0.8']);
        grantWorkerAccess($caller, $visibleNode, ['instance:read']);
        $app = Project::factory()->for($visibleNode, 'node')->create(['name' => 'docs']);
        create_worker_app_instance($app, $visibleNode, name: 'development');
        create_worker_app_instance($app, $hiddenNode, name: 'production');
        bindWorkerControllerShell();

        $hidden = $this->call(
            'GET',
            '/api/instances/docs.production/worker',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP],
        );
        $missing = $this->call(
            'GET',
            '/api/instances/docs.does-not-exist/worker',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP],
        );
        $normalize = static function (TestResponse $response): array {
            /** @var array<string, mixed> $error */
            $error = $response->json('error');
            unset($error['message']);

            if (is_array($error['meta'] ?? null)) {
                $error['meta']['instance'] = '<selector>';
            }

            return $error;
        };

        $hidden->assertUnprocessable();
        $missing->assertUnprocessable();

        expect($normalize($hidden))
            ->toBe($normalize($missing))
            ->and($hidden->json('error.code'))
            ->toBe('validation_failed')
            ->and($hidden->json('error.meta'))
            ->not->toHaveKeys(['instances', 'missing_permission', 'serving_node']);
    });

    it('reads worker state from the explicitly selected instance', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['instance:read']);
        $app = Project::factory()->for($node, 'node')->create(['name' => 'docs']);
        create_worker_app_instance($app, $node, name: 'development');
        create_worker_app_instance($app, $node, name: 'production', overrides: [
            'worker_enabled' => true,
            'worker_config' => ['workers' => 4, 'max_requests' => 250],
        ]);

        $response = $this->call(
            'GET',
            '/api/instances/docs.production/worker',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.project', 'docs')
            ->assertJsonPath('success.data.instance', 'production')
            ->assertJsonPath('success.data.worker_enabled', true)
            ->assertJsonPath('success.data.worker_config.workers', 4);
    });
});
