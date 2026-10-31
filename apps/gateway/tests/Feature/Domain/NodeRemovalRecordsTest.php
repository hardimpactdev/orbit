<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\TaskVms\TaskVmNodeRemovalPlan;
use App\Domain\Nodes\NodeRemovalRecords;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;
use App\Models\Route;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Fleet\FleetFixtures;

it('projects bounded native completion into exact Node and role records without deleting another Node or Route', function (): void {
    $source = FleetFixtures::node('removal-lower-source', [RoleName::AppDev]);
    $other = FleetFixtures::node('removal-lower-other', [RoleName::Database]);
    $project = Project::query()->create(['slug' => 'remove-lower', 'name' => 'Removal', 'repository_url' => 'https://github.com/example/remove-lower.git']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $source->id, 'domain' => 'remove-lower.orbit', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $keep = Route::query()->create(['project_id' => $project->id, 'node_id' => $other->id, 'domain' => 'keep-lower.orbit', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $snapshot = app(NodeRemovalPlan::class)->build($source, true);
    $result = ['removed' => true, 'node_id' => $source->id, 'schema_version' => 1];
    app(NodeRemovalRecords::class)->remove($snapshot, $result);
    app(NodeRemovalRecords::class)->remove($snapshot, $result);

    $this->assertModelMissing($source);
    $this->assertModelMissing($route);
    $this->assertModelExists($other);
    $this->assertModelExists($keep);
    $this->assertDatabaseMissing('node_roles', ['node_id' => $source->id]);
    $this->assertDatabaseHas('node_roles', ['node_id' => $other->id, 'role' => 'database']);
});

it('does not restore or delete a row owned by another TaskVM', function (): void {
    $source = FleetFixtures::node('removal-lower-changed-vm');
    $vm = TaskVm::query()->create(['node_id' => $source->id, 'name' => $source->name, 'wireguard_ip' => $source->wireguard_ip, 'provider' => 'incus', 'state' => 'destroying']);
    $snapshot = app(NodeRemovalPlan::class)->build($source, true, true);
    $vm->update(['node_id' => null]);
    expect(fn () => app(NodeRemovalRecords::class)->restore($snapshot))->toThrow(ResourceOperationException::class)
        ->and(fn () => app(NodeRemovalRecords::class)->remove($snapshot, ['schema_version' => 1, 'node_id' => $source->id, 'removed' => true]))->toThrow(ResourceOperationException::class);
    $this->assertModelExists($source);
});

it('withdraws and restores only the exact admitted Node membership without deleting role intent', function (): void {
    $source = FleetFixtures::node('removal-lower-transition', [RoleName::AppDev]);
    $other = FleetFixtures::node('removal-lower-transition-keep', [RoleName::Database]);
    $snapshot = app(NodeRemovalPlan::class)->build($source, true);
    app(NodeRemovalRecords::class)->begin($snapshot);
    expect($source->fresh()->status)->toBe(LifecycleStatus::Removing)
        ->and($other->fresh()->status)->toBe(LifecycleStatus::Active);
    $this->assertDatabaseHas('node_roles', ['node_id' => $source->id, 'role' => 'app-dev']);
    app(NodeRemovalRecords::class)->restore($snapshot);
    expect($source->fresh()->status)->toBe(LifecycleStatus::Active);
});

it('does not begin withdrawal for a changed TaskVM owner', function (): void {
    $source = FleetFixtures::node('removal-lower-transition-owner');
    $vm = TaskVm::query()->create(['node_id' => $source->id, 'name' => $source->name, 'wireguard_ip' => $source->wireguard_ip, 'provider' => 'incus', 'state' => 'destroying']);
    $snapshot = app(NodeRemovalPlan::class)->build($source, true, true);
    $vm->update(['node_id' => null]);
    expect(fn () => app(NodeRemovalRecords::class)->begin($snapshot))->toThrow(ResourceOperationException::class);
    expect($source->fresh()->status)->toBe(LifecycleStatus::Active);
});

it('does not broadcast a Node deletion when the owning parent transaction rolls back', function (): void {
    $node = FleetFixtures::node('removal-lower-rollback', [RoleName::AppDev]);
    $snapshot = app(NodeRemovalPlan::class)->build($node, true);
    Event::fake([RecordBroadcast::class]);

    expect(fn () => DB::transaction(function () use ($snapshot, $node): void {
        app(NodeRemovalRecords::class)->remove($snapshot, ['schema_version' => 1, 'node_id' => $node->id, 'removed' => true]);
        throw new RuntimeException('Parent rollback');
    }))->toThrow(RuntimeException::class, 'Parent rollback');

    $this->assertModelExists($node);
    Event::assertNotDispatched(RecordBroadcast::class, static fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeDeleted);
});

it('retains the actual Metrics contribution when capturing an already deleted TaskVM guest', function (): void {
    $guest = FleetFixtures::node('metrics-taskvm-guest');
    $metrics = FleetFixtures::node('metrics-taskvm-owner', [RoleName::Metrics]);
    $gateway = FleetFixtures::node('metrics-taskvm-gateway', [RoleName::Gateway]);
    $vm = TaskVm::query()->create(['node_id' => $guest->id, 'name' => $guest->name, 'wireguard_ip' => $guest->wireguard_ip, 'provider' => 'incus', 'state' => 'destroying']);
    $snapshot = app(NodeRemovalPlan::class)->build($guest, true, true);
    expect($snapshot['metrics'])->toBe(['node_id' => $metrics->id, 'gateway_id' => $gateway->id]);
    $this->assertModelExists($guest);
});

it('captures the exact deleted TaskVM and all serving participant owners without calling the deleted guest', function (): void {
    $source = FleetFixtures::node('removal-lower-guest', [RoleName::AppDev]);
    $hub = FleetFixtures::node('removal-lower-vpn', [RoleName::Vpn]);
    $other = FleetFixtures::node('removal-lower-serving');
    $vm = TaskVm::query()->create(['node_id' => $source->id, 'name' => $source->name, 'wireguard_ip' => $source->wireguard_ip, 'provider' => 'incus', 'state' => 'destroying']);
    $snapshot = app(TaskVmNodeRemovalPlan::class)->deletedTaskVm($vm, $source);

    expect($snapshot)->toMatchArray(['schema_version' => 1, 'node_id' => $source->id, 'task_vm_id' => $vm->id, 'machine_deleted' => true, 'offline' => true, 'metrics' => null]);
    expect($snapshot['participants'])->toBe([
        ['node_id' => $hub->id, 'peer' => false, 'routes' => false, 'residue' => false, 'dns' => true],
    ]);
    $this->assertModelExists($source);
});

it('refuses a foreign VM Node binding before producing a reusable cleanup input', function (): void {
    $source = FleetFixtures::node('removal-lower-owner');
    $other = FleetFixtures::node('removal-lower-foreign');
    $vm = TaskVm::query()->create(['node_id' => $source->id, 'name' => $source->name, 'wireguard_ip' => $source->wireguard_ip, 'provider' => 'incus', 'state' => 'destroying']);
    expect(fn () => app(TaskVmNodeRemovalPlan::class)->deletedTaskVm($vm, $other))->toThrow(ResourceOperationException::class);
    $this->assertModelExists($source);
    $this->assertModelExists($other);
});
