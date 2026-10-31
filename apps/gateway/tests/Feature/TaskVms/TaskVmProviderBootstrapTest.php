<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\DirectOperationPrivateMaterial;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmImageProjection;
use App\Infrastructure\TaskVms\TaskVmProviderBootstrapStore;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function providerBootstrapOperation(): array
{
    Http::preventStrayRequests();
    $gateway = Node::query()->create(['name' => 'provider-gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.14', 'public_ssh_host' => '192.0.2.14']);
    $guest = Node::query()->create(['name' => 'provider-guest', 'status' => 'provisioning', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.130', 'public_ssh_host' => '192.0.2.130']);
    $project = Project::query()->create(['name' => 'Provider bootstrap', 'slug' => 'provider-bootstrap', 'repository_url' => 'https://github.com/example/bootstrap.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Provision', 'brief' => 'Provision VM', 'status' => 'reserved']);
    $vm = TaskVm::query()->create(['provider' => 'upcloud', 'group_id' => $group->id, 'node_id' => $guest->id, 'name' => 'pending', 'state' => 'provisioning', 'wireguard_ip' => $guest->wireguard_ip]);
    $vm->update(['name' => 'tvm-'.$vm->id]);
    $direct = app(DirectOperation::class);
    if ($direct->definition('tasks.provision') === null) {
        $direct = new DirectOperation([new class implements DirectOperationContribution
        {
            public function definitions(): array
            {
                return ['tasks.provision' => ['workflow' => 'operation-test-provider', 'projection' => TaskVmImageProjection::class,
                    'private_material' => ['provider_bootstrap' => 'task_vm.provider_bootstrap'],
                    'secret_outputs' => ['provider_bootstrap' => 'task_vm.provider_bootstrap']]];
            }
        }]);
        app()->instance(DirectOperation::class, $direct);
    }
    app()->instance(DirectOperationPrivateBindings::class, new DirectOperationPrivateBindings($direct));
    $input = ['task_vm_id' => $vm->id, 'enrollment' => ['task_vm_id' => $vm->id, 'guest_node_id' => $guest->id],
        'provider_bootstrap' => ['$secret' => 'task_vm.provider_bootstrap', 'id' => $vm->id],
        'participant_node_ids' => [$gateway->id, $guest->id],
        'private_bindings' => ['provider_bootstrap' => ['owner_node_id' => $guest->id, 'read_node_ids' => [$gateway->id], 'write_node_ids' => [$gateway->id]]]];
    $operation = Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $group->id,
        'node_id' => $gateway->id, 'status' => 'running', 'input' => $input]);

    return [$vm, $operation, $gateway, $guest];
}

function providerBootstrapPair(): array
{
    return ['private' => "-----BEGIN OPENSSH PRIVATE KEY-----\ncHJpdmF0ZS1ieXRlcw==\n-----END OPENSSH PRIVATE KEY-----\n", 'public' => 'ssh-ed25519 YWJj provider'];
}

it('stages one encrypted provider host key before creation through the existing private boundary', function (): void {
    [$vm, $operation, $gateway] = providerBootstrapOperation();
    $transport = app(DirectOperationPrivateMaterial::class);
    expect($transport->read($operation, $gateway->id))->toBe(['provider_bootstrap' => null]);
    $pair = providerBootstrapPair();
    expect($transport->write($operation, ['provider_bootstrap' => $pair], $gateway->id))
        ->toBe(['provider_bootstrap' => ['$secret' => 'task_vm.provider_bootstrap', 'id' => $vm->id]]);
    expect($transport->read($operation, $gateway->id))->toBe(['provider_bootstrap' => $pair]);
    $vm->refresh();
    $raw = DB::table('task_vms')->where('id', $vm->id)->value('provider_bootstrap');
    expect($vm->ssh_host_key)->toBe($pair['public'])
        ->and($vm->toArray())->not->toHaveKey('provider_bootstrap')
        ->and($raw)->not->toContain($pair['private'])
        ->and(json_encode($operation->fresh()->input, JSON_THROW_ON_ERROR))->not->toContain($pair['private']);
    Http::assertNothingSent();
});

it('replays an identical key without replacing encrypted bytes and refuses rotation', function (): void {
    [$vm, $operation, $gateway] = providerBootstrapOperation();
    $transport = app(DirectOperationPrivateMaterial::class);
    $pair = providerBootstrapPair();
    $transport->write($operation, ['provider_bootstrap' => $pair], $gateway->id);
    $ciphertext = DB::table('task_vms')->where('id', $vm->id)->value('provider_bootstrap');
    $transport->write($operation, ['provider_bootstrap' => $pair], $gateway->id);
    expect(DB::table('task_vms')->where('id', $vm->id)->value('provider_bootstrap'))->toBe($ciphertext);
    expect(fn () => $transport->write($operation, ['provider_bootstrap' => [...$pair, 'public' => 'ssh-ed25519 ZGVm replacement']], $gateway->id))
        ->toThrow(ResourceOperationException::class);
    expect($vm->fresh()->provider_bootstrap)->toBe($pair);
});

it('refuses non-Gateway readers and writers even though the guest owns the key', function (): void {
    [$vm, $operation, , $guest] = providerBootstrapOperation();
    $transport = app(DirectOperationPrivateMaterial::class);
    expect(fn () => $transport->read($operation, $guest->id))->toThrow(ResourceOperationException::class)
        ->and(fn () => $transport->write($operation, ['provider_bootstrap' => providerBootstrapPair()], $guest->id))->toThrow(ResourceOperationException::class)
        ->and($vm->fresh()->provider_bootstrap)->toBeNull();
});

it('refuses a foreign group, unsupported parent or changed guest ownership', function (string $change): void {
    [$vm, $operation, $gateway] = providerBootstrapOperation();
    if ($change === 'group') {
        $operation->target_id++;
    } elseif ($change === 'kind') {
        $operation->kind = 'task_vm.enroll';
    } else {
        $input = $operation->input;
        $input['private_bindings']['provider_bootstrap']['owner_node_id'] = $gateway->id;
        $operation->input = $input;
    }
    expect(app(TaskVmProviderBootstrapStore::class)->ownerId($operation))->toBeNull()
        ->and(fn () => app(TaskVmProviderBootstrapStore::class)->store($operation, $vm->id, providerBootstrapPair()))->toThrow(ResourceOperationException::class)
        ->and($vm->fresh()->provider_bootstrap)->toBeNull();
})->with(['group', 'kind', 'owner']);

it('refuses malformed private material and an existing different public pin', function (): void {
    [$vm, $operation, $gateway] = providerBootstrapOperation();
    $transport = app(DirectOperationPrivateMaterial::class);
    expect(fn () => $transport->write($operation, ['provider_bootstrap' => ['private' => 'not-private-key', 'public' => 'ssh-ed25519 YWJj']], $gateway->id))
        ->toThrow(ResourceOperationException::class);
    $vm->update(['ssh_host_key' => 'ssh-ed25519 ZGVm retained']);
    expect(fn () => $transport->write($operation, ['provider_bootstrap' => providerBootstrapPair()], $gateway->id))
        ->toThrow(ResourceOperationException::class)
        ->and($vm->fresh()->provider_bootstrap)->toBeNull()
        ->and($vm->fresh()->ssh_host_key)->toBe('ssh-ed25519 ZGVm retained');
});
