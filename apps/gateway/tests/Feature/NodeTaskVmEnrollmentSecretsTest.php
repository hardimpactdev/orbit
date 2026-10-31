<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeAgentHashSource;
use App\Infrastructure\Nodes\NodeWorkerEnrollment;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Fleet\FleetFixtures;

final readonly class NodeTaskVmSecretFixtureProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        if (($operation->input[$operation->kind === 'task_vm.prepare_runtime' ? 'guest_node_id' : 'execution_node_id'] ?? null) !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The family refuses its changed execution root.', 422);
        }

        return $operation->input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return $operation;
    }
}

beforeEach(function (): void {
    $this->host = FleetFixtures::node('custody-bootstrap');
    $this->guest = FleetFixtures::node('custody-guest');
    $this->issuer = FleetFixtures::node('custody-issuer');
    $this->vm = TaskVm::query()->create(['node_id' => $this->guest->id, 'host_node_id' => $this->host->id,
        'name' => $this->guest->name, 'wireguard_ip' => $this->guest->wireguard_ip, 'provider' => 'incus', 'state' => 'provisioning']);
    $contribution = new readonly class(app(DirectOperation::class)->definitions()) implements DirectOperationContribution
    {
        public function __construct(private array $known) {}

        public function definitions(): array
        {
            return array_diff_key(array_fill_keys(['task_vm.provision', 'tasks.provision', 'task_vm.enroll', 'task_vm.prepare_runtime'],
                ['workflow' => 'operation-task-vm-enroll', 'projection' => NodeTaskVmSecretFixtureProjection::class,
                    'private_material' => ['secret_hash' => 'node.agent_secret_hash', 'worker_credentials' => 'node.worker_credentials'],
                    'secret_outputs' => ['secret_hash' => 'node.agent_secret_hash', 'worker_credentials' => 'node.worker_credentials']]), $this->known);
        }
    };
    app()->instance(DirectOperation::class, new DirectOperation([$contribution]));
    $id = $this->guest->id;
    $this->operation = Operation::query()->create(['kind' => 'task_vm.enroll', 'node_id' => $this->host->id,
        'target_type' => 'task_vm', 'target_id' => $this->vm->id, 'status' => OperationStatus::Running,
        'input' => ['task_vm_id' => $this->vm->id, 'guest_node_id' => $id, 'execution_node_id' => $this->host->id,
            'participant_node_ids' => [$this->host->id, $id, $this->issuer->id],
            'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $id],
            'worker_credentials' => ['$secret' => 'node.worker_credentials', 'id' => $id],
            'private_bindings' => ['secret_hash' => ['owner_node_id' => $id, 'read_node_ids' => [$this->host->id], 'write_node_ids' => [$this->host->id]],
                'worker_credentials' => ['owner_node_id' => $id, 'read_node_ids' => [$this->host->id], 'write_node_ids' => [$this->issuer->id]]]],
        'state' => ['swamp_run_id' => '418fba90-401a-4c69-93cb-70d96dd31210']]);
    $this->credentials = ['enrollment_token' => 'orbit-node-'.$id.'.'.str_repeat('a', 32), 'server_token' => 'orbit-node-'.$id.'-access.'.str_repeat('b', 32)];
});

it('keeps nullable initial material and protected outputs on the actual guest', function (string $kind): void {
    $this->operation->kind = $kind;
    if ($kind === 'task_vm.prepare_runtime') {
        $this->operation->node_id = $this->guest->id;
    }
    if ($kind === 'tasks.provision') {
        $project = Project::query()->create(['slug' => 'custody-project', 'name' => 'Custody', 'repository_url' => 'https://github.com/example/custody.git']);
        $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Custody', 'brief' => 'Custody', 'status' => 'backlog']);
        $this->vm->update(['group_id' => $group->id]);
        $this->operation->target_type = 'task_group';
        $this->operation->target_id = $group->id;
    }
    $hashes = app(NodeAgentHashSource::class);
    $workers = app(NodeWorkerEnrollment::class);
    expect($hashes->resolve($this->operation, $this->guest->id, '', 'secret_hash')->value)->toBeNull()
        ->and($workers->resolve($this->operation, $this->guest->id, '', 'worker_credentials')->value)->toBeNull();
    $hashes->store($this->operation, $this->guest->id, str_repeat('c', 64));
    $workers->store($this->operation, $this->guest->id, $this->credentials);
    expect($this->guest->fresh()->agent_secret_hash)->toBe(str_repeat('c', 64))
        ->and($this->host->fresh()->agent_secret_hash)->toBeNull()
        ->and($workers->resolve($this->operation, $this->guest->id, '', 'worker_credentials')->value)->toBe($this->credentials)
        ->and(Cache::get('nodes:worker-enrollment:operation:'.$this->operation->id))->not->toContain($this->credentials['enrollment_token']);
})->with(['task_vm.enroll', 'task_vm.prepare_runtime', 'task_vm.provision', 'tasks.provision']);

it('refuses marker reference binding VM or family ownership drift before writing', function (string $change): void {
    $input = $this->operation->input;
    match ($change) {
        'marker' => $input['guest_node_id'] = $this->host->id,
        'reference' => $input['secret_hash']['id'] = $this->host->id,
        'binding' => $input['private_bindings']['secret_hash']['owner_node_id'] = $this->host->id,
        'family' => $input['execution_node_id'] = $this->issuer->id,
        'vm' => $this->vm->update(['node_id' => $this->issuer->id]),
    };
    $this->operation->input = $input;
    expect(fn () => app(NodeAgentHashSource::class)->store($this->operation, $this->guest->id, str_repeat('c', 64)))->toThrow(ResourceOperationException::class);
    expect($this->guest->fresh()->agent_secret_hash)->toBeNull();
})->with(['marker', 'reference', 'binding', 'family', 'vm']);

it('denies foreign output owners invalid pairs terminal and undeclared kinds', function (): void {
    $source = app(NodeWorkerEnrollment::class);
    expect(fn () => $source->store($this->operation, $this->host->id, $this->credentials))->toThrow(ResourceOperationException::class);
    expect(fn () => $source->store($this->operation, $this->guest->id, [...$this->credentials, 'enrollment_token' => 'orbit-node-999.'.str_repeat('a', 32)]))->toThrow(ResourceOperationException::class);
    $this->operation->status = OperationStatus::Succeeded;
    expect(fn () => $source->store($this->operation, $this->guest->id, $this->credentials))->toThrow(ResourceOperationException::class);
    $this->operation->status = OperationStatus::Running;
    $this->operation->kind = 'task_vm.destroy';
    expect(fn () => $source->store($this->operation, $this->guest->id, $this->credentials))->toThrow(ResourceOperationException::class);
    expect(Cache::get('nodes:worker-enrollment:operation:'.$this->operation->id))->toBeNull();
});
