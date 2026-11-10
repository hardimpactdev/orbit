<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
});

function valkeyImageUpdateServer(string $driver = 'redis', string $desired = 'running'): DatabaseServer
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '10.44.0.1', 'user' => 'orbit',
    ]));
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $process = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'database',
        'runtime' => 'docker', 'working_directory' => '/data',
        'runtime_config' => [
            'image' => $driver === 'redis' ? 'valkey/valkey:8.1' : 'mysql:8.4',
            'command' => $driver === 'redis'
                ? ['valkey-server', '--appendonly', 'yes', '--dir', '/data', '--aclfile', '/data/users.acl']
                : ['mysqld'],
            'environment' => [],
            'ports' => [$driver === 'redis' ? '10.44.0.1:6379:6379' : '10.44.0.1:3306:3306'],
            'volumes' => [['source' => 'orbit-database-data', 'target' => '/data', 'read_only' => false]],
        ],
        'restart_policy' => 'unless-stopped', 'keep_alive' => true, 'restart_on_deploy' => false,
        'desired_state' => $desired, 'status' => 'active',
    ]);

    return DatabaseServer::query()->create([
        'slug' => 'database', 'driver' => $driver, 'node_id' => $node->id, 'process_id' => $process->id,
        'tag' => $driver === 'redis' ? '8.1' : '8.4', 'port' => $driver === 'redis' ? 6379 : 3306,
        'status' => 'active', 'root_password' => 'image-update-admin-password',
    ])->refresh();
}

it('accepts an image-only Valkey tag update and keeps the remaining specification', function (string $desired): void {
    $server = valkeyImageUpdateServer(desired: $desired);
    $process = $server->process;
    $before = $process->getAttributes();
    $serverBefore = $server->getAttributes();
    $config = $process->runtime_config;

    $response = $this->patchJson('/api/v1/processes/'.$process->id, ['image' => 'valkey/valkey:8.1.3-alpine'])
        ->assertAccepted()->assertJsonPath('data.kind', 'process.update');

    $config['image'] = 'valkey/valkey:8.1.3-alpine';
    expect($process->refresh()->runtime_config)->toBe($config);
    expect(Arr::except($process->getAttributes(), ['runtime_config', 'status', 'updated_at']))
        ->toBe(Arr::except($before, ['runtime_config', 'status', 'updated_at']));
    expect($server->refresh()->getAttributes())->toBe($serverBefore);
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->target_id)->toBe($process->id)
        ->and($operation->input['desired'])->toBe($desired);
})->with(['running', 'stopped']);

it('returns 409 without mutation for every other Valkey Process update', function (array $body): void {
    $server = valkeyImageUpdateServer();
    $process = $server->process;
    $before = $process->getAttributes();
    $serverBefore = $server->getAttributes();

    $this->patchJson('/api/v1/processes/'.$process->id, $body)
        ->assertConflict()->assertJsonPath('error.code', 'process.required_by_database_server');

    expect($process->refresh()->getAttributes())->toBe($before);
    expect($server->refresh()->getAttributes())->toBe($serverBefore);
    expect(Operation::query()->count())->toBe(0);
})->with([
    'another repository' => [['image' => 'redis:8.2']],
    'repository prefix' => [['image' => 'valkey/valkey-other:8.2']],
    'registry prefix' => [['image' => 'docker.io/valkey/valkey:8.2']],
    'untagged' => [['image' => 'valkey/valkey']],
    'empty tag' => [['image' => 'valkey/valkey:']],
    'digest' => [['image' => 'valkey/valkey:8.2@sha256:'.str_repeat('a', 64)]],
    'invalid tag' => [['image' => 'valkey/valkey:-8.2']],
    'command drops ACL file' => [['image' => 'valkey/valkey:8.2', 'command' => ['valkey-server']]],
    'environment override' => [['image' => 'valkey/valkey:8.2', 'environment' => ['VALKEY_EXTRA_FLAGS' => '--aclfile /tmp/open.acl']]],
    'volume removed' => [['image' => 'valkey/valkey:8.2', 'volumes' => []]],
    'unchanged extra field' => [['image' => 'valkey/valkey:8.2', 'restart_policy' => 'unless-stopped']],
    'unknown extra field' => [['image' => 'valkey/valkey:8.2', 'extra' => true]],
    'no image' => [['command' => ['valkey-server']]],
    'no fields' => [[]],
]);

it('keeps ordinary partial updates available for a MySQL server Process', function (): void {
    $server = valkeyImageUpdateServer('mysql');
    $process = $server->process;
    $config = $process->runtime_config;

    $this->patchJson('/api/v1/processes/'.$process->id, [
        'image' => 'mysql:8.4.6', 'command' => ['mysqld', '--max-connections=200'],
    ])->assertAccepted()->assertJsonPath('data.kind', 'process.update');

    $config['image'] = 'mysql:8.4.6';
    $config['command'] = ['mysqld', '--max-connections=200'];
    expect($process->refresh()->runtime_config)->toBe($config);
});
