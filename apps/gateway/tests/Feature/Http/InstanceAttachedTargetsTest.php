<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Http\Mcp\ApiDispatcher;
use App\Http\Mcp\ApiOperationTool;
use App\Http\Mcp\ToolDefinition;
use App\Http\Mcp\ToolManifest;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Mcp\Request as McpRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;

uses(LazilyRefreshDatabase::class);

function attachedTargetNode(string $name = 'beast', string $ip = '10.44.0.7'): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'wireguard_ip' => $ip, 'public_ssh_host' => $ip,
        'status' => 'active', 'platform' => 'linux', 'user' => 'orbit',
    ]));
}

function attachedTargetInstance(Node $node, string $name = 'default'): Instance
{
    $node->accessibleNodes()->syncWithoutDetaching([$node->id]);
    $project = Project::query()->firstOrCreate(['slug' => 'hauzer'], [
        'name' => 'Hauzer', 'type' => 'node-package', 'root' => '.',
        'default_branch' => 'main', 'repository_url' => 'https://github.com/hardimpactdev/hauzer.git',
    ]);

    return Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => $name,
        'checkout_path' => '/home/orbit/apps/hauzer/'.$name, 'branch' => 'main',
        'status' => InstanceState::Active, 'first_setup_pending' => false,
    ]);
}

/** @param array<string, mixed> $attributes */
function attachedTargetConnection(Instance $instance, string $prefix, array $attributes, bool $shared = false): DatabaseConnection
{
    $connection = DatabaseConnection::query()->create([
        'slug' => strtolower($prefix), 'driver' => 'mysql', 'host' => '10.44.0.7',
        'port' => 3306, 'database' => 'hauzer', 'username' => 'hauzer',
        'password' => 'attachment-password-sentinel', ...$attributes,
    ]);
    DatabaseConnectionTarget::query()->create([
        'instance_id' => $instance->id, 'database_connection_id' => $connection->id,
        'prefix' => $prefix, 'network' => 'wireguard', 'shared' => $shared,
    ]);

    return $connection;
}

it('shows an empty attached targets array without contacting a Node', function (): void {
    $node = attachedTargetNode();
    $instance = attachedTargetInstance($node);
    Http::preventStrayRequests();
    Process::fake();

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->getJson('/api/v1/instances/'.$instance->id)->assertOk()
        ->assertJsonPath('data.attached_targets', []);

    Http::assertNothingSent();
    Process::assertNothingRan();
});

