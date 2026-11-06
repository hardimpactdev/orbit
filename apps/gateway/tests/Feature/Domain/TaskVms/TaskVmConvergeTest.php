<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmConvergeAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmConvergeIntent;
use App\Domain\TaskVms\TaskVmConvergeProjection;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

/** A ready task VM with its host, its active guest Node and the Gateway Node that holds the Pi server. */
function convergeWorld(TaskVmState $state = TaskVmState::Ready): TaskVm
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('shark', '10.44.0.11');
    $guest = $node('tvm-554', '10.44.0.131');
    $node('gateway', '10.44.0.2')->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $key = base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32));
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.$key.' fixture']);

    return TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-554',
        'state' => $state, 'address' => '10.251.78.33', 'wireguard_ip' => '10.44.0.131', 'size' => TaskVmSize::Normal]);
}

function convergeRefusal(Closure $call): string
{
    try {
        $call();
    } catch (ResourceOperationException $error) {
        return $error->errorCode;
    }

    throw new RuntimeException('The call was not refused.');
}

/** @return array<string,mixed> */
function convergeRun(string $status, string $endCheck = 'succeeded'): array
{
    return ['run_id' => 'fixture-converge', 'status' => $status, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => [TaskVmConvergeProjection::EndCheck => ['status' => $endCheck]], 'summary' => ['failed_steps' => $endCheck === 'failed' ? [TaskVmConvergeProjection::EndCheck] : []]]];
}

it('carries the pins of the release and the VM target in its intent', function (): void {
    $vm = convergeWorld();

    $intent = app(TaskVmConvergeIntent::class)->capture($vm, ['checkout' => '/home/orbit/orbit', 'subnet' => '10.232.2.0/24', 'interface' => 'nested']);

    expect($intent)->toMatchArray(['task_vm_id' => $vm->id, 'guest_node_id' => $vm->node_id, 'profile' => 'base', 'image' => false]);
    expect($intent['ssh']['target'])->toMatchArray(['host' => '10.44.0.131', 'user' => 'orbit', 'port' => 22, 'host_keys' => ['ssh-ed25519 fixture-public-key']]);
    expect($intent['recipe'])->toHaveKeys(['ubuntu', 'tools', 'base', 'common', 'php']);
    expect($intent['recipe']['tools']['pi']['artifacts']['amd64']['sha256'])->toBe($intent['pi']['sha256']);
    expect($intent['pi']['source']['ssh']['target']['host'])->toBe('10.44.0.2');
    expect($intent['pi']['source']['path'])->toEndWith($intent['recipe']['tools']['pi']['artifacts']['amd64']['filename']);
    expect(hash('sha256', $intent['vp_lock']))->toBe($intent['recipe']['tools']['vp']['lock_sha256']);
    expect($intent['nfs'])->toBe(['checkout' => '/home/orbit/orbit', 'subnet' => '10.232.2.0/24', 'interface' => 'nested']);
    expect(json_encode($intent, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE KEY');
})->group('task-vm-converge');

it('refuses a VM that is not ready before it records anything', function (): void {
    $vm = convergeWorld(TaskVmState::Parked);

    expect(convergeRefusal(fn () => app(StartTaskVmConvergeAction::class)->execute($vm->id)))->toBe('task_vm.converge_refused');
    expect(Operation::query()->where('kind', 'task_vm.converge')->count())->toBe(0);
})->group('task-vm-converge');

it('admits one converge on the guest Node and refuses a second while it runs', function (): void {
    $vm = convergeWorld();
    try {
        app(StartTaskVmConvergeAction::class)->execute($vm->id);
    } catch (Throwable $error) {
        // The run start after the commit needs a Swamp server the suite has not; the Operation is recorded before it.
        if (! str_contains($error::class, 'Swamp') && ! $error instanceof ResourceOperationException) {
            throw $error;
        }
    }
    $operation = Operation::query()->where('kind', 'task_vm.converge')->firstOrFail();

    expect([$operation->target_type, $operation->target_id, $operation->node_id])->toBe(['task_vm', $vm->id, $vm->node_id]);
    $operation->update(['status' => 'running']);
    expect(convergeRefusal(fn () => app(StartTaskVmConvergeAction::class)->execute($vm->id)))->toBe('operation.target_busy');
})->group('task-vm-converge');

it('records a succeeded converge only with its passed end check', function (): void {
    $vm = convergeWorld();
    $intent = app(TaskVmConvergeIntent::class)->capture($vm, null);
    $operation = Operation::query()->create(['kind' => 'task_vm.converge', 'target_type' => 'task_vm', 'target_id' => $vm->id, 'node_id' => $vm->node_id,
        'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'input' => ['intent' => $intent], 'state' => ['swamp_run_id' => 'fixture-converge']]);

    expect(convergeRefusal(fn () => app(TaskVmConvergeProjection::class)->apply($operation, convergeRun('succeeded', 'failed'))))->toBe('operation.result_invalid');

    $done = app(TaskVmConvergeProjection::class)->apply($operation->refresh(), convergeRun('succeeded'));

    expect($done->status->value)->toBe('succeeded');
    expect($done->result)->toMatchArray(['converged' => true, 'task_vm_id' => $vm->id, 'recipe_identity' => $intent['recipe_identity']]);
})->group('task-vm-converge');

it('registers the operator command that admits the converge', function (): void {
    expect(array_keys(Artisan::all()))->toContain('task-vms:converge');
})->group('task-vm-converge');
