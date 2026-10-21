<?php

declare(strict_types=1);

use App\Actions\Doctor\DatabaseServerDoctorProbe;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseServerFirewall;
use App\Domain\DatabaseServers\DatabaseServerFirewallReconciler;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\DoctorResourceKind;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\Support\FakeDatabaseServerFirewall;
use Tests\Support\FakeValkeyServerAdmin;

it('queues running managed servers on the Node and inspects one selected server', function (): void {
    $node = Node::query()->create(['name' => 'servers', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.80', 'user' => 'orbit']);
    $other = Node::query()->create(['name' => 'other', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.81', 'user' => 'orbit']);
    $servers = [];
    foreach ([$node, $node, $node, $node, $other] as $index => $owner) {
        $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $owner->id, 'name' => 'database-'.$index, 'runtime' => ProcessRuntime::Docker, 'runtime_config' => ['image' => 'valkey:8'], 'desired_state' => $index === 2 ? DesiredProcessState::Stopped : DesiredProcessState::Running, 'status' => LifecycleStatus::Active]);
        $servers[] = DatabaseServer::query()->create(['slug' => 'database-'.$index, 'driver' => $index === 3 ? DatabaseDriver::Mysql : DatabaseDriver::Redis, 'node_id' => $owner->id, 'process_id' => $process->id, 'tag' => '8', 'port' => 6379 + $index, 'root_password' => 'test-secret', 'status' => LifecycleStatus::Active, 'private_endpoints_managed' => true]);
    }
    $admin = new FakeValkeyServerAdmin;
    $probe = new DatabaseServerDoctorProbe($admin, new FakeDatabaseServerAdmin, app(DatabaseServerFirewallReconciler::class));

    $resources = $probe->resources($node);

    expect(array_map(static fn ($resource): array => $resource->ids, $resources))->toBe([[$servers[0]->id], [$servers[1]->id], [$servers[3]->id]])
        ->and($resources[0]->kind)->toBe(DoctorResourceKind::Rows);
    $report = $probe->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true), resources: $resources[1]));
    expect($report->checked)->toBe(1)
        ->and(array_map(static fn ($issue): int|string|null => $issue->resourceId, $report->issues))->toBe([$servers[1]->id, $servers[1]->id])
        ->and($admin->calls)->toBe(['anonymous ping', 'config get aclfile', 'acl list']);
});

it('checks SQL login, private publications and initialization secret removal for PostgreSQL', function (): void {
    $node = Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.17', 'wireguard_ip' => '10.44.0.3', 'lan_ip' => '10.0.0.6']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'managed-pg', 'runtime' => ProcessRuntime::Docker, 'runtime_config' => ['ports' => ['10.44.0.3:5434:5432'], 'environment' => ['POSTGRES_PASSWORD' => 'fixture-secret']], 'desired_state' => 'running', 'status' => 'active']);
    DatabaseServer::query()->create(['slug' => 'managed-pg', 'driver' => 'pgsql', 'node_id' => $node->id, 'process_id' => $process->id, 'tag' => '18', 'port' => 5434, 'root_password' => 'fixture-secret', 'status' => 'active', 'private_endpoints_managed' => true]);
    $sql = new FakeDatabaseServerAdmin;
    $sql->readiness = [false];
    app()->instance(DatabaseServerFirewall::class, new FakeDatabaseServerFirewall);
    app(DatabaseServerFirewall::class)->matching = false;
    $probe = new DatabaseServerDoctorProbe(new FakeValkeyServerAdmin, $sql, app(DatabaseServerFirewallReconciler::class));
    $report = $probe->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));
    expect(array_map(fn ($issue): string => $issue->code, $report->issues))->toBe(['database_server.publication_mismatch', 'database_server.filter_mismatch', 'database_server.login_failed', 'database_server.initialization_secret_present']);
});

it('keeps endpoint rollout checks off existing servers until explicitly enabled', function (string $driver, int $port): void {
    $node = Node::query()->create(['name' => 'existing-host', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.17', 'wireguard_ip' => '10.44.0.3', 'lan_ip' => '10.0.0.6']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'existing-server', 'runtime' => ProcessRuntime::Docker, 'runtime_config' => ['ports' => ["10.44.0.3:{$port}:{$port}"]], 'desired_state' => 'running', 'status' => 'active']);
    $server = DatabaseServer::query()->create(['slug' => 'existing-server', 'driver' => $driver, 'node_id' => $node->id, 'process_id' => $process->id, 'tag' => '8', 'port' => $port, 'root_password' => 'fixture-secret', 'status' => 'active']);
    $firewall = Mockery::mock(DatabaseServerFirewall::class);
    $firewall->shouldNotReceive('matches');
    $valkey = new FakeValkeyServerAdmin;
    $valkey->waitUntilReady($server, 1);
    $sql = new FakeDatabaseServerAdmin;
    $probe = new DatabaseServerDoctorProbe($valkey, $sql, new DatabaseServerFirewallReconciler($firewall));

    $report = $probe->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->issues)->toBe([])->and($report->checked)->toBe(1);
    if ($driver === 'mysql') {
        expect($sql->readyChecks)->toBe(['existing-server']);
    } else {
        expect($valkey->calls)->toContain('anonymous ping', 'config get aclfile', 'acl list');
    }
})->with([['mysql', 3306], ['redis', 6379]]);