it('shows a SQLite attachment without inventing an endpoint or Node', function (): void {
    $node = attachedTargetNode();
    $instance = attachedTargetInstance($node);
    $connection = attachedTargetConnection($instance, 'DB', [
        'slug' => 'hauzer-sqlite', 'driver' => 'sqlite', 'node_id' => null,
        'host' => null, 'port' => null, 'database' => null, 'username' => null,
        'password' => null, 'path' => '/home/orbit/apps/hauzer/default/database.sqlite',
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->getJson('/api/v1/instances/'.$instance->id)->assertOk()
        ->assertJsonPath('data.attached_targets', [
            ['kind' => 'database', 'id' => $connection->id, 'name' => 'hauzer-sqlite', 'driver' => 'sqlite',
                'node' => null, 'host' => null, 'port' => null, 'database' => null, 'username' => null,
                'prefix' => 'DB', 'shared' => false, 'server' => null],
        ]);
});

it('shows only attached SQL and Redis connections with server and remote Node identities, the shared mark and no secrets', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-10T12:00:00Z'));
    $node = attachedTargetNode();
    $services = attachedTargetNode('Services', '10.44.0.3');
    $instance = attachedTargetInstance($node);
    $other = attachedTargetInstance($node, 'other');
    $server = DatabaseServer::query()->create([
        'slug' => 'catalog-postgres', 'driver' => 'pgsql', 'node_id' => $services->id,
        'tag' => '18', 'port' => 5434, 'root_password' => 'attachment-root-password-sentinel', 'status' => 'active',
    ]);
    $redis = attachedTargetConnection($instance, 'REDIS', [
        'driver' => 'redis', 'slug' => 'hauzer-cache', 'node_id' => $node->id,
        'port' => 6379, 'database' => '2', 'username' => null,
    ]);
    $sql = attachedTargetConnection($instance, 'DB', [
        'slug' => 'hauzer-main', 'node_id' => $node->id,
    ]);
    $catalog = attachedTargetConnection($instance, 'FOOD_CATALOG', [
        'slug' => 'food-catalog', 'driver' => 'pgsql', 'database_server_id' => $server->id,
        'host' => '10.44.0.3', 'port' => 5434, 'database' => 'food_catalog', 'username' => 'catalog_reader',
    ], shared: true);
    attachedTargetConnection($other, 'OTHER', ['slug' => 'unattached-connection-sentinel']);
    Http::preventStrayRequests();
    Process::fake();

    $response = $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->getJson('/api/v1/instances/'.$instance->id)->assertOk()
        ->assertJsonPath('data.attached_targets', [
            ['kind' => 'database', 'id' => $sql->id, 'name' => 'hauzer-main', 'driver' => 'mysql',
                'node' => ['id' => $node->id, 'name' => 'beast'], 'host' => '10.44.0.7', 'port' => 3306,
                'database' => 'hauzer', 'username' => 'hauzer', 'prefix' => 'DB', 'shared' => false, 'server' => null],
            ['kind' => 'redis', 'id' => $redis->id, 'name' => 'hauzer-cache', 'driver' => 'redis',
                'node' => ['id' => $node->id, 'name' => 'beast'], 'host' => '10.44.0.7', 'port' => 6379,
                'database' => '2', 'username' => null, 'prefix' => 'REDIS', 'shared' => false, 'server' => null],
            ['kind' => 'database', 'id' => $catalog->id, 'name' => 'food-catalog', 'driver' => 'pgsql',
                'node' => ['id' => $services->id, 'name' => 'Services'], 'host' => '10.44.0.3', 'port' => 5434,
                'database' => 'food_catalog', 'username' => 'catalog_reader', 'prefix' => 'FOOD_CATALOG',
                'shared' => true, 'server' => ['id' => $server->id, 'name' => 'catalog-postgres']],
        ]);

    expect($response->getContent())->not->toContain(
        'attachment-password-sentinel', 'attachment-root-password-sentinel', 'unattached-connection-sentinel',
        'password', 'vault', 'secret',
    );
    $this->getJson('/api/v1/instances')->assertOk()
        ->assertJsonPath('data.0.attached_targets', $response->json('data.attached_targets'));
    $body = $response->json();
    $body['meta']['request_id'] = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $fixture = ['schema' => 1, 'request' => ShowInstanceRequest::class,
        'route' => 'GET /api/v1/instances/{instance}', 'status' => 200, 'body' => $body];
    $path = base_path('../../packages/php-sdk/fixtures/instances/instance-show/attached-targets.json');
    if (getenv('ORBIT_FIXTURES') === 'record') {
        file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
    expect($fixture)->toBe(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
    Http::assertNothingSent();
    Process::assertNothingRan();
});

it('keeps show query count flat as attachments on distinct Nodes and servers grow', function (): void {
    $node = attachedTargetNode();
    $instance = attachedTargetInstance($node);
    $add = function (int $index) use ($instance): void {
        $services = attachedTargetNode('services-'.$index, '10.44.1.'.$index);
        $server = DatabaseServer::query()->create([
            'slug' => 'sql-'.$index, 'driver' => 'pgsql', 'node_id' => $services->id,
            'tag' => '18', 'port' => 5434, 'root_password' => 'attachment-root-password-sentinel', 'status' => 'active',
        ]);
        attachedTargetConnection($instance, 'SQL_'.$index, [
            'slug' => 'catalog-'.$index, 'driver' => 'pgsql', 'database_server_id' => $server->id,
            'node_id' => $services->id, 'host' => $services->wireguard_ip, 'port' => 5434,
        ]);
    };
    $add(1);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->getJson('/api/v1/instances/'.$instance->id)->assertOk();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->getJson('/api/v1/instances/'.$instance->id)->assertOk()->assertJsonCount(1, 'data.attached_targets');
    $one = count(DB::getQueryLog());
    for ($index = 2; $index <= 8; $index++) {
        $add($index);
    }
    DB::flushQueryLog();
    $this->getJson('/api/v1/instances/'.$instance->id)->assertOk()->assertJsonCount(8, 'data.attached_targets');
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($one);
});

it('exposes the same attached targets through the generated MCP instance-show tool', function (): void {
    $node = attachedTargetNode();
    $instance = attachedTargetInstance($node);
    $connection = attachedTargetConnection($instance, 'REDIS', [
        'slug' => 'cache', 'driver' => 'redis', 'node_id' => $node->id, 'port' => 6380,
        'database' => null, 'username' => null,
    ]);
    $definition = collect(ToolManifest::default()->definitions())->firstWhere('name', 'instance-show');
    expect($definition)->toBeInstanceOf(ToolDefinition::class);
    $caller = Request::create('/mcp', server: ['REMOTE_ADDR' => $node->wireguard_ip]);

    $tool = new ApiOperationTool($definition, app(ApiDispatcher::class));
    $response = $tool->handle(new McpRequest(['instance' => $instance->id]), $caller);
    $body = (string) $response->content();
    $result = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

    expect($result['data']['attached_targets'])->toBe([
        ['kind' => 'redis', 'id' => $connection->id, 'name' => 'cache', 'driver' => 'redis',
            'node' => ['id' => $node->id, 'name' => 'beast'], 'host' => '10.44.0.7', 'port' => 6380,
            'database' => null, 'username' => null, 'prefix' => 'REDIS', 'shared' => false, 'server' => null],
    ])
        ->and($body)->not->toContain('attachment-password-sentinel', 'password', 'vault', 'secret');
});
