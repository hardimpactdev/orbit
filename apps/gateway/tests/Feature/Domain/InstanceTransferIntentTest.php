<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceTransferState;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'Transfer proof', 'slug' => 'transfer-proof',
        'repository_url' => 'https://github.com/example/transfer-proof.git', 'default_branch' => 'main', 'root' => 'public']);
    $node = static fn (string $name, string $ip): Node => Node::query()->create(['name' => $name, 'status' => 'active',
        'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => $ip, 'public_ssh_host' => '192.0.2.10']);
    $this->source = $node('source', '10.44.0.9');
    $this->destination = $node('destination', '10.44.0.10');
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->source->id,
        'name' => 'proof', 'checkout_path' => '/srv/apps/transfer-proof/proof', 'source_layout' => 'checkout', 'status' => 'active']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $this->source->id,
        'domain' => 'proof.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $this->transfer = InstanceTransfer::query()->create(['instance_id' => $this->instance->id,
        'source_node_id' => $this->source->id, 'destination_node_id' => $this->destination->id,
        'source_path' => $this->instance->checkout_path, 'source_layout' => 'checkout', 'source_route_id' => $route->id,
        'destination_name' => 'proof', 'destination_path' => '/srv/apps/transfer-proof/proof', 'destination_domain' => 'proof.test',
        'status' => 'reserved', 'current_step' => 'reserved']);
    $this->operation = Operation::query()->create(['kind' => 'instance.transfer', 'target_type' => 'instance',
        'target_id' => $this->instance->id, 'node_id' => $this->source->id, 'status' => 'running', 'worker' => 'proof', 'sequence' => 1,
        'input' => ['instance_id' => $this->instance->id, 'transfer_id' => $this->transfer->id,
            'initial_completed' => false, 'initial_cutover' => false],
        'state' => ['executor_lease_node_id' => $this->source->id, 'executor_runs' => ['other' => ['result' => ['ready' => true]]]]]);
});

it('binds a recorded transfer to its exact Instance and source Node', function (): void {
    expect(app(InstanceTransferState::class)->transfer($this->operation)->id)->toBe($this->transfer->id);
    $this->operation->node_id = $this->destination->id;
    expect(fn () => app(InstanceTransferState::class)->transfer($this->operation))
        ->toThrow(ResourceOperationException::class);
});

it('refuses another Instance or changed admitted destination before projecting transfer state', function (string $field): void {
    if ($field === 'target') {
        $this->operation->target_id++;
    } else {
        $this->operation->input = [...$this->operation->input, 'destination_path' => '/srv/apps/foreign/proof'];
    }
    expect(fn () => app(InstanceTransferState::class)->transfer($this->operation))
        ->toThrow(ResourceOperationException::class);
    expect($this->transfer->refresh()->current_step)->toBe(InstanceTransferStep::Reserved);
    $this->assertDatabaseHas('instances', ['id' => $this->instance->id, 'node_id' => $this->source->id]);
})->with(['target', 'destination']);

it('reuses a committed transfer finish without reading the already retired source Route', function (): void {
    $this->instance->update(['node_id' => $this->destination->id, 'checkout_path' => $this->transfer->destination_path]);
    $this->transfer->update(['status' => InstanceTransferStatus::Completed, 'current_step' => InstanceTransferStep::Completed,
        'cutover_at' => now(), 'completed_at' => now()]);
    $result = app(InstanceTransferState::class)->transition($this->operation, ['action' => 'transfer-finish']);
    expect($result)->toBe(['transfer_id' => $this->transfer->id, 'completed' => true]);
    $this->instance->update(['checkout_path' => '/srv/apps/other/proof']);
    expect(fn () => app(InstanceTransferState::class)->transition($this->operation, ['action' => 'transfer-finish']))
        ->toThrow(ResourceOperationException::class);
});
