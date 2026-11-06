<?php

declare(strict_types=1);

use App\Actions\Instances\ShowInstanceQueueAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\Queue\InstanceQueueReader;
use App\Domain\Instances\Queue\InstanceQueueTarget;
use App\Domain\Instances\Queue\InstanceQueueTargets;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\StoreInstanceQueueReader;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Predis\Client;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

/** @param array<string, string> $environment */
function queue_instance(array $environment = []): Instance
{
    static $count = 0;
    $count++;
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => 'apps-'.$count, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.'.($count + 1), 'public_ssh_host' => '10.44.0.'.($count + 1), 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'site', 'slug' => 'site-'.$count, 'repository_url' => 'https://example.test/site-'.$count.'.git', 'default_branch' => 'main', 'root' => 'public']);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'shop-'.$count, 'environment' => 'production', 'status' => 'active',
        'checkout_path' => '/home/orbit-app-1/releases/initial', 'root' => 'public', 'provisioning_step' => 'active',
    ]);
    foreach ($environment as $key => $value) {
        InstanceEnvironmentValue::query()->create(['instance_id' => $instance->id, 'env_key' => $key, 'env_value' => $value]);
    }

    return $instance;
}

function queue_connect(Instance $instance, DatabaseDriver $driver, string $prefix): DatabaseConnection
{
    $connection = DatabaseConnection::query()->create([
        'slug' => strtolower($prefix).'-'.$driver->value, 'driver' => $driver, 'host' => '10.44.0.9', 'port' => $driver->defaultPort(),
        'database' => $driver === DatabaseDriver::Redis ? '2' : 'shop', 'username' => 'shop', 'password' => 'secret',
    ]);
    DatabaseConnectionTarget::query()->create(['database_connection_id' => $connection->id, 'instance_id' => $instance->id, 'prefix' => $prefix, 'network' => 'wireguard']);

    return $connection;
}

