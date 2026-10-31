<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\NodeRemovalRecords;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmNodeRemovalPlan;
use App\Models\Project;
use App\Models\Route;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Fleet\FleetFixtures;

it('captures the exact deleted TaskVM and all serving participant owners without calling the deleted guest', function (): void {
    $source = FleetFixtures::node('removal-lower-guest', [RoleName::AppDev]);
    $hub = FleetFixtures::node('removal-lower-vpn', [RoleName::Vpn]);
    $other = FleetFixtures::node('removal-lower-serving');
    $vm = TaskVm::query()->create(['node_id' => $source->id, 'name' => $source->name, 'wireguard_ip' => $source->wireguard_ip, 'provider' => 'incus', 'state' => 'destroying']);
    $snapshot = app(TaskVmNodeRemovalPlan::class)->deletedTaskVm($vm, $source);

    expect($snapshot)->toMatchArray(['schema_version' => 1, 'node_id' => $source->id, 'task_vm_id' => $vm->id, 'machine_deleted' => true, 'offline' => true, 'metrics' => null]);
    expect($snapshot['participants'])->toBe([
        ['node_id' => $hub->id, 'peer' => true, 'residue' => true, 'dns' => true],
        ['node_id' => $other->id, 'peer' => true, 'residue' => true, 'dns' => false],
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

it('projects bounded native completion into exact Node and role records without deleting another Node or Route', function (): void {
    $source = FleetFixtures::node('removal-lower-source', [RoleName::AppDev]);
    $other = FleetFixtures::node('removal-lower-other', [RoleName::Database]);
    $project = Project::query()->create(['slug' => 'remove-lower', 'name' => 'Removal', 'repository_url' => 'https://github.com/example/remove-lower.git']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $source->id, 'domain' => 'remove-lower.orbit', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $keep = Route::query()->create(['project_id' => $project->id, 'node_id' => $other->id, 'domain' => 'keep-lower.orbit', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $snapshot = app(TaskVmNodeRemovalPlan::class)->build($source, true);
    app(NodeRemovalRecords::class)->remove($snapshot);
    app(NodeRemovalRecords::class)->remove($snapshot);

    $this->assertModelMissing($source);
    $this->assertModelMissing($route);
    $this->assertModelExists($other);
    $this->assertModelExists($keep);
    $this->assertDatabaseMissing('node_roles', ['node_id' => $source->id]);
    $this->assertDatabaseHas('node_roles', ['node_id' => $other->id, 'role' => 'database']);
});

it('retains Node records when the immutable owning Node identity changes', function (): void {
    $node = FleetFixtures::node('removal-lower-owner', [RoleName::CiRunner]);
    $snapshot = app(TaskVmNodeRemovalPlan::class)->build($node, true);
    $node->update(['name' => 'different-node']);
    expect(fn () => app(NodeRemovalRecords::class)->remove($snapshot))->toThrow(ResourceOperationException::class);
    $this->assertModelExists($node);
});

it('does not broadcast a Node deletion when the owning parent transaction rolls back', function (): void {
    $node = FleetFixtures::node('removal-lower-rollback', [RoleName::AppDev]);
    $snapshot = app(TaskVmNodeRemovalPlan::class)->build($node, true);
    Event::fake([RecordBroadcast::class]);

    expect(fn () => DB::transaction(function () use ($snapshot): void {
        app(NodeRemovalRecords::class)->remove($snapshot);
        throw new RuntimeException('Parent rollback');
    }))->toThrow(RuntimeException::class, 'Parent rollback');

    $this->assertModelExists($node);
    Event::assertNotDispatched(RecordBroadcast::class, static fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeDeleted);
});
