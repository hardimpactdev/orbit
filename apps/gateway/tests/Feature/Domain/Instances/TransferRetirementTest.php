<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\NativeInstanceTransferIntent;
use App\Domain\Instances\NativeInstanceTransferProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $control = Node::withoutEvents(fn () => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $this->source = containerDefaultNode();
    $this->destination = containerDefaultNode('destination', '10.44.0.8');
    $project = containerDefaultProject();
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->source->id,
        'name' => 'owner', 'checkout_path' => '/fixture/owner', 'status' => 'active', 'root' => '.', 'source_layout' => 'checkout']);
    $this->dependent = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->source->id,
        'name' => 'moved', 'checkout_path' => '/fixture/moved', 'status' => 'active', 'root' => '.', 'source_layout' => 'checkout']);
    $this->transfer = InstanceTransfer::query()->create(['instance_id' => $this->instance->id, 'source_node_id' => $this->source->id,
        'destination_node_id' => $this->destination->id, 'destination_name' => 'owner', 'destination_path' => '/destination/owner',
        'destination_domain' => 'owner.fixture.test', 'source_layout' => 'checkout', 'source_path' => '/fixture/owner',
        'source_route_id' => 1, 'status' => 'reserved', 'current_step' => 'reserved']);
});

it('captures the source Node host checkout inventory with destroy selection and schema', function (): void {
    $container = Instance::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->source->id,
        'name' => 'container', 'checkout_path' => '/fixture/container', 'status' => 'active', 'root' => '.', 'source_layout' => 'checkout']);
    app(InstanceContainer::class)->reserve($container, $this->source);
    $plan = app(NativeInstanceTransferIntent::class)->capture($this->transfer);
    expect($plan['checkout_instances'])->toBe([
        ['id' => $this->instance->id, 'path' => '/fixture/owner'], ['id' => $this->dependent->id, 'path' => '/fixture/moved'],
    ]);
    $this->instance->update(['node_id' => $this->destination->id, 'checkout_path' => '/destination/owner']);
    expect(app(NativeInstanceTransferIntent::class)->capture($this->transfer)['checkout_instances'])
        ->toBe([['id' => $this->dependent->id, 'path' => '/fixture/moved']]);
});

it('retains retirement domain refusal paths and Instance IDs after cutover and cleanup', function (string $code): void {
    $this->instance->update(['node_id' => $this->destination->id, 'checkout_path' => '/destination/owner']);
    $this->transfer->update(['cutover_at' => now(), 'current_step' => 'cutover']);
    $operation = Operation::query()->create(['kind' => 'instance.transfer', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->source->id, 'status' => 'running', 'input' => ['transfer_id' => $this->transfer->id, 'route_transfer' => []],
        'state' => ['swamp_run_id' => 'fixture-transfer'], 'started_at' => now()]);
    $receipt = ['outcome' => 'refused', 'data' => $code === 'instance.worktree_dependents'
        ? ['error_code' => $code, 'dependents' => [['path' => '/fixture/moved', 'instance_ids' => [$this->dependent->id]]]]
        : ['error_code' => $code, 'path' => '/fixture/moved', 'cause' => 'unreadable']];
    $terminal = ['run_id' => 'fixture-transfer', 'status' => 'failed', 'error_code' => 'operation.native_failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['forward' => ['ready' => true], 'source_removal' => json_encode($receipt, JSON_THROW_ON_ERROR)]];
    $projection = app(NativeInstanceTransferProjection::class);
    $projected = $projection->apply($operation, $terminal);
    expect($projected->error_code)->toBe($code)->and($projected->result['cutover'])->toBeTrue()
        ->and($projected->result['error_code'])->toBe($code)
        ->and($this->instance->fresh()->node_id)->toBe($this->destination->id)
        ->and($this->transfer->fresh()->error_code)->toBe($code);
    if ($code === 'instance.worktree_dependents') {
        expect($projected->result['details']['dependents'][0]['id'])->toBe($this->dependent->id)
            ->and($projected->result['details']['dependent_paths'])->toBe(['/fixture/moved']);
    } else {
        expect($projected->result['details']['path'])->toBe('/fixture/moved')
            ->and($projected->result['details']['instance_ids'])->toBe([$this->dependent->id]);
    }
    expect($projection->apply($projected, $terminal)->result)->toBe($projected->result);
    expect(app(DirectOperation::class)->definition('instance.transfer')['terminal_result']['captures']['source_removal'])
        ->toBe(['job' => 'transfer', 'step' => 'Clean retirement files', 'field' => 'stdout']);
})->with(['instance.worktree_dependents', 'instance.remove_refused']);
