<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmEnrollmentProjection;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmEnrollmentMaterialSource;
use App\Infrastructure\TaskVms\TaskVmImageArtifactSource;
use App\Infrastructure\TaskVms\TaskVmSecretSource;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;

/** @return array{TaskVm, Operation} */
function enrollmentMaterialParent(): array
{
    $project = Project::query()->create(['name' => 'Enrollment material', 'slug' => 'enrollment-material', 'repository_url' => 'https://github.com/example/enrollment.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Provision', 'brief' => 'Prepare the VM', 'status' => 'reserved', 'orchestration' => 'external']);
    $node = Node::query()->create(['name' => 'tvm-material', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'provisioning', 'wireguard_ip' => '10.44.0.130', 'public_ssh_host' => '192.0.2.130']);
    $vm = TaskVm::query()->create(['name' => 'tvm-material', 'provider' => 'incus', 'state' => 'provisioning', 'node_id' => $node->id, 'group_id' => $group->id, 'wireguard_ip' => '10.44.0.130']);
    $operation = new Operation(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => 14,
        'input' => ['task_vm_id' => $vm->id, 'enrollment' => ['task_vm_id' => $vm->id, 'guest_node_id' => $node->id, 'runtime' => ['pi_sha256' => str_repeat('a', 64)]],
            'pi_artifact' => ['path' => '/not-read', 'sha256' => str_repeat('a', 64)]]]);

    return [$vm, $operation];
}

function enrollmentMaterialBindings(Operation $operation, string $field, int $owner): void
{
    $known = app(DirectOperation::class)->definitions();
    $contribution = new class($known) implements DirectOperationContribution
    {
        public function __construct(private array $known) {}

        public function definitions(): array
        {
            return isset($this->known['tasks.provision']) ? [] : ['tasks.provision' => [
                'workflow' => 'operation-test-enrollment', 'projection' => TaskVmEnrollmentProjection::class,
                'private_material' => ['installation' => 'task_vm.installation', 'first_visit' => 'task_vm.first_visit', 'enrollment_network' => 'task_vm.enrollment_network'],
            ]];
        }
    };
    app()->instance(DirectOperationPrivateBindings::class, new DirectOperationPrivateBindings(new DirectOperation([$contribution])));
    $operation->input = [...$operation->input,
        'participant_node_ids' => array_values(array_unique([14, $owner, $operation->input['enrollment']['guest_node_id']])),
        'private_bindings' => [$field => ['owner_node_id' => $owner, 'read_node_ids' => [14], 'write_node_ids' => []]],
    ];
}

it('binds native private enrollment material to the admitted VM and parent group', function (): void {
    [$vm, $operation] = enrollmentMaterialParent();
    $source = new TaskVmEnrollmentMaterialSource('installation');
    expect($source->ownerId($operation))->toBe($vm->id);
    $operation->target_id++;
    expect($source->ownerId($operation))->toBeNull();
});

it('refuses private material when a nested guest selector differs from the VM owner', function (): void {
    [$vm, $operation] = enrollmentMaterialParent();
    $operation->input = [...$operation->input, 'enrollment' => ['task_vm_id' => $vm->id, 'guest_node_id' => $vm->node_id + 1]];
    $source = new TaskVmEnrollmentMaterialSource('installation');
    expect($source->ownerId($operation))->toBeNull();
    expect(fn () => $source->resolve($operation, $vm->id, '', 'installation'))->toThrow(ResourceOperationException::class);
});

it('refuses a foreign installation binding before rendering any private content', function (): void {
    [$vm, $operation] = enrollmentMaterialParent();
    enrollmentMaterialBindings($operation, 'installation', $vm->node_id + 1);
    expect(fn () => new TaskVmEnrollmentMaterialSource('installation')->resolve($operation, $vm->id, '', 'installation'))->toThrow(ResourceOperationException::class);
});

it('provides the existing pinned UpCloud SSH material privately without minting a credential', function (): void {
    [$vm, $operation] = enrollmentMaterialParent();
    $vm->update(['provider' => 'upcloud', 'address' => '192.0.2.130', 'ssh_host_key' => 'ssh-ed25519 AAAA admitted']);
    enrollmentMaterialBindings($operation, 'first_visit', $vm->node_id);
    $ssh = Mockery::mock(SshKeyProvider::class);
    $ssh->shouldReceive('privateKeyPath')->once()->andReturn('/private/managed-ssh');
    app()->instance(SshKeyProvider::class, $ssh);
    $value = new TaskVmEnrollmentMaterialSource('first_visit')->resolve($operation, $vm->id, '', 'first_visit');
    expect($value->value)->toBe(['kind' => 'ssh', 'address' => '192.0.2.130', 'publicKey' => 'ssh-ed25519 AAAA admitted', 'identity' => '/private/managed-ssh']);
    expect(json_encode($value))->toBe('"[REDACTED]"');
    expect(json_encode($operation->input))->not->toContain('/private/managed-ssh');
});

it('serves the admitted Pi artifact only for the same enrollment pin', function (): void {
    [$vm, $operation] = enrollmentMaterialParent();
    $source = new TaskVmImageArtifactSource;
    expect($source->ownerId($operation))->toBe($vm->id);
    $operation->input = [...$operation->input, 'pi_artifact' => ['path' => '/not-read', 'sha256' => str_repeat('b', 64)]];
    expect($source->ownerId($operation))->toBeNull();
    expect(fn () => $source->artifact($operation, $vm->id))->toThrow(ResourceOperationException::class);
});

it('returns absent first-visit material until its native provider result is projected', function (): void {
    [$vm, $operation] = enrollmentMaterialParent();
    $vm->update(['provider' => 'upcloud', 'address' => null, 'ssh_host_key' => null]);
    enrollmentMaterialBindings($operation, 'first_visit', $vm->node_id);
    $ssh = Mockery::mock(SshKeyProvider::class);
    $ssh->shouldNotReceive('privateKeyPath');
    app()->instance(SshKeyProvider::class, $ssh);

    expect(new TaskVmEnrollmentMaterialSource('first_visit')->resolve($operation, $vm->id, '', 'first_visit')->value)->toBeNull();
    $operation->target_id++;
    expect(fn () => new TaskVmEnrollmentMaterialSource('first_visit')->resolve($operation, $vm->id, '', 'first_visit'))->toThrow(ResourceOperationException::class);
});

it('binds the provision parent cloud token to its actual UpCloud VM before any credential read', function (): void {
    [$vm, $operation] = enrollmentMaterialParent();
    $source = new TaskVmSecretSource('upcloud.api');
    expect($source->ownerId($operation))->toBeNull();
    $vm->update(['provider' => 'upcloud']);
    expect($source->ownerId($operation))->toBe($vm->id);
    $operation->target_id++;
    expect($source->ownerId($operation))->toBeNull();
});
