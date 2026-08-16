<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\DatabaseConnections\DatabaseConnectionRegistry;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('DatabaseConnectionRegistry', function (): void {
    it('lists and shows connections ordered by slug', function (): void {
        $app = App::factory()->create();
        $instance = Instance::factory()->for($app)->create(['name' => 'production']);
        $first = DatabaseConnection::factory()->create(['slug' => 'zebra']);
        $second = DatabaseConnection::factory()->create(['slug' => 'alpha']);
        DatabaseConnectionTarget::query()->create([
            'database_connection_id' => $second->id,
            'instance_id' => $instance->id,
            'env_prefix' => 'DB',
        ]);

        $registry = app(DatabaseConnectionRegistry::class);

        expect($registry->list()->modelKeys())
            ->toBe([$second->id, $first->id])
            ->and($registry->list(instance: $instance)->modelKeys())
            ->toBe([$second->id])
            ->and($registry->show('alpha')?->is($second))
            ->toBeTrue();
    });

    it('filters node listings by concrete instance and workspace placement', function (): void {
        $logicalAppNode = Node::factory()->create(['name' => 'logical-app-node']);
        $instanceNode = Node::factory()->create(['name' => 'instance-node']);
        $unrelatedNode = Node::factory()->create(['name' => 'unrelated-node']);
        $app = App::factory()->create();
        $instance = Instance::factory()->for($app)->create([
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $instanceNode->id,
                path: '/srv/apps/docs',
            ),
        ]);
        $workspace = Workspace::factory()->for($app, 'app')->create([
            'instance_id' => $instance->id,
        ]);
        $instanceConnection = DatabaseConnection::factory()->create(['slug' => 'instance-db']);
        $workspaceConnection = DatabaseConnection::factory()->create(['slug' => 'workspace-db']);
        $directConnection = DatabaseConnection::factory()->for($logicalAppNode, 'node')->create([
            'slug' => 'logical-node-db',
        ]);
        DatabaseConnection::factory()->for($unrelatedNode, 'node')->create(['slug' => 'unrelated-db']);
        DatabaseConnectionTarget::factory()
            ->for($instanceConnection, 'connection')
            ->forInstance($instance)
            ->create();
        DatabaseConnectionTarget::factory()
            ->for($workspaceConnection, 'connection')
            ->forWorkspace($workspace)
            ->create();

        $registry = app(DatabaseConnectionRegistry::class);

        expect($registry->list(node: $instanceNode)->modelKeys())
            ->toBe([$instanceConnection->id, $workspaceConnection->id])
            ->and($registry->list(node: $logicalAppNode)->modelKeys())
            ->toBe([$directConnection->id]);
    });

    it('creates and updates tcp and sqlite connections with validation', function (): void {
        $registry = app(DatabaseConnectionRegistry::class);
        $node = Node::factory()->create();

        $created = $registry->create('primary-db', [
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => '5432',
            'database' => 'orbit',
            'username' => 'orbit',
            'password' => 'secret',
        ]);

        expect($created)
            ->toBeInstanceOf(DatabaseConnection::class)
            ->and($created)
            ->toMatchArray([
                'slug' => 'primary-db',
                'driver' => 'pgsql',
                'host' => 'db.internal',
                'port' => 5432,
                'database' => 'orbit',
                'username' => 'orbit',
                'path' => null,
            ])
            ->and($created->credentials)
            ->toBe(['password' => 'secret']);

        $updated = $registry->update('primary-db', [
            'driver' => 'sqlite',
            'node_id' => $node->id,
            'path' => '/srv/orbit/database.sqlite',
            'password' => null,
        ]);

        expect($updated)
            ->toBeInstanceOf(DatabaseConnection::class)
            ->and($updated)
            ->toMatchArray([
                'slug' => 'primary-db',
                'driver' => 'sqlite',
                'node_id' => $node->id,
                'host' => null,
                'port' => null,
                'database' => null,
                'path' => '/srv/orbit/database.sqlite',
                'username' => null,
            ])
            ->and($updated->credentials)
            ->toBe([]);
    });

    it('merges update attributes onto the existing connection and preserves credentials unless cleared', function (): void {
        $registry = app(DatabaseConnectionRegistry::class);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'primary-db',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'orbit',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);

        $renamed = $registry->update('primary-db', [
            'slug' => 'renamed-db',
        ]);

        expect($renamed)
            ->toBeInstanceOf(DatabaseConnection::class)
            ->and($renamed)
            ->toMatchArray([
                'slug' => 'renamed-db',
                'driver' => 'pgsql',
                'host' => 'db.internal',
                'port' => 5432,
                'database' => 'orbit',
                'username' => 'orbit',
            ])
            ->and($renamed->credentials)
            ->toBe(['password' => 'secret']);

        $updatedPassword = $registry->update('renamed-db', [
            'password' => 'updated-secret',
        ]);

        expect($updatedPassword)
            ->toBeInstanceOf(DatabaseConnection::class)
            ->and($updatedPassword->credentials)
            ->toBe(['password' => 'updated-secret']);

        $clearedPassword = $registry->update('renamed-db', [
            'clear_password' => true,
        ]);

        expect($clearedPassword)
            ->toBeInstanceOf(DatabaseConnection::class)
            ->and($clearedPassword->credentials)
            ->toBe([]);
    });

    it('rejects invalid create and update payloads cleanly', function (): void {
        $registry = app(DatabaseConnectionRegistry::class);
        $connection = DatabaseConnection::factory()->create(['slug' => 'primary-db']);
        DatabaseConnection::factory()->create(['slug' => 'analytics-db']);
        $tooLongSlug = str_repeat('a', 41);

        $invalidSlug = $registry->create('Primary_DB', [
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'orbit',
            'username' => 'orbit',
        ]);

        $missingTcpFields = $registry->create('secondary-db', [
            'driver' => 'mysql',
            'host' => 'db.internal',
        ]);

        $missingTcpUsername = $registry->create('tertiary-db', [
            'driver' => 'mysql',
            'host' => 'db.internal',
            'port' => 3306,
            'database' => 'orbit',
        ]);

        $missingSqliteFields = $registry->update($connection->slug, [
            'driver' => 'sqlite',
            'path' => '/srv/orbit/database.sqlite',
        ]);

        $unsupportedDriver = $registry->update($connection->slug, [
            'driver' => 'sqlserver',
        ]);

        $duplicateSlug = $registry->update($connection->slug, [
            'slug' => 'analytics-db',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'orbit',
            'username' => 'orbit',
        ]);

        $tooLong = $registry->create($tooLongSlug, [
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'orbit',
            'username' => 'orbit',
        ]);

        $startsWithHyphen = $registry->create('-primary-db', [
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'orbit',
            'username' => 'orbit',
        ]);

        $endsWithHyphen = $registry->create('primary-db-', [
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'orbit',
            'username' => 'orbit',
        ]);

        expect($invalidSlug)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($invalidSlug->code)
            ->toBe('validation_failed')
            ->and($missingTcpFields)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($missingTcpFields->meta['field'])
            ->toBe('payload')
            ->and($missingTcpUsername)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($missingTcpUsername->message)
            ->toContain('require username')
            ->and($missingSqliteFields)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($missingSqliteFields->meta['field'])
            ->toBe('payload')
            ->and($unsupportedDriver)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($unsupportedDriver->meta['field'])
            ->toBe('driver')
            ->and($duplicateSlug)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($duplicateSlug->code)
            ->toBe('database_connection.slug_taken')
            ->and($duplicateSlug->meta['field'])
            ->toBe('slug')
            ->and($tooLong)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($tooLong->meta['field'])
            ->toBe('slug')
            ->and($startsWithHyphen)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($startsWithHyphen->meta['field'])
            ->toBe('slug')
            ->and($endsWithHyphen)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($endsWithHyphen->meta['field'])
            ->toBe('slug');
    });

    it('attaches and detaches workspace and instance targets with conflict handling', function (): void {
        $app = App::factory()->create();
        $instance = Instance::factory()->for($app)->create(['name' => 'production']);
        $workspace = Workspace::factory()->create();
        $primary = DatabaseConnection::factory()->create(['slug' => 'primary-db']);
        $analytics = DatabaseConnection::factory()->create(['slug' => 'analytics-db']);
        $registry = app(DatabaseConnectionRegistry::class);

        $instanceTarget = $registry->attachToInstance('primary-db', $instance, 'DB');
        $idempotentInstanceTarget = $registry->attachToInstance('primary-db', $instance, 'DB');
        $instanceConflict = $registry->attachToInstance('analytics-db', $instance, 'DB');

        $workspaceTarget = $registry->attachToWorkspace('analytics-db', $workspace, 'ANALYTICS_DB');
        $idempotentWorkspaceTarget = $registry->attachToWorkspace('analytics-db', $workspace, 'ANALYTICS_DB');
        $workspaceConflict = $registry->attachToWorkspace('primary-db', $workspace, 'ANALYTICS_DB');

        expect($instanceTarget)
            ->toBeInstanceOf(DatabaseConnectionTarget::class)
            ->and($instanceTarget->database_connection_id)
            ->toBe($primary->id)
            ->and($idempotentInstanceTarget)
            ->toBeInstanceOf(DatabaseConnectionTarget::class)
            ->and($idempotentInstanceTarget->id)
            ->toBe($instanceTarget->id)
            ->and($instanceConflict)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($instanceConflict->code)
            ->toBe('database_connection.target_conflict')
            ->and($workspaceTarget)
            ->toBeInstanceOf(DatabaseConnectionTarget::class)
            ->and($workspaceTarget->database_connection_id)
            ->toBe($analytics->id)
            ->and($idempotentWorkspaceTarget)
            ->toBeInstanceOf(DatabaseConnectionTarget::class)
            ->and($idempotentWorkspaceTarget->id)
            ->toBe($workspaceTarget->id)
            ->and($workspaceConflict)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($workspaceConflict->code)
            ->toBe('database_connection.target_conflict')
            ->and(DatabaseConnectionTarget::query()->count())
            ->toBe(2);

        $detachedInstanceTarget = $registry->detachFromInstance('primary-db', $instance, 'DB');
        $missingInstanceDetach = $registry->detachFromInstance('primary-db', $instance, 'DB');
        $detachedWorkspaceTarget = $registry->detachFromWorkspace('analytics-db', $workspace, 'ANALYTICS_DB');
        $missingWorkspaceDetach = $registry->detachFromWorkspace('analytics-db', $workspace, 'ANALYTICS_DB');
        expect($detachedInstanceTarget)
            ->toBeInstanceOf(DatabaseConnectionTarget::class)
            ->and($detachedInstanceTarget->exists)
            ->toBeFalse()
            ->and($missingInstanceDetach)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($missingInstanceDetach->code)
            ->toBe('database_connection.target_not_found')
            ->and($detachedWorkspaceTarget)
            ->toBeInstanceOf(DatabaseConnectionTarget::class)
            ->and($detachedWorkspaceTarget->exists)
            ->toBeFalse()
            ->and($missingWorkspaceDetach)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($missingWorkspaceDetach->code)
            ->toBe('database_connection.target_not_found')
            ->and(DatabaseConnectionTarget::query()->count())
            ->toBe(0);
    });

    it('blocks remove when targets exist unless forced', function (): void {
        $app = App::factory()->create();
        $instance = Instance::factory()->for($app)->create(['name' => 'production']);
        $connection = DatabaseConnection::factory()->create(['slug' => 'primary-db']);
        DatabaseConnectionTarget::query()->create([
            'database_connection_id' => $connection->id,
            'instance_id' => $instance->id,
            'env_prefix' => 'DB',
        ]);
        $registry = app(DatabaseConnectionRegistry::class);

        $blocked = $registry->remove('primary-db');

        expect($blocked)
            ->toBeInstanceOf(DatabaseConnectionRegistryFailure::class)
            ->and($blocked->code)
            ->toBe('database_connection.has_targets')
            ->and(DatabaseConnection::query()->whereKey($connection->id)->exists())
            ->toBeTrue()
            ->and(
                DatabaseConnectionTarget::query()
                    ->where('database_connection_id', $connection->id)
                    ->exists(),
            )
            ->toBeTrue();

        $removed = $registry->remove('primary-db', force: true);

        expect($removed)
            ->toBeTrue()
            ->and(DatabaseConnection::query()->whereKey($connection->id)->exists())
            ->toBeFalse()
            ->and(
                DatabaseConnectionTarget::query()
                    ->where('database_connection_id', $connection->id)
                    ->exists(),
            )
            ->toBeFalse();
    });
});
