<?php

declare(strict_types=1);

use App\Actions\Doctor\DatabaseServerDoctorProbe;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\DoctorResourceKind;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\FakeValkeyServerAdmin;

it('queues only running Valkey servers on the Node and inspects one selected server', function (): void {
    $node = Node::query()->create(['name' => 'servers', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.80', 'user' => 'orbit']);
    $other = Node::query()->create(['name' => 'other', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.81', 'user' => 'orbit']);
    $servers = [];
    foreach ([$node, $node, $node, $node, $other] as $index => $owner) {
        $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $owner->id, 'name' => 'database-'.$index, 'runtime' => ProcessRuntime::Docker, 'runtime_config' => ['image' => 'valkey:8'], 'desired_state' => $index === 2 ? DesiredProcessState::Stopped : DesiredProcessState::Running, 'status' => LifecycleStatus::Active]);
        $servers[] = DatabaseServer::query()->create(['slug' => 'database-'.$index, 'driver' => $index === 3 ? DatabaseDriver::Mysql : DatabaseDriver::Redis, 'node_id' => $owner->id, 'process_id' => $process->id, 'tag' => '8', 'port' => 6379 + $index, 'root_password' => 'test-secret', 'status' => LifecycleStatus::Active]);
    }
    $admin = new FakeValkeyServerAdmin;
    $probe = new DatabaseServerDoctorProbe($admin);

    $resources = $probe->resources($node);

    expect(array_map(static fn ($resource): array => $resource->ids, $resources))->toBe([[$servers[0]->id], [$servers[1]->id]])
        ->and($resources[0]->kind)->toBe(DoctorResourceKind::Rows);
    $report = $probe->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true), resources: $resources[1]));
    expect($report->checked)->toBe(1)
        ->and(array_map(static fn ($issue): int|string|null => $issue->resourceId, $report->issues))->toBe([$servers[1]->id])
        ->and($admin->calls)->toBe(['anonymous ping', 'config get aclfile', 'acl list']);
});
