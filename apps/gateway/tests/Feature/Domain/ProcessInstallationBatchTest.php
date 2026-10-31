<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Processes\ProcessInstallationBatch;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->source = Node::query()->create(['name' => 'batch-source', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.11']);
    $this->destination = Node::query()->create(['name' => 'batch-destination', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.12']);
    $project = Project::query()->create(['name' => 'Batch', 'slug' => 'batch-proof', 'repository_url' => 'https://example.test/batch.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->source->id, 'name' => 'proof', 'checkout_path' => '/srv/source', 'status' => 'active', 'provisioning_step' => 'active']);
    $this->process = Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $this->instance->id, 'name' => 'batch-proof',
        'runtime' => 'systemd', 'working_directory' => '/srv/source/app', 'runtime_config' => ['command' => ['/usr/bin/sleep', '300'], 'environment_file' => '/srv/source/.env', 'environment' => ['PASSWORD' => 'batch-private-value']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active']);
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            throw new RuntimeException('Account discovery must not run in batch admission or private hydration.');
        }
    });
    app()->instance(DirectOperation::class, new DirectOperation([new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['instance.transfer' => ['workflow' => 'operation-instance-transfer', 'projection' => ProcessInstanceOperation::class,
                'private_material' => ['source_installations' => 'process.installation', 'destination_installations' => 'process.installation']]];
        }
    }]));
    $this->batch = app(ProcessInstallationBatch::class);
    $this->target = function (Node $node, string $checkout): ProcessTarget {
        $placed = clone $this->instance;
        $placed->node_id = $node->id;
        $placed->checkout_path = $checkout;
        $placed->setRelation('node', $node);

        return new ProcessTarget($node, 'orbit', $checkout, certificateScope: 'app-instance-'.$placed->id, instance: $placed, environmentFile: $checkout.'/.env');
    };
    $this->captures = fn (): array => ['source_installations' => $this->batch->capture($this->instance, ($this->target)($this->source, '/srv/source')),
        'destination_installations' => $this->batch->capture($this->instance, ($this->target)($this->destination, '/srv/destination'))];
    $this->operation = function (array $captures): Operation {
        $operation = new Operation;
        $operation->forceFill(['id' => 123, 'kind' => 'instance.transfer', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->source->id,
            'input' => ['process_installations' => $captures, 'participant_node_ids' => [$this->source->id, $this->destination->id],
                'private_bindings' => ['source_installations' => ['owner_node_id' => $this->source->id, 'read_node_ids' => [$this->source->id], 'write_node_ids' => []],
                    'destination_installations' => ['owner_node_id' => $this->destination->id, 'read_node_ids' => [$this->destination->id], 'write_node_ids' => []]]]]);

        return $operation;
    };
});

it('captures two placements without account discovery, private bytes, children or record mutation', function (): void {
    $captures = ($this->captures)();
    $destination = $captures['destination_installations'];
    $native = $this->batch->input($destination);
    expect(json_encode($captures))->not->toContain('batch-private-value')
        ->and($native[0]['input']['installation']['account_user'])->toBe('orbit')
        ->and($destination['node_id'])->toBe($this->destination->id)
        ->and($this->process->fresh()->working_directory)->toBe('/srv/source/app')
        ->and(Operation::query()->count())->toBe(0);
});

it('binds private material to current placement while the parent remains on the source Node', function (): void {
    $operation = ($this->operation)(($this->captures)());
    $source = $this->batch->material($operation, 'source_installations')->value;
    expect($source[$this->process->id]['installation']['content'])->toContain(ProcessOperationAdmission::AccountHomeToken, '/srv/source/app')
        ->and($source[$this->process->id]['installation']['environment'])->toContain('batch-private-value');
    expect(fn () => $this->batch->material($operation, 'destination_installations'))->toThrow(ResourceOperationException::class);
    DB::transaction(function (): void {
        $relocated = $this->batch->placedProcess($this->process, '/srv/source', '/srv/destination');
        $this->instance->update(['node_id' => $this->destination->id, 'checkout_path' => '/srv/destination']);
        $this->process->update(['working_directory' => $relocated->working_directory, 'runtime_config' => $relocated->runtime_config]);
    });
    $destination = $this->batch->material($operation, 'destination_installations')->value;
    expect($operation->node_id)->toBe($this->source->id)
        ->and($destination[$this->process->id]['installation']['content'])->toContain('/srv/destination/app', '/srv/destination/.env');
    expect(fn () => $this->batch->material($operation, 'source_installations'))->toThrow(ResourceOperationException::class);
});

it('refuses destination material when Instance placement moved without the Process records', function (): void {
    $operation = ($this->operation)(($this->captures)());
    $this->instance->update(['node_id' => $this->destination->id, 'checkout_path' => '/srv/destination']);
    expect(fn () => $this->batch->material($operation, 'destination_installations'))->toThrow(ResourceOperationException::class);
});

it('refuses paths outside the captured checkout before writing placement', function (string $field, string $path): void {
    $this->process->update($field === 'working_directory' ? [$field => $path] : ['runtime_config' => [...$this->process->runtime_config, $field => $path]]);
    expect(fn () => ($this->captures)())->toThrow(ResourceOperationException::class)
        ->and($this->instance->fresh()->node_id)->toBe($this->source->id);
})->with([['working_directory', '/srv/source-sibling/app'], ['environment_file', '/etc/private.env'], ['working_directory', '/srv/destination/app'], ['working_directory', '/srv/source/../foreign'], ['environment_file', '/srv/source//.env']]);

it('refuses changed specifications or foreign Process ownership before returning material', function (string $change): void {
    $operation = ($this->operation)(($this->captures)());
    $this->process->update($change === 'owner' ? ['owner_id' => $this->instance->id + 100] : ['runtime_config' => [...$this->process->runtime_config, 'environment' => ['PASSWORD' => 'changed']]]);
    expect(fn () => $this->batch->material($operation, 'source_installations'))->toThrow(ResourceOperationException::class);
})->with(['owner', 'spec']);

it('keeps captured membership and rejects an undeclared selector', function (): void {
    $operation = ($this->operation)(($this->captures)());
    $later = $this->process->replicate();
    $later->name = 'later';
    $later->save();
    expect(array_keys($this->batch->material($operation, 'source_installations')->value))->toBe([$this->process->id]);
    expect(fn () => $this->batch->material($operation, 'installations'))->toThrow(ResourceOperationException::class);
});

it('preserves null Docker defaults during relocation', function (): void {
    $this->process->update(['runtime' => 'docker', 'working_directory' => null, 'runtime_config' => ['image' => 'example:1', 'command' => ['sleep', '300'], 'environment_file' => null]]);
    $relocated = $this->batch->placedProcess($this->process, '/srv/source', '/srv/destination');
    expect($relocated->working_directory)->toBeNull()->and($relocated->runtime_config['environment_file'])->toBeNull();
});
