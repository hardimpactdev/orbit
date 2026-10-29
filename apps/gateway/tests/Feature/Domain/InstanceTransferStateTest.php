<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceTransferState;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Instances\TransferLifecycleExecution;
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
    $this->run = ['worker' => 'proof', 'key' => 'transfer-capture', 'transport' => 'instance_lifecycle',
        'name' => 'instance.transfer-source', 'input' => []];
    $this->capture = ['head' => str_repeat('a', 40), 'branch' => 'feature', 'detached' => false,
        'archive' => '/tmp/orbit-transfer-proof-0123456789abcdef.tar', 'common' => null, 'refs' => ['main', 'feature']];
});

it('projects only safe capture evidence and preserves the generic lease and run state', function (): void {
    $receipt = app(TransferLifecycleExecution::class)->received($this->operation, $this->run,
        [...$this->capture, 'stdout' => 'private archive contents']);
    expect($receipt)->toBe(['captured' => true, 'commit' => str_repeat('a', 40), 'branch' => 'feature'])
        ->and($this->transfer->refresh()->current_step)->toBe(InstanceTransferStep::SourceCaptured)
        ->and($this->operation->refresh()->state['executor_lease_node_id'])->toBe($this->source->id)
        ->and($this->operation->state['executor_runs']['other'])->toBe(['result' => ['ready' => true]])
        ->and($this->operation->state['lifecycle_transfer_capture'])->toBe($this->capture)
        ->and(json_encode($this->operation->toArray()))->not->toContain('private archive contents');
});

it('rejects invalid archive, source identity and branch evidence before projecting capture', function (string $field, mixed $value): void {
    expect(fn () => app(TransferLifecycleExecution::class)->received($this->operation, $this->run, [...$this->capture, $field => $value]))
        ->toThrow(ResourceOperationException::class);
    expect($this->transfer->refresh()->current_step)->toBe(InstanceTransferStep::Reserved)
        ->and($this->operation->refresh()->state)->not->toHaveKey('lifecycle_transfer_capture');
})->with([
    ['archive', '/tmp/other-transfer.tar'], ['archive', '/tmp/orbit-transfer-proof-../../foreign.tar'],
    ['head', 'HEAD'], ['branch', '--upload-pack=other'], ['detached', true], ['refs', ['feature', 42]],
]);

it('binds source capture to the parent Node and marks the archive-producing command nonresumable', function (): void {
    $descriptors = app(TransferLifecycleExecution::class)->descriptors($this->operation);
    expect($descriptors[0]['input'])->toBe(['operation' => 'capture', 'source' => $this->transfer->source_path,
        'layout' => 'checkout', 'sqlite_source' => null])->and($descriptors[0]['resumable'])->toBeFalse();
    $this->operation->node_id = $this->destination->id;
    expect(fn () => app(TransferLifecycleExecution::class)->descriptors($this->operation))->toThrow(ResourceOperationException::class);
});

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