function queue_sqlite(): Connection
{
    $database = DB::build(['driver' => 'sqlite', 'database' => ':memory:']);
    $schema = $database->getSchemaBuilder();
    $schema->create('jobs', static function ($table): void {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('created_at')->nullable();
    });
    $schema->create('failed_jobs', static function ($table): void {
        $table->id();
        $table->string('uuid');
        $table->string('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->nullable();
    });

    return $database;
}

function queue_payload(string $class, ?float $pushedAt = null): string
{
    return json_encode(['uuid' => 'u-'.$class, 'displayName' => $class, 'pushedAt' => $pushedAt, 'data' => ['command' => 'customer-secret']], JSON_THROW_ON_ERROR);
}

it('finds no queue store without a queue connection in the stored environment', function (): void {
    expect(app(InstanceQueueTargets::class)->for(queue_instance()))->toBeNull()
        ->and(app(InstanceQueueTargets::class)->for(queue_instance(['QUEUE_CONNECTION' => 'sync'])))->toBeNull();
});

it('finds a Redis queue store with Laravel\'s default prefix and queue', function (): void {
    $instance = queue_instance(['QUEUE_CONNECTION' => 'redis', 'APP_NAME' => 'My Shop']);
    $redis = queue_connect($instance, DatabaseDriver::Redis, 'REDIS');
    $database = queue_connect($instance, DatabaseDriver::Mysql, 'DB');

    $target = app(InstanceQueueTargets::class)->for($instance);

    expect($target?->driver)->toBe('redis')
        ->and($target?->store->is($redis))->toBeTrue()
        ->and($target?->failed?->is($database))->toBeTrue()
        ->and($target?->prefix)->toBe('my_shop_database_')
        ->and($target?->queues)->toBe(['default']);
});

it('takes the prefix and queue of a Redis store from the stored environment', function (): void {
    $instance = queue_instance(['QUEUE_CONNECTION' => 'redis', 'REDIS_PREFIX' => 'shop:', 'REDIS_QUEUE' => 'mail']);
    queue_connect($instance, DatabaseDriver::Redis, 'REDIS');

    $target = app(InstanceQueueTargets::class)->for($instance);

    expect($target?->prefix)->toBe('shop:')->and($target?->queues)->toBe(['mail'])->and($target?->failed)->toBeNull();
});

it('needs a registered Redis connection for a Redis queue and a mysql or pgsql one for a database queue', function (): void {
    $redis = queue_instance(['QUEUE_CONNECTION' => 'redis']);
    queue_connect($redis, DatabaseDriver::Mysql, 'DB');
    $database = queue_instance(['QUEUE_CONNECTION' => 'database']);
    queue_connect($database, DatabaseDriver::Sqlite, 'DB');

    expect(app(InstanceQueueTargets::class)->for($redis))->toBeNull()
        ->and(app(InstanceQueueTargets::class)->for($database))->toBeNull();
});

it('reads a database queue and its failed jobs without returning a payload', function (): void {
    $sqlite = queue_sqlite();
    $sqlite->table('jobs')->insert([
        ['queue' => 'default', 'payload' => queue_payload('App\\Jobs\\SendInvoice', 1_700_000_000.5), 'reserved_at' => null, 'created_at' => 1_700_000_001],
        ['queue' => 'default', 'payload' => queue_payload('App\\Jobs\\SyncStock', 1_700_000_100.0), 'reserved_at' => null, 'created_at' => 1_700_000_101],
        ['queue' => 'mail', 'payload' => queue_payload('App\\Jobs\\Digest'), 'reserved_at' => null, 'created_at' => 1_700_000_300],
        ['queue' => 'mail', 'payload' => queue_payload('App\\Jobs\\Running'), 'reserved_at' => 1_700_000_200, 'created_at' => 1_700_000_200],
    ]);
    $sqlite->table('failed_jobs')->insert([
        'uuid' => 'f-1', 'queue' => 'mail', 'payload' => queue_payload('App\\Jobs\\SendReceipt'),
        'exception' => "RuntimeException: The mail server refused.\n#0 /app/Mail.php(10): send('customer-secret')", 'failed_at' => '2026-11-06 10:00:00',
    ]);
    $reader = new StoreInstanceQueueReader(fn (): Connection => $sqlite);
    $store = new DatabaseConnection(['driver' => DatabaseDriver::Mysql]);
    $target = new InstanceQueueTarget('database', $store, $store, '', []);

    $pending = $reader->read($target, 'pending', 2);
    $failed = $reader->read($target, 'failed', 50);

    expect($pending['pending'])->toBe(3)
        ->and($pending['failed'])->toBe(1)
        ->and($pending['queues'])->toBe([['name' => 'default', 'length' => 2], ['name' => 'mail', 'length' => 1]])
        ->and(array_column($pending['jobs'], 'name'))->toBe(['App\\Jobs\\Digest', 'App\\Jobs\\SyncStock'])
        ->and(array_column($pending['jobs'], 'pushed_at'))->toBe([1_700_000_300, 1_700_000_100])
        ->and($failed['jobs'])->toHaveCount(1)
        ->and($failed['jobs'][0]['id'])->toBe('f-1')
        ->and($failed['jobs'][0]['exception'])->toBe('RuntimeException: The mail server refused.')
        ->and(json_encode([$pending, $failed]))->not->toContain('customer-secret');
});

it('reads a Redis queue through the lists and sets Laravel keeps', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('scan')->once()->with('0', ['MATCH' => 'shop:queues:*', 'COUNT' => 200])->andReturn(['0', ['shop:queues:default', 'shop:queues:mail', 'shop:queues:mail:delayed', 'shop:queues:mail:reserved', 'shop:queues:mail:notify']]);
    // One pipeline holds the list length and the delayed count of each queue, in order.
    $client->shouldReceive('pipeline')->once()->andReturn([2, 1, 0, 0]);
    $client->shouldReceive('lrange')->with('shop:queues:default', -5, -1)->andReturn([queue_payload('App\\Jobs\\Old', 1_700_000_000.0), queue_payload('App\\Jobs\\New', 1_700_000_100.0)]);
    $reader = new StoreInstanceQueueReader(null, fn (): Client => $client);
    $target = new InstanceQueueTarget('redis', new DatabaseConnection(['driver' => DatabaseDriver::Redis]), null, 'shop:', ['default']);

    $report = $reader->read($target, 'pending', 5);

    expect($report['pending'])->toBe(3)
        ->and($report['failed'])->toBeNull()
        ->and($report['queues'])->toBe([['name' => 'default', 'length' => 3], ['name' => 'mail', 'length' => 0]])
        ->and(array_column($report['jobs'], 'name'))->toBe(['App\\Jobs\\New', 'App\\Jobs\\Old']);
});

