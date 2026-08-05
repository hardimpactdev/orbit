<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const WORKSPACE_APP_LOG_CALLER_WG_IP = '10.6.0.196';

function create_workspace_app_log_caller(): Node
{
    return Node::factory()->create([
        'name' => 'caller',
        'host' => WORKSPACE_APP_LOG_CALLER_WG_IP,
        'managed' => true,
        'wireguard_address' => WORKSPACE_APP_LOG_CALLER_WG_IP,
    ]);
}

function grant_workspace_read(Node $caller, Node $serving): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $serving->id,
        'permissions' => json_encode(['workspace:read'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function seed_workspace_for_app_log(Node $serving): Workspace
{
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $serving->id,
        'path' => '/srv/apps/docs',
    ]);
    $instance = Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $serving->id,
            node: $serving->name,
            path: '/srv/apps/docs',
            domain: 'docs.test',
        ),
    ]);

    return Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'name' => 'feature-docs',
        'path' => '/srv/apps/docs/workspaces/feature-docs',
    ]);
}

function fake_workspace_application_log_agent(array $data, int $exitCode = 0): void
{
    app()->instance(RunsInternalCommands::class, app(RemoteLocalExecutor::class));
    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'application.log.read',
            'binary' => 'orbit',
            'status' => $exitCode === 0 ? 'succeeded' : 'failed',
            'exit_code' => $exitCode,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => json_encode([
                        'success' => [
                            'data' => $data,
                            'meta' => [],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
        ]),
    ]);
}

