<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const INSTANCE_APP_LOG_CALLER_WG_IP = '10.6.0.195';

function create_instance_app_log_caller(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => INSTANCE_APP_LOG_CALLER_WG_IP,
        'managed' => true,
        'wireguard_address' => INSTANCE_APP_LOG_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grant_instance_read(Node $caller, Node $serving): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $serving->id,
        'permissions' => json_encode(['instance:read'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function seed_instance_for_app_log(Node $serving, string $path = '/srv/apps/docs'): Instance
{
    $app = App::factory()->create([
        'name' => 'docs',
    ]);

    return Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $serving->id,
            node: $serving->name,
            path: $path,
            document_root: 'public',
            domain: 'docs.test',
        ),
    ]);
}

function fake_application_log_agent(array $data, int $exitCode = 0): void
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

describe('Instance application log API', function (): void {
    it('returns the bounded envelope for authorized instance:read callers when the file is missing', function (): void {
        $caller = create_instance_app_log_caller();
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.45',
        ]);
        grant_instance_read($caller, $serving);
        seed_instance_for_app_log($serving);
        fake_application_log_agent([
            'file_exists' => false,
            'lines' => [],
            'path' => 'storage/logs/laravel.log',
        ]);

        $response = $this->call(
            'GET',
            '/api/instances/docs.development/log',
            ['lines' => 100],
            server: [
                'REMOTE_ADDR' => INSTANCE_APP_LOG_CALLER_WG_IP,
            ],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.path', 'storage/logs/laravel.log')
            ->assertJsonPath('success.data.file_exists', false)
            ->assertJsonPath('success.data.lines', [])
            ->assertJsonPath('success.data.target.type', 'instance')
            ->assertJsonPath('success.data.target.selector', 'docs.development')
            ->assertJsonPath('success.data.lines_requested', 100);
    });

    it('requires instance:read and never accepts process:log for application logs', function (): void {
        $caller = create_instance_app_log_caller();
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.45',
        ]);
        DB::table('node_access')->insert([
            'consumer_node_id' => $caller->id,
            'serving_node_id' => $serving->id,
            'permissions' => json_encode(['process:log'], JSON_THROW_ON_ERROR),
            'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        seed_instance_for_app_log($serving);

        $response = $this->call(
            'GET',
            '/api/instances/docs.development/log',
            server: [
                'REMOTE_ADDR' => INSTANCE_APP_LOG_CALLER_WG_IP,
            ],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.meta.missing_permission', 'instance:read');
    });

    it('rejects a mismatched node constraint', function (): void {
        $caller = create_instance_app_log_caller(role: 'gateway');
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.45',
        ]);
        seed_instance_for_app_log($serving);

        $response = $this->call(
            'GET',
            '/api/instances/docs.development/log',
            ['node' => 'other-node'],
            server: [
                'REMOTE_ADDR' => INSTANCE_APP_LOG_CALLER_WG_IP,
            ],
        );

        $response->assertStatus(422);
        expect($response->json('error.code'))->not->toBeNull();
    });

    it('rejects non-integer lines values without truncation', function (mixed $lines): void {
        $caller = create_instance_app_log_caller(role: 'gateway');
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.45',
        ]);
        seed_instance_for_app_log($serving);

        $response = $this->call(
            'GET',
            '/api/instances/docs.development/log',
            ['lines' => $lines],
            server: [
                'REMOTE_ADDR' => INSTANCE_APP_LOG_CALLER_WG_IP,
            ],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'lines');
    })->with(['1.5', '1e3', '0', '-3', '999999999999999999999999999']);

    it('accepts PHP_INT_MAX as a strict positive lines value', function (): void {
        $caller = create_instance_app_log_caller();
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.45',
        ]);
        grant_instance_read($caller, $serving);
        seed_instance_for_app_log($serving);
        fake_application_log_agent([
            'file_exists' => false,
            'lines' => [],
            'path' => 'storage/logs/laravel.log',
        ]);

        $this
            ->call(
                'GET',
                '/api/instances/docs.development/log',
                ['lines' => (string) PHP_INT_MAX],
                server: [
                    'REMOTE_ADDR' => INSTANCE_APP_LOG_CALLER_WG_IP,
                ],
            )
            ->assertOk()
            ->assertJsonPath('success.data.lines_requested', PHP_INT_MAX);
    });

    it('records success activity without log contents or absolute paths', function (): void {
        $caller = create_instance_app_log_caller();
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.45',
        ]);
        grant_instance_read($caller, $serving);
        seed_instance_for_app_log($serving);
        fake_application_log_agent([
            'file_exists' => true,
            'lines' => ['SECRET_LOG_LINE_SHOULD_NOT_BE_AUDITED'],
            'path' => 'storage/logs/laravel.log',
        ]);

        $this->call(
            'GET',
            '/api/instances/docs.development/log',
            ['lines' => 50, 'node' => 'app-dev-1'],
            server: [
                'REMOTE_ADDR' => INSTANCE_APP_LOG_CALLER_WG_IP,
                'HTTP_X_ORBIT_APPLICATION_LOG_REQUESTED_TARGET' => 'docs.test',
            ],
        )->assertOk();

        $entry = \Spatie\Activitylog\Models\Activity::query()->latest('id')->first();

        expect($entry)->not->toBeNull();
        $encoded = json_encode($entry->properties->toArray(), JSON_THROW_ON_ERROR);
        expect($encoded)
            ->not->toContain('SECRET_LOG_LINE_SHOULD_NOT_BE_AUDITED')
            ->not->toContain('/srv/apps')->and($entry->properties->get('mode'))->toBe(
                'bounded',
            )->and($entry->properties->get('lines'))->toBe(50)->and($entry->properties->get('selector'))->toBe(
                'docs.development',
            )->and($entry->properties->get('requested_target'))->toBe('docs.test')->and($entry->properties->get(
                'node',
            ))->toBe(
                'app-dev-1',
            )->and($entry->properties->get('outcome'))->toBe(
                'success',
            );
    });

    it('records post-authorization read_failed activity without log contents or absolute paths', function (): void {
        $caller = create_instance_app_log_caller();
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.45',
        ]);
        grant_instance_read($caller, $serving);
        seed_instance_for_app_log($serving);
        // Authorized request; executor failure is a controller-reachable path.
        // Grant middleware denials never reach Loggable controllers.
        fake_application_log_agent([
            'file_exists' => true,
            'lines' => ['SECRET_LOG_LINE_SHOULD_NOT_BE_AUDITED'],
            'path' => '/srv/apps/docs/storage/logs/laravel.log',
        ], exitCode: 1);

        $this
            ->call(
                'GET',
                '/api/instances/docs.development/log',
                ['lines' => 10, 'node' => 'app-dev-1'],
                server: [
                    'REMOTE_ADDR' => INSTANCE_APP_LOG_CALLER_WG_IP,
                ],
            )
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'application_log.read_failed');

        $entry = \Spatie\Activitylog\Models\Activity::query()->latest('id')->first();

        expect($entry)->not->toBeNull();
        $encoded = json_encode($entry->properties->toArray(), JSON_THROW_ON_ERROR);
        expect($encoded)
            ->not->toContain('SECRET_LOG_LINE_SHOULD_NOT_BE_AUDITED')
            ->not->toContain('/srv/apps')->and($entry->properties->get('outcome'))->toBe(
                'application_log.read_failed',
            )->and($entry->properties->get('mode'))->toBe('bounded')->and($entry->properties->get('selector'))->toBe(
                'docs.development',
            )->and($entry->properties->get('lines'))->toBe(10)->and($entry->properties->get('node'))->toBe(
                'app-dev-1',
            )->and($entry->properties->get('target'))->toBeNull();
    });

    it('starts a follow stream operation for authorized callers', function (): void {
        config()->set('orbit.operation_token_secret', 'instance-app-log-stream-secret');
        $caller = create_instance_app_log_caller(role: 'gateway');
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.45',
        ]);
        seed_instance_for_app_log($serving);
        fake_application_log_agent([
            'file_exists' => true,
            'lines' => [],
            'path' => 'storage/logs/laravel.log',
        ]);

        $response = $this->call(
            'POST',
            '/api/instances/docs.development/log-stream',
            content: json_encode(['lines' => 50], JSON_THROW_ON_ERROR),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => INSTANCE_APP_LOG_CALLER_WG_IP,
            ],
        );

        $response->assertStatus(202);
        expect($response->json('success.data.operation.uuid'))->toBeString()->not->toBe('');
    });

    it('records stream-start failure activity without log contents or absolute paths', function (): void {
        config()->set('orbit.operation_token_secret', 'instance-app-log-stream-fail-secret');
        $caller = create_instance_app_log_caller();
        $serving = createTestAppHostNode([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.45',
        ]);
        grant_instance_read($caller, $serving);
        seed_instance_for_app_log($serving);
        // Authorized stream-start; node constraint fails after grant middleware.
        // Do not fake the executor so activity cannot leak remote path/content.

        $this
            ->call(
                'POST',
                '/api/instances/docs.development/log-stream',
                content: json_encode([
                    'lines' => 25,
                    'node' => 'other-node',
                ], JSON_THROW_ON_ERROR),
                server: [
                    'CONTENT_TYPE' => 'application/json',
                    'REMOTE_ADDR' => INSTANCE_APP_LOG_CALLER_WG_IP,
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
            ->not->toContain('SECRET_')->and($entry->properties->get('outcome'))->toBe(
                'validation_failed',
            )->and($entry->properties->get('mode'))->toBe('follow')->and($entry->properties->get('selector'))->toBe(
                'docs.development',
            )->and($entry->properties->get('lines'))->toBe(25)->and($entry->properties->get('node'))->toBe(
                'other-node',
            )->and($entry->properties->get('target'))->toBeNull();
    });
});