it('keeps a pending read when failed_jobs is unreadable and refuses a failed read', function (): void {
    $sqlite = queue_sqlite();
    $sqlite->getSchemaBuilder()->drop('failed_jobs');
    $sqlite->table('jobs')->insert(['queue' => 'default', 'payload' => queue_payload('App\\Jobs\\Digest'), 'reserved_at' => null, 'created_at' => 1_700_000_300]);
    $reader = new StoreInstanceQueueReader(fn (): Connection => $sqlite);
    $store = new DatabaseConnection(['driver' => DatabaseDriver::Mysql]);
    $target = new InstanceQueueTarget('database', $store, $store, '', []);

    $pending = $reader->read($target, 'pending', 5);

    expect($pending['pending'])->toBe(1)->and($pending['failed'])->toBeNull();
    expect(fn () => $reader->read($target, 'failed', 5))->toThrow(ResourceOperationException::class);
});

it('refuses with a bounded error when the store does not answer', function (): void {
    $reader = new StoreInstanceQueueReader(fn () => throw new RuntimeException('SQLSTATE[HY000] password=secret'));
    $store = new DatabaseConnection(['driver' => DatabaseDriver::Mysql]);

    try {
        $reader->read(new InstanceQueueTarget('database', $store, $store, '', []), 'pending', 5);
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('instance.queue_failed')->and($error->getMessage())->not->toContain('secret');

        return;
    }

    throw new LogicException('The read did not fail.');
});

it('reports a queue as unavailable without a store and bounds what the store returns', function (): void {
    $reader = new class implements InstanceQueueReader
    {
        public function read(InstanceQueueTarget $target, string $state, int $limit): array
        {
            return [
                'pending' => 2, 'failed' => null, 'queues' => [['name' => str_repeat('q', 300), 'length' => -4]],
                'jobs' => [['id' => "a\0b", 'name' => str_repeat('N', 500), 'queue' => 'default', 'pushed_at' => 1_700_000_000, 'failed_at' => 'not a time', 'exception' => "line\x07"]],
            ];
        }
    };
    app()->instance(InstanceQueueReader::class, $reader);
    $instance = queue_instance(['QUEUE_CONNECTION' => 'redis']);

    expect(app(ShowInstanceQueueAction::class)->execute($instance, 'pending', 50))->toBe(['available' => false, 'state' => 'pending']);

    queue_connect($instance, DatabaseDriver::Redis, 'REDIS');
    $report = app(ShowInstanceQueueAction::class)->execute($instance, 'pending', 50);

    expect($report['available'])->toBeTrue()
        ->and($report['totals'])->toBe(['pending' => 2, 'failed' => null])
        ->and(strlen($report['queues'][0]['name']))->toBe(100)
        ->and($report['queues'][0]['length'])->toBe(0)
        ->and($report['jobs'][0])->toBe([
            'id' => 'a b', 'name' => str_repeat('N', 200), 'queue' => 'default',
            'pushed_at' => '2023-11-14T22:13:20Z', 'failed_at' => null, 'exception' => 'line ',
        ]);
});
