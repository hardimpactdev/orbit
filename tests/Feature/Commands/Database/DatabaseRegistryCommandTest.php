<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Database\AddDatabaseConnectionRequest;
use App\Http\Gateway\Requests\Database\AttachDatabaseConnectionTargetRequest;
use App\Http\Gateway\Requests\Database\DetachDatabaseConnectionTargetRequest;
use App\Http\Gateway\Requests\Database\ListDatabaseConnectionsRequest;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createDatabaseRegistryLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "database-registry-{$role}",
        'role' => $role,
        'host' => '10.9.0.1',
        'wireguard_address' => '10.9.0.1',
    ]);
}

function configureDatabaseRegistryGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);
    createDatabaseRegistryLocalNode('gateway');
}

function configureDatabaseRegistryControlCaller(): void
{
    config(['orbit.is_gateway' => false]);
    createDatabaseRegistryLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.9.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();
}

describe('database registry commands', function (): void {
    it('lists scoped local database connections in the documented json shape', function (): void {
        configureDatabaseRegistryGatewayCaller();
        $node = createTestAppHostNode(['name' => 'db-node', 'role' => 'app']);
        $otherNode = createTestAppHostNode(['name' => 'other-node', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $visible = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'primary-db',
            'driver' => 'pgsql',
            'host' => 'postgres.internal',
            'port' => 5432,
            'database' => 'orbit',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->for($visible, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);
        DatabaseConnectionTarget::factory()->for($visible, 'connection')->forWorkspace($workspace)->create(['env_prefix' => 'ANALYTICS_DB']);

        DatabaseConnection::factory()->create([
            'node_id' => $otherNode->id,
            'slug' => 'hidden-db',
        ]);

        $exitCode = Artisan::call('database:list', ['--app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['meta']['count'])->toBe(1)
            ->and($payload['success']['data']['connections'])->toHaveCount(1)
            ->and($payload['success']['data']['connections'][0])->toMatchArray([
                'slug' => 'primary-db',
                'driver' => 'pgsql',
                'host' => 'postgres.internal',
                'port' => 5432,
                'database' => 'orbit',
                'path' => null,
                'username' => 'orbit',
                'node' => 'db-node',
            ])
            ->and($payload['success']['data']['connections'][0])->not->toHaveKey('password')
            ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('secret');
    });

    it('renders local database connections with the prompts table primitive', function (): void {
        configureDatabaseRegistryGatewayCaller();
        $node = createTestAppHostNode(['name' => 'db-node', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => null,
            'slug' => 'primary-db',
            'driver' => 'pgsql',
        ]);
        DatabaseConnectionTarget::factory()->for($connection, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);

        $exitCode = Artisan::call('database:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Showing 1 database connection(s).')
            ->and($output)->toContain('SLUG')
            ->and($output)->toContain('DRIVER')
            ->and($output)->toContain('NODE')
            ->and($output)->toContain('TARGETS')
            ->and($output)->toContain('primary-db')
            ->and($output)->toContain('—')
            ->and($output)->not->toContain('+---');
    });

    it('shows one local database connection without exposing passwords', function (): void {
        configureDatabaseRegistryGatewayCaller();
        $node = createTestAppHostNode(['name' => 'db-node', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'primary-db',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->for($connection, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);

        $exitCode = Artisan::call('database:show', ['connection' => 'primary-db', '--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['connection']['slug'])->toBe('primary-db')
            ->and($payload['success']['data']['connection']['targets'])->toBe([
                ['type' => 'app', 'name' => 'docs', 'env_prefix' => 'DB'],
            ])
            ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('secret');
    });

    it('shows one local database connection as key-value lines in human mode', function (): void {
        configureDatabaseRegistryGatewayCaller();
        DatabaseConnection::factory()->create([
            'node_id' => null,
            'slug' => 'primary-db',
            'driver' => 'sqlite',
            'path' => '/srv/docs/database/database.sqlite',
        ]);

        $exitCode = Artisan::call('database:show', ['connection' => 'primary-db']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Showing database connection 'primary-db'.")
            ->and($output)->toContain('Slug: primary-db')
            ->and($output)->toContain('Driver: sqlite')
            ->and($output)->toContain('Path: /srv/docs/database/database.sqlite')
            ->and($output)->toContain('Node: —')
            ->and($output)->not->toContain('+---');
    });

    it('creates, updates, attaches, detaches, and removes a connection locally', function (): void {
        configureDatabaseRegistryGatewayCaller();
        $node = createTestAppHostNode(['name' => 'db-node', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $createExitCode = Artisan::call('database:add', [
            'slug' => 'primary-db',
            '--driver' => 'pgsql',
            '--host' => 'postgres.internal',
            '--port' => '5432',
            '--database' => 'orbit',
            '--username' => 'orbit',
            '--password' => 'secret',
            '--node' => 'db-node',
            '--json' => true,
        ]);
        $createPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $connection = DatabaseConnection::query()->where('slug', 'primary-db')->firstOrFail();

        expect($createExitCode)->toBe(0)
            ->and($createPayload['success']['data']['connection']['slug'])->toBe('primary-db')
            ->and($connection->node_id)->toBe($node->id)
            ->and($connection->credentials)->toBe(['password' => 'secret'])
            ->and(json_encode($createPayload, JSON_THROW_ON_ERROR))->not->toContain('secret');

        $updateExitCode = Artisan::call('database:update', [
            'connection' => 'primary-db',
            '--slug' => 'renamed-db',
            '--clear-password' => true,
            '--json' => true,
        ]);
        $updatePayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($updateExitCode)->toBe(0)
            ->and($updatePayload['success']['data']['connection']['slug'])->toBe('renamed-db')
            ->and(DatabaseConnection::query()->where('slug', 'renamed-db')->firstOrFail()->credentials)->toBe([]);

        $attachExitCode = Artisan::call('database:attach', [
            'connection' => 'renamed-db',
            '--app' => 'docs',
            '--env-prefix' => 'DB',
            '--json' => true,
        ]);
        $attachPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($attachExitCode)->toBe(0)
            ->and($attachPayload['success']['data']['connection']['targets'])->toBe([
                ['type' => 'app', 'name' => 'docs', 'env_prefix' => 'DB'],
            ]);

        $detachExitCode = Artisan::call('database:detach', [
            'connection' => 'renamed-db',
            '--app' => 'docs',
            '--env-prefix' => 'DB',
            '--json' => true,
        ]);
        $detachPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($detachExitCode)->toBe(0)
            ->and($detachPayload['success']['data']['result'])->toBe([
                'action' => 'detached',
                'connection' => 'renamed-db',
                'target_type' => 'app',
                'target' => 'docs',
                'env_prefix' => 'DB',
            ]);

        Artisan::call('database:attach', [
            'connection' => 'renamed-db',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $removeExitCode = Artisan::call('database:remove', [
            'connection' => 'renamed-db',
            '--force' => true,
            '--json' => true,
        ]);
        $removePayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($removeExitCode)->toBe(0)
            ->and($removePayload['success']['data']['result'])->toBe([
                'action' => 'removed',
                'connection' => 'renamed-db',
            ])
            ->and(DatabaseConnection::query()->where('slug', 'renamed-db')->exists())->toBeFalse()
            ->and(DatabaseConnectionTarget::query()->count())->toBe(0);
    });

    it('requires force for json removal', function (): void {
        configureDatabaseRegistryGatewayCaller();
        DatabaseConnection::factory()->create(['slug' => 'primary-db']);

        $exitCode = Artisan::call('database:remove', [
            'connection' => 'primary-db',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe([
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ])
            ->and(DatabaseConnection::query()->where('slug', 'primary-db')->exists())->toBeTrue();
    });

    it('forwards non-gateway callers through typed gateway requests', function (): void {
        configureDatabaseRegistryControlCaller();

        MockClient::global([
            ListDatabaseConnectionsRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'connections' => [[
                            'slug' => 'primary-db',
                            'driver' => 'pgsql',
                            'host' => 'postgres.internal',
                            'port' => 5432,
                            'database' => 'orbit',
                            'path' => null,
                            'username' => 'orbit',
                            'node' => 'db-node',
                            'targets' => [],
                        ]],
                    ],
                    'meta' => ['count' => 1],
                ],
            ], 200),
            AddDatabaseConnectionRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'connection' => [
                            'slug' => 'remote-db',
                            'driver' => 'sqlite',
                            'host' => null,
                            'port' => null,
                            'database' => null,
                            'path' => '/srv/orbit/database.sqlite',
                            'username' => null,
                            'node' => 'db-node',
                            'targets' => [],
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $listExitCode = Artisan::call('database:list', ['--node' => 'db-node', '--json' => true]);
        $listPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($listExitCode)->toBe(0)
            ->and($listPayload['success']['data']['connections'][0]['slug'])->toBe('primary-db');

        $addExitCode = Artisan::call('database:add', [
            'slug' => 'remote-db',
            '--driver' => 'sqlite',
            '--node' => 'db-node',
            '--path' => '/srv/orbit/database.sqlite',
            '--json' => true,
        ]);
        $addPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($addExitCode)->toBe(0)
            ->and($addPayload['success']['data']['connection']['slug'])->toBe('remote-db');
    });

    it('forwards non-gateway attach and detach selectors without requiring local app rows', function (): void {
        configureDatabaseRegistryControlCaller();

        MockClient::global([
            AttachDatabaseConnectionTargetRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'connection' => [
                            'slug' => 'remote-db',
                            'driver' => 'pgsql',
                            'host' => 'postgres.internal',
                            'port' => 5432,
                            'database' => 'orbit',
                            'path' => null,
                            'username' => 'orbit',
                            'node' => 'db-node',
                            'targets' => [
                                ['type' => 'app', 'name' => 'docs', 'env_prefix' => 'DB'],
                            ],
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
            DetachDatabaseConnectionTargetRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'result' => [
                            'action' => 'detached',
                            'connection' => 'remote-db',
                            'target_type' => 'workspace',
                            'target' => 'feature-docs',
                            'env_prefix' => 'ANALYTICS_DB',
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $attachExitCode = Artisan::call('database:attach', [
            'connection' => 'remote-db',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $attachPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $detachExitCode = Artisan::call('database:detach', [
            'connection' => 'remote-db',
            '--workspace' => 'feature-docs',
            '--env-prefix' => 'ANALYTICS_DB',
            '--json' => true,
        ]);
        $detachPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($attachExitCode)->toBe(0)
            ->and($attachPayload['success']['data']['connection']['targets'][0])->toBe([
                'type' => 'app',
                'name' => 'docs',
                'env_prefix' => 'DB',
            ])
            ->and($detachExitCode)->toBe(0)
            ->and($detachPayload['success']['data']['result'])->toBe([
                'action' => 'detached',
                'connection' => 'remote-db',
                'target_type' => 'workspace',
                'target' => 'feature-docs',
                'env_prefix' => 'ANALYTICS_DB',
            ]);
    });

    it('does not leak passwords in command validation errors', function (): void {
        configureDatabaseRegistryGatewayCaller();

        $exitCode = Artisan::call('database:add', [
            'slug' => 'broken-db',
            '--driver' => 'pgsql',
            '--host' => 'postgres.internal',
            '--password' => 'super-secret',
            '--json' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->not->toContain('super-secret');
    });

    it('applies app and node filters together without leaking other node-owned matches', function (): void {
        configureDatabaseRegistryGatewayCaller();
        $node = createTestAppHostNode(['name' => 'db-node', 'role' => 'app']);
        $otherNode = createTestAppHostNode(['name' => 'other-node', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $otherAppOnNode = App::factory()->create(['name' => 'blog', 'node_id' => $node->id]);

        $attachedToDocs = DatabaseConnection::factory()->create(['slug' => 'docs-db']);
        DatabaseConnectionTarget::factory()->for($attachedToDocs, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);

        $attachedToOtherAppOnSameNode = DatabaseConnection::factory()->create(['slug' => 'blog-db']);
        DatabaseConnectionTarget::factory()->for($attachedToOtherAppOnSameNode, 'connection')->forApp($otherAppOnNode)->create(['env_prefix' => 'DB']);

        DatabaseConnection::factory()->create(['slug' => 'node-owned-db', 'node_id' => $node->id]);
        DatabaseConnection::factory()->create(['slug' => 'other-node-db', 'node_id' => $otherNode->id]);

        $exitCode = Artisan::call('database:list', [
            '--app' => 'docs',
            '--node' => 'db-node',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(array_column($payload['success']['data']['connections'], 'slug'))->toBe(['docs-db']);
    });

    it('fails add and update when node selectors are invalid', function (): void {
        configureDatabaseRegistryGatewayCaller();
        DatabaseConnection::factory()->create(['slug' => 'primary-db']);

        $addExitCode = Artisan::call('database:add', [
            'slug' => 'broken-db',
            '--driver' => 'sqlite',
            '--node' => 'missing-node',
            '--path' => '/srv/orbit/database.sqlite',
            '--json' => true,
        ]);
        $addPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $updateExitCode = Artisan::call('database:update', [
            'connection' => 'primary-db',
            '--node' => 'missing-node',
            '--json' => true,
        ]);
        $updatePayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($addExitCode)->toBe(1)
            ->and($addPayload['error']['code'])->toBe('validation_failed')
            ->and($addPayload['error']['meta']['field'])->toBe('node')
            ->and($updateExitCode)->toBe(1)
            ->and($updatePayload['error']['code'])->toBe('validation_failed')
            ->and($updatePayload['error']['meta']['field'])->toBe('node');
    });
});
