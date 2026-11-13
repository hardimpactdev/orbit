<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\AttachDatabaseConnectionAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/../Instances/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

/** A running container default with its own database under DB and a read-only catalogue connection on another Node. */
function sharedAttachmentFixture(): array
{
    $node = containerDefaultNode();
    $instance = Instance::query()->create(['node_id' => $node->id, 'project_id' => containerDefaultProject()->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => '.', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => false]);
    app(InstanceContainer::class)->reserve($instance, $node)->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    $instance->forceFill(['provisioning_step' => 'active'])->save();
    $own = DatabaseConnection::query()->forceCreate(['slug' => 'tool', 'driver' => DatabaseDriver::Pgsql, 'node_id' => $node->id,
        'owner_instance_id' => $instance->id, 'host' => $node->wireguard_ip, 'port' => 5432, 'database' => 'tool', 'username' => 'tool', 'password' => 'own-secret']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $instance->id, 'database_connection_id' => $own->id, 'prefix' => 'DB', 'network' => 'wireguard']);
    $catalogue = DatabaseConnection::query()->forceCreate(['slug' => 'food-reader', 'driver' => DatabaseDriver::Pgsql, 'node_id' => $node->id,
        'host' => '10.44.0.3', 'port' => 5434, 'database' => 'food_catalog', 'username' => 'food_catalog_reader', 'password' => 'reader-secret']);

    return [$instance->refresh(), $own, $catalogue];
}

function sharedMark(Instance $instance, string $prefix): ?bool
{
    return DatabaseConnectionTarget::query()->where('instance_id', $instance->id)->where('prefix', $prefix)->first()?->shared;
}

it('attaches a new connection unshared unless the caller marks it, and changes the mark only when the caller says so', function (): void {
    [$instance, , $catalogue] = sharedAttachmentFixture();
    $attach = app(AttachDatabaseConnectionAction::class);

    $attach->execute($instance, $catalogue, 'FOOD_CATALOG');
    expect(sharedMark($instance, 'FOOD_CATALOG'))->toBeFalse();

    // A change of the mark alone is a change, never "nothing to change".
    expect($attach->execute($instance, $catalogue, 'FOOD_CATALOG', shared: true)->changed)->toBeTrue()
        ->and(sharedMark($instance, 'FOOD_CATALOG'))->toBeTrue()
        ->and($attach->execute($instance, $catalogue, 'FOOD_CATALOG', shared: true)->changed)->toBeFalse();

    // A re-attach for another reason keeps the mark: the next clone still gets the connection.
    $attach->execute($instance, $catalogue, 'FOOD_CATALOG', replace: true);
    expect(sharedMark($instance, 'FOOD_CATALOG'))->toBeTrue();

    $attach->execute($instance, $catalogue, 'FOOD_CATALOG', shared: false);
    expect(sharedMark($instance, 'FOOD_CATALOG'))->toBeFalse();

    $attach->execute($instance, $catalogue, 'OTHER_CATALOG', shared: true);
    expect(sharedMark($instance, 'OTHER_CATALOG'))->toBeTrue();
});

function sharedRefusal(Closure $attach): ?string
{
    try {
        $attach();
    } catch (ResourceOperationException $exception) {
        return $exception->errorCode;
    }

    return null;
}

it('refuses to share the Instance\'s own database, however it is attached', function (Closure $arrange): void {
    [$instance, $own, $catalogue] = sharedAttachmentFixture();
    [$connection, $prefix] = $arrange($own, $catalogue, $instance);

    expect(sharedRefusal(fn () => app(AttachDatabaseConnectionAction::class)->execute($instance, $connection, $prefix, replace: true, shared: true)))
        ->toBe('database.shared_own_database')
        ->and(DatabaseConnectionTarget::query()->where('instance_id', $instance->id)->where('shared', true)->exists())->toBeFalse();
})->with([
    'under the DB prefix' => [fn (DatabaseConnection $own, DatabaseConnection $catalogue): array => [$catalogue, 'DB']],
    'the DB connection under another prefix' => [fn (DatabaseConnection $own): array => [$own, 'MAIN']],
    'a database Orbit made for this Instance' => [function (DatabaseConnection $own, DatabaseConnection $catalogue, Instance $instance): array {
        $catalogue->forceFill(['owner_instance_id' => $instance->id])->save();

        return [$catalogue, 'FOOD_CATALOG'];
    }],
]);

it('takes the mark from the add request body as a strict boolean, and keeps it when the body leaves it out', function (): void {
    [$instance] = sharedAttachmentFixture();
    $instance->node->accessibleNodes()->syncWithoutDetaching([$instance->node_id]);
    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);
    $uri = "/api/v1/instances/{$instance->id}/database-connections/food-reader";

    $this->putJson($uri, ['prefix' => 'FOOD_CATALOG', 'shared' => true])->assertOk()->assertJsonPath('data.changed', true);
    expect(sharedMark($instance, 'FOOD_CATALOG'))->toBeTrue();
    $this->putJson($uri, ['prefix' => 'FOOD_CATALOG'])->assertOk()->assertJsonPath('data.changed', false);
    expect(sharedMark($instance, 'FOOD_CATALOG'))->toBeTrue();
    $this->putJson($uri, ['prefix' => 'FOOD_CATALOG', 'shared' => 'yes'])->assertUnprocessable();
    $this->putJson("/api/v1/instances/{$instance->id}/database-connections/tool", ['prefix' => 'MAIN', 'shared' => true])
        ->assertUnprocessable()->assertJsonPath('error.code', 'database.shared_own_database');
});

it('checks every add against the mark the attachment keeps, so a shared prefix never takes the own database', function (): void {
    [$instance, $own, $catalogue] = sharedAttachmentFixture();
    $attach = app(AttachDatabaseConnectionAction::class);
    $attach->execute($instance, $catalogue, 'FOOD_CATALOG', shared: true);

    // The own database under the shared prefix, with no shared field: the recorded mark stays, so it is refused.
    expect(sharedRefusal(fn () => $attach->execute($instance, $own, 'FOOD_CATALOG', replace: true)))->toBe('database.shared_own_database')
        // The shared connection under DB would make it the own database while the Instance shares it.
        ->and(sharedRefusal(fn () => $attach->execute($instance, $catalogue, 'DB', replace: true)))->toBe('database.shared_own_database')
        ->and(DatabaseConnectionTarget::query()->where('instance_id', $instance->id)->pluck('database_connection_id', 'prefix')->all())
        ->toEqual(['DB' => $own->id, 'FOOD_CATALOG' => $catalogue->id]);
});

it('shares only a mysql or pgsql connection: a clone gets its own Valkey user, and a SQLite file stays in the workspace', function (DatabaseDriver $driver): void {
    [$instance, , $catalogue] = sharedAttachmentFixture();
    $catalogue->forceFill(['driver' => $driver, 'node_id' => $instance->node_id])->save();

    expect(sharedRefusal(fn () => app(AttachDatabaseConnectionAction::class)->execute($instance, $catalogue->refresh(), 'CACHE', shared: true)))
        ->toBe('database.shared_driver_unsupported')
        ->and(DatabaseConnectionTarget::query()->where('instance_id', $instance->id)->where('prefix', 'CACHE')->exists())->toBeFalse();
})->with([DatabaseDriver::Redis, DatabaseDriver::Sqlite]);
