<?php

declare(strict_types=1);

use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\DatabaseServers\RemoteDatabaseServerAdmin;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\FakeAgentCommandRunner;

const REMOTE_DATABASE_SERVER_ROOT = 'root-secret-aa12';

function remote_database_server(): DatabaseServer
{
    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.80',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.80',
    ]);
    $process = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => 'beast-mysql',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => ['image' => 'mysql:8.4', 'command' => ['mysqld'], 'environment' => [], 'ports' => [], 'volumes' => []],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);

    return DatabaseServer::query()->create([
        'slug' => 'beast-mysql',
        'node_id' => $node->id,
        'process_id' => $process->id,
        'tag' => '8.4',
        'port' => 3306,
        'root_password' => REMOTE_DATABASE_SERVER_ROOT,
        'status' => LifecycleStatus::Active,
    ]);
}

function remote_database_server_admin(FakeAgentCommandRunner $agents): RemoteDatabaseServerAdmin
{
    return new RemoteDatabaseServerAdmin($agents, app(DockerProcessRenderer::class));
}

it('runs SQL through the agent with the root password in the protected envelope', function (): void {
    $server = remote_database_server();
    $agents = new FakeAgentCommandRunner()->answer('database.server-admin', ['output' => "app\tutf8\nlogs\n"]);

    expect(remote_database_server_admin($agents)->execute($server, "SHOW DATABASES;\n"))->toBe(['app', 'logs'])
        ->and($agents->calls[0]['input'])->toBe([
            'operation' => 'execute',
            'password_variable' => 'MYSQL_PWD',
            'client' => ['mysql', '--user=root', '--batch', '--skip-column-names'],
            'sql' => "SHOW DATABASES;\n",
            'container' => app(DockerProcessRenderer::class)->containerName($server->process),
            'password' => REMOTE_DATABASE_SERVER_ROOT,
        ]);
});

it('runs PostgreSQL over authenticated TCP with psql error stopping', function (): void {
    $server = remote_database_server();
    $server->update(['driver' => 'pgsql', 'tag' => '18']);
    $agents = new FakeAgentCommandRunner()->answer('database.server-admin', ['output' => "app\n"]);

    expect(remote_database_server_admin($agents)->execute($server, "SELECT datname FROM pg_database;\n"))->toBe(['app'])
        ->and($agents->calls[0]['input'])->toMatchArray([
            'password_variable' => 'PGPASSWORD',
            'client' => ['psql', '--no-psqlrc', '--host=127.0.0.1', '--username=postgres', '--dbname=postgres', '--no-align', '--tuples-only', '--quiet', '--set=ON_ERROR_STOP=1'],
        ]);
});

it('waits for the root login over TCP and reports a timeout', function (): void {
    $server = remote_database_server();
    $ready = new FakeAgentCommandRunner()->answer('database.server-admin', []);
    $late = new FakeAgentCommandRunner()->refuse('database.server-admin', 'database.server_not_ready');

    expect(remote_database_server_admin($ready)->waitUntilReady($server, 30))->toBeTrue()
        ->and($ready->calls[0]['input'])->toMatchArray([
            'operation' => 'ready',
            'client' => ['mysql', '--protocol=TCP', '--host=127.0.0.1', '--user=root', '--batch', '--skip-column-names'],
            'timeout_seconds' => 30,
        ])
        ->and(remote_database_server_admin($late)->waitUntilReady($server, 30))->toBeFalse();
});

it('finishes retained MySQL copies through the protected agent command', function (): void {
    $server = remote_database_server();
    $agents = new FakeAgentCommandRunner()->answer('database.server-admin', []);

    remote_database_server_admin($agents)->copyDatabase($server, 'source_db', 'target_db');

    expect($agents->calls)->toHaveCount(1)
        ->and($agents->calls[0]['input'])->toMatchArray(['operation' => 'copy', 'source' => 'source_db', 'target' => 'target_db', 'password' => REMOTE_DATABASE_SERVER_ROOT]);
});

it('refuses an unsafe or identical retained MySQL copy before invoking the agent', function (string $source, string $target): void {
    $server = remote_database_server();
    $agents = new FakeAgentCommandRunner;

    expect(fn () => remote_database_server_admin($agents)->copyDatabase($server, $source, $target))->toThrow(ResourceOperationException::class);
    expect($agents->calls)->toBe([]);
})->with([['same_db', 'same_db'], ['source; DROP DATABASE other', 'target_db']]);
