<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const DATABASE_API_CALLER_WG_IP = '10.9.0.97';

function createDatabaseApiCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'database-api-caller',
        'role' => 'control',
        'host' => DATABASE_API_CALLER_WG_IP,
        'wireguard_address' => DATABASE_API_CALLER_WG_IP,
    ], $overrides));
}

function assignDatabaseApiGatewayRole(Node $node): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);
}

describe('database connection api', function (): void {
    it('allows active control callers without a gateway role to use registry endpoints', function (): void {
        createDatabaseApiCallerNode();
        $node = createTestAppHostNode(['name' => 'db-node', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $connection = DatabaseConnection::factory()->create(['slug' => 'primary-db', 'node_id' => $node->id]);
        DatabaseConnectionTarget::factory()->for($connection, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);

        $listResponse = $this->call('GET', '/api/database-connections', [], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);
        $attachResponse = $this->call('POST', '/api/database-connections/primary-db/targets', [
            'app' => 'docs',
            'env_prefix' => 'ANALYTICS_DB',
        ], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $listResponse->assertOk()
            ->assertJsonPath('success.data.connections.0.slug', 'primary-db');
        $attachResponse->assertOk();

        expect($attachResponse->json('success.data.connection.targets'))
            ->toContain([
                'type' => 'app',
                'name' => 'docs',
                'env_prefix' => 'ANALYTICS_DB',
            ]);
    });

    it('lists and shows canonical database entities without passwords', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        $node = createTestAppHostNode(['name' => 'db-node', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'primary-db',
            'node_id' => $node->id,
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->for($connection, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);

        $listResponse = $this->call('GET', '/api/database-connections', [], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);
        $showResponse = $this->call('GET', '/api/database-connections/primary-db', [], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $listResponse->assertOk()
            ->assertJsonPath('success.meta.count', 1)
            ->assertJsonPath('success.data.connections.0.slug', 'primary-db');
        $showResponse->assertOk()
            ->assertJsonPath('success.data.connection.slug', 'primary-db')
            ->assertJsonPath('success.data.connection.targets.0', [
                'type' => 'app',
                'name' => 'docs',
                'env_prefix' => 'DB',
            ]);

        expect($listResponse->getContent())->not->toContain('secret')
            ->and($showResponse->getContent())->not->toContain('secret');
    });

    it('creates, updates, attaches, detaches, and removes connections with activity logs', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        $node = createTestAppHostNode(['name' => 'db-node', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $createResponse = $this->call('POST', '/api/database-connections', [
            'slug' => 'primary-db',
            'driver' => 'pgsql',
            'host' => 'postgres.internal',
            'port' => 5432,
            'database' => 'orbit',
            'username' => 'orbit',
            'password' => 'secret',
            'node' => 'db-node',
        ], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $createResponse->assertOk()
            ->assertJsonPath('success.data.connection.slug', 'primary-db');

        $connection = DatabaseConnection::query()->where('slug', 'primary-db')->firstOrFail();

        expect($createResponse->getContent())->not->toContain('secret')
            ->and($connection->credentials)->toBe(['password' => 'secret']);

        $updateResponse = $this->call('PATCH', '/api/database-connections/primary-db', [
            'slug' => 'renamed-db',
            'clear_password' => true,
        ], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $updateResponse->assertOk()
            ->assertJsonPath('success.data.connection.slug', 'renamed-db');

        expect(DatabaseConnection::query()->where('slug', 'renamed-db')->firstOrFail()->credentials)->toBe([]);

        $attachResponse = $this->call('POST', '/api/database-connections/renamed-db/targets', [
            'workspace' => 'feature-docs',
            'env_prefix' => 'ANALYTICS_DB',
        ], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $attachResponse->assertOk()
            ->assertJsonPath('success.data.connection.targets.0', [
                'type' => 'workspace',
                'name' => 'feature-docs',
                'env_prefix' => 'ANALYTICS_DB',
            ]);

        $detachResponse = $this->call('DELETE', '/api/database-connections/renamed-db/targets', [
            'workspace' => 'feature-docs',
            'env_prefix' => 'ANALYTICS_DB',
        ], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $detachResponse->assertOk()
            ->assertJsonPath('success.data.result', [
                'action' => 'detached',
                'connection' => 'renamed-db',
                'target_type' => 'workspace',
                'target' => 'feature-docs',
                'env_prefix' => 'ANALYTICS_DB',
            ]);

        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => DatabaseConnection::query()->where('slug', 'renamed-db')->firstOrFail()->id,
            'env_prefix' => 'DB',
        ]);

        $removeResponse = $this->call('DELETE', '/api/database-connections/renamed-db', [
            'force' => true,
        ], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $removeResponse->assertOk()
            ->assertJsonPath('success.data.result', [
                'action' => 'removed',
                'connection' => 'renamed-db',
            ]);

        expect(DatabaseConnection::query()->where('slug', 'renamed-db')->exists())->toBeFalse()
            ->and(DatabaseConnectionTarget::query()->count())->toBe(0);

        $properties = Activity::query()->pluck('properties')->all();
        $loggedJson = json_encode($properties, JSON_THROW_ON_ERROR);

        expect($loggedJson)->not->toContain('secret')
            ->and($loggedJson)->toContain('renamed-db')
            ->and($loggedJson)->toContain('ANALYTICS_DB');
    });

    it('returns documented validation and not-found error envelopes', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        $node = createTestAppHostNode(['name' => 'db-node', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $connection = DatabaseConnection::factory()->create(['slug' => 'primary-db']);
        DatabaseConnectionTarget::factory()->for($connection, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);

        $removeWithoutForce = $this->call('DELETE', '/api/database-connections/primary-db', [], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);
        $missingShow = $this->call('GET', '/api/database-connections/missing-db', [], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);
        $invalidAttach = $this->call('POST', '/api/database-connections/primary-db/targets', [
            'app' => 'docs',
            'workspace' => 'feature-docs',
        ], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $removeWithoutForce->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force')
            ->assertJsonPath('error.meta.reason', 'destructive_consent_required');
        $missingShow->assertNotFound()
            ->assertJsonPath('error.code', 'database_connection.not_found');
        $invalidAttach->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
    });

    it('does not leak passwords in api validation errors', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);

        $response = $this->call('POST', '/api/database-connections', [
            'slug' => 'broken-db',
            'driver' => 'pgsql',
            'host' => 'postgres.internal',
            'password' => 'super-secret',
        ], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        expect($response->getContent())->not->toContain('super-secret');
    });

    it('fails create and update when api node selectors are invalid', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        DatabaseConnection::factory()->create(['slug' => 'primary-db']);

        $createResponse = $this->call('POST', '/api/database-connections', [
            'slug' => 'broken-db',
            'driver' => 'sqlite',
            'node' => 'missing-node',
            'path' => '/srv/orbit/database.sqlite',
        ], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $updateResponse = $this->call('PATCH', '/api/database-connections/primary-db', [
            'node' => 'missing-node',
        ], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $createResponse->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node');
        $updateResponse->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node');
    });

    it('does not rewrite env files during attach and detach', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        $node = createTestAppHostNode(['name' => 'db-node', 'role' => 'app']);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/srv/apps/docs',
        ]);
        $connection = DatabaseConnection::factory()->create(['slug' => 'primary-db']);

        $before = DB::table('database_connection_targets')->count();

        $attachResponse = $this->call('POST', '/api/database-connections/primary-db/targets', [
            'app' => 'docs',
            'env_prefix' => 'DB',
        ], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $attachResponse->assertOk();

        $detachResponse = $this->call('DELETE', '/api/database-connections/primary-db/targets', [
            'app' => 'docs',
            'env_prefix' => 'DB',
        ], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $detachResponse->assertOk();

        expect($before)->toBe(0)
            ->and(DB::table('database_connection_targets')->count())->toBe(0)
            ->and(file_exists('/srv/apps/docs/.env'))->toBeFalse();
    });
});