describe('Workspace application log API', function (): void {
    it('requires instance query parameter on bounded reads', function (): void {
        $caller = create_workspace_app_log_caller();
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.46',
        ]);
        grant_workspace_read($caller, $serving);
        seed_workspace_for_app_log($serving);

        $response = $this->call(
            'GET',
            '/api/workspaces/feature-docs/log',
            server: [
                'REMOTE_ADDR' => WORKSPACE_APP_LOG_CALLER_WG_IP,
            ],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'instance');
    });

    it('returns the bounded envelope when authorized with workspace:read', function (): void {
        $caller = create_workspace_app_log_caller();
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.46',
        ]);
        grant_workspace_read($caller, $serving);
        seed_workspace_for_app_log($serving);
        fake_workspace_application_log_agent([
            'file_exists' => true,
            'lines' => ['workspace laravel line'],
            'path' => 'storage/logs/laravel.log',
        ]);

        $response = $this->call(
            'GET',
            '/api/workspaces/feature-docs/log',
            [
                'instance' => 'docs.development',
                'lines' => 100,
            ],
            server: [
                'REMOTE_ADDR' => WORKSPACE_APP_LOG_CALLER_WG_IP,
            ],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.path', 'storage/logs/laravel.log')
            ->assertJsonPath('success.data.target.type', 'workspace')
            ->assertJsonPath('success.data.target.selector', 'feature-docs')
            ->assertJsonPath('success.data.target.workspace', 'feature-docs')
            ->assertJsonPath('success.data.target.instance', 'development')
            ->assertJsonPath('success.data.target.app', 'docs');
    });

    it('rejects non-integer lines on workspace bounded reads', function (): void {
        $caller = create_workspace_app_log_caller();
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.46',
        ]);
        grant_workspace_read($caller, $serving);
        seed_workspace_for_app_log($serving);

        $response = $this->call(
            'GET',
            '/api/workspaces/feature-docs/log',
            [
                'instance' => 'docs.development',
                'lines' => '2.0',
            ],
            server: [
                'REMOTE_ADDR' => WORKSPACE_APP_LOG_CALLER_WG_IP,
            ],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.meta.field', 'lines');
    });

    it('requires instance in stream-start body', function (): void {
        $caller = create_workspace_app_log_caller();
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.46',
        ]);
        grant_workspace_read($caller, $serving);
        seed_workspace_for_app_log($serving);

        $response = $this->call(
            'POST',
            '/api/workspaces/feature-docs/log-stream',
            content: json_encode(['lines' => 50], JSON_THROW_ON_ERROR),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => WORKSPACE_APP_LOG_CALLER_WG_IP,
            ],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.meta.field', 'instance');
    });

    it('records success activity without log contents or absolute paths', function (): void {
        $caller = create_workspace_app_log_caller();
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.46',
        ]);
        grant_workspace_read($caller, $serving);
        seed_workspace_for_app_log($serving);
        fake_workspace_application_log_agent([
            'file_exists' => true,
            'lines' => ['SECRET_WORKSPACE_LOG_LINE'],
            'path' => 'storage/logs/laravel.log',
        ]);

        $this->call(
            'GET',
            '/api/workspaces/feature-docs/log',
            [
                'instance' => 'docs.development',
                'lines' => 25,
                'node' => 'app-dev-1',
            ],
            server: [
                'REMOTE_ADDR' => WORKSPACE_APP_LOG_CALLER_WG_IP,
            ],
        )->assertOk();

        $entry = \Spatie\Activitylog\Models\Activity::query()->latest('id')->first();

        expect($entry)->not->toBeNull();
        $encoded = json_encode($entry->properties->toArray(), JSON_THROW_ON_ERROR);
        expect($encoded)
            ->not->toContain('SECRET_WORKSPACE_LOG_LINE')
            ->not->toContain('/srv/apps')
            ->and($entry->properties->get('outcome'))->toBe('success')
            ->and($entry->properties->get('mode'))->toBe('bounded')
            ->and($entry->properties->get('workspace'))->toBe('feature-docs')
            ->and($entry->properties->get('lines'))->toBe(25)
            ->and($entry->properties->get('node'))->toBe('app-dev-1');
    });

    it('records stream-start failure activity without log contents or absolute paths', function (): void {
        config()->set('orbit.operation_token_secret', 'workspace-app-log-stream-fail-secret');
        $caller = create_workspace_app_log_caller();
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.46',
        ]);
        grant_workspace_read($caller, $serving);
        seed_workspace_for_app_log($serving);

        $this->call(
            'POST',
            '/api/workspaces/feature-docs/log-stream',
            content: json_encode([
                'instance' => 'docs.development',
                'lines' => 40,
                'node' => 'other-node',
            ], JSON_THROW_ON_ERROR),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => WORKSPACE_APP_LOG_CALLER_WG_IP,
            ],
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'node_mismatch');

        $entry = \Spatie\Activitylog\Models\Activity::query()->latest('id')->first();

        expect($entry)->not->toBeNull();
        $encoded = json_encode($entry->properties->toArray(), JSON_THROW_ON_ERROR);
        expect($encoded)
            ->not->toContain('/srv/apps')
            ->not->toContain('SECRET_')
            ->and($entry->properties->get('outcome'))->toBe('validation_failed')
            ->and($entry->properties->get('mode'))->toBe('follow')
            ->and($entry->properties->get('workspace'))->toBe('feature-docs')
            ->and($entry->properties->get('lines'))->toBe(40)
            ->and($entry->properties->get('node'))->toBe('other-node')
            ->and($entry->properties->get('target'))->toBeNull();
    });

    it('records post-authorization read_failed activity without log contents or absolute paths', function (): void {
        $caller = create_workspace_app_log_caller();
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.46',
        ]);
        grant_workspace_read($caller, $serving);
        seed_workspace_for_app_log($serving);
        fake_workspace_application_log_agent([
            'file_exists' => true,
            'lines' => ['SECRET_WORKSPACE_LOG_LINE'],
            'path' => '/srv/apps/docs/storage/logs/laravel.log',
        ], exitCode: 1);

        $this->call(
            'GET',
            '/api/workspaces/feature-docs/log',
            [
                'instance' => 'docs.development',
                'lines' => 10,
                'node' => 'app-dev-1',
            ],
            server: [
                'REMOTE_ADDR' => WORKSPACE_APP_LOG_CALLER_WG_IP,
            ],
        )
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'application_log.read_failed');

        $entry = \Spatie\Activitylog\Models\Activity::query()->latest('id')->first();

        expect($entry)->not->toBeNull();
        $encoded = json_encode($entry->properties->toArray(), JSON_THROW_ON_ERROR);
        expect($encoded)
            ->not->toContain('SECRET_WORKSPACE_LOG_LINE')
            ->not->toContain('/srv/apps')
            ->and($entry->properties->get('outcome'))->toBe('application_log.read_failed')
            ->and($entry->properties->get('mode'))->toBe('bounded')
            ->and($entry->properties->get('workspace'))->toBe('feature-docs')
            ->and($entry->properties->get('lines'))->toBe(10)
            ->and($entry->properties->get('node'))->toBe('app-dev-1')
            ->and($entry->properties->get('target'))->toBeNull();
    });
});
