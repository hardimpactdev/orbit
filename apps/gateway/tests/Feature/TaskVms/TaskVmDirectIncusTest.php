<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmIncusEffectAction;
use App\Actions\TaskVms\StartTaskVmLifecycleOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmIncusProjection;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    $this->host = Node::query()->create(['name' => 'host', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.13', 'wireguard_ip' => '10.44.0.13', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active]);
    $this->gateway = Node::query()->create(['name' => 'gateway', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14', 'status' => LifecycleStatus::Active]);
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->worker = Node::query()->create(['name' => 'worker', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.209', 'wireguard_ip' => '10.44.0.209', 'status' => LifecycleStatus::Active]);
    $this->worker->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $this->worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->host->id)]]);
    $this->vm = TaskVm::query()->create(['name' => 'pending', 'provider' => 'incus', 'host_node_id' => $this->host->id, 'state' => 'provisioning', 'wireguard_ip' => '10.44.0.129']);
    $this->vm->update(['name' => 'tvm-'.$this->vm->id]);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 '.base64_encode(str_repeat('a', 32)));
    app()->instance(SshKeyProvider::class, $keys);
    $this->pin = app(AgentDelivery::class)->pin($this->host);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-vm-incus', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $this->socket);
    $this->outcome = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['ready' => true]];
});

function admitDirectTaskVmIncus(string $verb = 'start'): Operation
{
    config(['swamp.direct_writers' => ['task_vm.incus_'.$verb => 'direct'], 'swamp.run_token' => 'fake.token']);

    return app(StartTaskVmIncusEffectAction::class)->execute(test()->vm, $verb);
}

it('keeps each Incus writer polled until its per-kind switch is enabled', function (): void {
    $op = app(StartTaskVmIncusEffectAction::class)->execute($this->vm, 'start');
    expect($op->state['task_vm_incus_writer'])->toBe('polled')->and($op->input)->not->toHaveKey('agent_sha256');
    config(['swamp.direct_writers' => ['task_vm.incus_start' => 'direct']]);
    expect(DirectOperation::owns($op->fresh()))->toBeFalse()->and($this->socket->sent)->toBe([]);
});

it('captures the owning host digest and binds the root before its first admitted read', function (): void {
    DB::beginTransaction();
    $op = admitDirectTaskVmIncus();
    $path = '/api/v1/direct-operations/'.$op->id.'?run_id='.$this->run;
    expect($this->socket->sent)->toBe([]);
    $this->getJson($path)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    DB::commit();
    $this->getJson($path)->assertOk()->assertJsonPath('data.input.agent_sha256', $this->pin)->assertJsonPath('data.input.intent.task_vm_id', $this->vm->id);
    expect(array_keys(app(TaskVmIncusProjection::class)->input($op->fresh())))->toBe(['agent_sha256', 'intent']);
    expect($this->socket->sent[0]['payload'])->toBe(['workflowIdOrName' => 'operation-task-vm-incus', 'inputs' => ['operation_id' => $op->id, 'kind' => 'task_vm.incus_start', 'node_id' => $this->host->id], 'runtimeTags' => ['orbit_start_key' => 'operation:'.$op->id]])
        ->and($op->fresh()->lock_key)->toBeNull()->and($op->fresh()->worker)->toBeNull()->and($op->fresh()->attempts)->toBe(0);
});

it('projects a successful direct effect idempotently without PHP effects or VM state changes', function (): void {
    $op = admitDirectTaskVmIncus();
    $path = '/api/v1/direct-operations/'.$op->id.'/result';
    $this->postJson($path, $this->outcome)->assertOk();
    $this->postJson($path, $this->outcome)->assertOk();
    expect($op->fresh()->status)->toBe(OperationStatus::Succeeded)->and($op->fresh()->state['steps']['incus-start'])->toBe(['ready' => true])
        ->and($op->fresh()->state)->not->toHaveKey('executor_runs')->and($this->vm->fresh()->state->value)->toBe('provisioning');
});

it('records bounded failure or cancellation without modifying the VM', function (string $status): void {
    $op = admitDirectTaskVmIncus();
    $code = $status === 'failed' ? 'task_vm.incus_failed' : null;
    $this->postJson('/api/v1/direct-operations/'.$op->id.'/result', [...$this->outcome, 'status' => $status, 'result' => $code === null ? [] : ['error_code' => $code], 'error_code' => $code])->assertOk();
    expect($op->fresh()->status->value)->toBe($status)->and($this->vm->fresh()->state->value)->toBe('provisioning');
})->with(['failed', 'cancelled']);

it('refuses unproven success raw output mismatched run and family relays', function (): void {
    $op = admitDirectTaskVmIncus();
    $path = '/api/v1/direct-operations/'.$op->id;
    $this->postJson($path.'/result', [...$this->outcome, 'result' => []])->assertUnprocessable();
    $this->postJson($path.'/result', [...$this->outcome, 'result' => ['ready' => true, 'stdout' => 'private']])->assertUnprocessable();
    $this->postJson($path.'/result', [...$this->outcome, 'run_id' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38'])->assertConflict();
    $this->postJson($path.'/primitive', ['run_id' => $this->run])->assertUnprocessable();
    expect($op->fresh()->status)->toBe(OperationStatus::Queued);
});

it('lets an enrolled Node worker access only its own host intent', function (): void {
    $op = admitDirectTaskVmIncus();
    $this->host->accessibleNodes()->attach($this->gateway);
    config(['operation_executor.worker_node_ids' => [$this->host->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->host->wireguard_ip]);
    $this->getJson('/api/v1/direct-operations/'.$op->id.'?run_id='.$this->run)->assertOk();
    $other = Node::query()->create(['name' => 'other', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'status' => LifecycleStatus::Active]);
    $other->accessibleNodes()->attach($this->gateway);
    config(['operation_executor.worker_node_ids' => [$other->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip]);
    $this->getJson('/api/v1/direct-operations/'.$op->id.'?run_id='.$this->run)->assertForbidden();
});

it('requires the destruction boundary before admitting a direct provider deletion', function (): void {
    expect(fn () => admitDirectTaskVmIncus('delete'))->toThrow(ResourceOperationException::class, 'The VM has no proven destruction boundary.');
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('keeps a directly started provider deletion bound to its owning destruction child', function (): void {
    $parent = app(StartTaskVmLifecycleOperationAction::class)->execute($this->vm->id, 'destroy');
    $children = app(OperationChildren::class);
    $boundary = $children->admit($parent, ['key' => 'destroy-intent'])['operation'];
    $boundary->update(['status' => OperationStatus::Succeeded, 'result' => ['ready' => true]]);
    $this->vm->update(['state' => 'destroying']);
    config(['swamp.direct_writers' => ['task_vm.incus_delete' => 'direct'], 'swamp.run_token' => 'fake.token']);
    $child = $children->admit($parent, ['key' => 'provider-delete'])['operation'];
    $this->postJson('/api/v1/direct-operations/'.$child->id.'/result', $this->outcome)->assertOk();
    expect($children->requireSucceeded($parent, 'provider-delete')->id)->toBe($child->id)
        ->and($child->node_id)->toBe($this->host->id)
        ->and($child->input['agent_sha256'])->toBe($this->pin)
        ->and($this->vm->fresh()->state->value)->toBe('destroying');
});

function directCacheParent(): Operation
{
    test()->vm->update(['state' => 'ready']);
    config(['swamp.run_token' => 'fake.token']);

    return Operation::query()->create(['kind' => 'task_vm.prepare_package_cache', 'status' => 'queued', 'target_type' => 'task_vm', 'target_id' => test()->vm->id, 'node_id' => test()->host->id,
        'input' => ['host_node_id' => test()->host->id, 'guest' => ['project' => 'orbit-tasks', 'name' => test()->vm->name], 'artifacts_json' => '{"artifacts":[]}']]);
}

it('admits fixed cache initialization directly with start binding and idempotent ready projection', function (): void {
    $parent = directCacheParent();
    DB::beginTransaction();
    $child = app(OperationChildren::class)->admit($parent, ['key' => 'cache-initialize'])['operation'];
    $path = '/api/v1/direct-operations/'.$child->id;
    $this->getJson($path.'?run_id='.$this->run)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    DB::commit();
    $this->getJson($path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.intent', ['task_vm_id' => $this->vm->id, 'operation' => 'initialize']);
    expect(DirectOperation::owns($child))->toBeTrue()->and($child->node_id)->toBe($this->host->id)->and($child->input['agent_sha256'])->toBe($this->pin);
    $this->postJson($path.'/result', $this->outcome)->assertOk();
    $this->postJson($path.'/result', $this->outcome)->assertOk();
    expect(app(OperationChildren::class)->requireSucceeded($parent, 'cache-initialize')->id)->toBe($child->id)
        ->and($this->vm->fresh()->state->value)->toBe('ready')->and($child->fresh()->state)->not->toHaveKey('executor_runs');
    expect(fn () => app(SwampOperationExecutor::class)->claim('task_vm.cache_initialize', 'old-worker', $this->worker->id))->toThrow(ResourceOperationException::class);
});

it('refuses cache initialization on a foreign host provider lifecycle or changed fixed input before Swamp starts', function (string $change): void {
    directCacheParent();
    $input = ['effect_key' => 'cache-initialize'];
    $nodeId = $this->host->id;
    match ($change) {
        'host' => $nodeId++, 'provider' => $this->vm->update(['provider' => 'upcloud']),
        'state' => $this->vm->update(['state' => 'parked']), 'payload' => $input = ['operation' => 'fill'],
        'token' => $input = ['github_token' => 'literal-forbidden'],
    };
    expect(fn () => app(TaskVmIncusProjection::class)->admitCache('task_vm.cache_initialize', $this->vm->id, $nodeId, $input))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toBe([]);
})->with(['host', 'provider', 'state', 'payload', 'token']);

it('refuses a polled cache initializer after its old executor is removed', function (): void {
    $parent = directCacheParent();
    config(['swamp.direct_writers' => ['task_vm.cache_initialize' => 'polled']]);
    expect(fn () => app(OperationChildren::class)->admit($parent, ['key' => 'cache-initialize']))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toBe([])->and($parent->children()->count())->toBe(0);
});

it('admits a direct read-only cache mount for exactly its ready VM and host project', function (): void {
    $parent = directCacheParent();
    $child = app(OperationChildren::class)->admit($parent, ['key' => 'cache-mount'])['operation'];
    $path = '/api/v1/direct-operations/'.$child->id;
    $this->getJson($path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.intent.task_vm_id', $this->vm->id)->assertJsonPath('data.input.intent.project', 'orbit-tasks')->assertJsonPath('data.input.intent.name', $this->vm->name);
    expect(DirectOperation::owns($child))->toBeTrue()->and($child->node_id)->toBe($this->host->id);
    $this->postJson($path.'/result', $this->outcome)->assertOk();
    $this->postJson($path.'/result', $this->outcome)->assertOk();
    expect(app(OperationChildren::class)->requireSucceeded($parent, 'cache-mount')->id)->toBe($child->id)
        ->and($child->fresh()->state)->not->toHaveKey('executor_runs');
    expect(fn () => app(SwampOperationExecutor::class)->claim('task_vm.cache_mount', 'old-worker', $this->worker->id))->toThrow(ResourceOperationException::class);
});

it('refuses a changed cache mount target project path source or lifecycle before starting Swamp', function (string $change): void {
    directCacheParent();
    $input = ['effect_key' => 'cache-mount', 'project' => 'orbit-tasks', 'name' => $this->vm->name];
    $nodeId = $this->host->id;
    match ($change) {
        'host' => $nodeId++, 'provider' => $this->vm->update(['provider' => 'upcloud']),
        'state' => $this->vm->update(['state' => 'parked']), 'project' => $input['project'] = 'operator',
        'name' => $input['name'] = 'tvm-999', 'path' => $input['path'] = '/operator',
        'source' => $input['source'] = '/operator', 'readonly' => $input['readonly'] = false,
        'identity' => $this->vm->update(['name' => 'operator-vm']),
    };
    expect(fn () => app(TaskVmIncusProjection::class)->admitCache('task_vm.cache_mount', $this->vm->id, $nodeId, $input))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toBe([]);
})->with(['host', 'provider', 'state', 'project', 'name', 'path', 'source', 'readonly', 'identity']);

it('refuses a polled cache mount after its old executor is removed', function (): void {
    $parent = directCacheParent();
    config(['swamp.direct_writers' => ['task_vm.cache_mount' => 'polled']]);
    expect(fn () => app(OperationChildren::class)->admit($parent, ['key' => 'cache-mount']))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toBe([])->and($parent->children()->count())->toBe(0);
});

it('seeds only the parent-selected immutable guest cache inventory through direct admission', function (): void {
    $parent = directCacheParent();
    $artifact = ['manager' => 'npm', 'package' => 'demo', 'version' => '1.0.0', 'url' => 'https://registry.npmjs.org/demo/-/demo-1.0.0.tgz', 'integrity' => null, 'key' => 'npm/demo.tgz'];
    $parent->update(['input' => [...$parent->input, 'artifacts_json' => json_encode(['artifacts' => [$artifact]], JSON_THROW_ON_ERROR)]]);
    $child = app(OperationChildren::class)->admit($parent, ['key' => 'cache-seed'])['operation'];
    $path = '/api/v1/direct-operations/'.$child->id;
    $this->getJson($path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.intent.artifacts', [$artifact])->assertJsonPath('data.input.intent.guest.name', $this->vm->name);
    $parent->update(['input' => [...$parent->input, 'artifacts_json' => '{"artifacts":[]}']]);
    expect(app(TaskVmIncusProjection::class)->input($child)['intent']['artifacts'])->toBe([$artifact]);
    $this->postJson($path.'/result', $this->outcome)->assertOk();
    $this->postJson($path.'/result', $this->outcome)->assertOk();
    expect(app(OperationChildren::class)->requireSucceeded($parent, 'cache-seed')->id)->toBe($child->id);
});

it('refuses foreign guests malformed inventory credentials and polled cache seeding before starting Swamp', function (string $change): void {
    $parent = directCacheParent();
    $input = ['effect_key' => 'cache-seed', 'guest' => ['project' => 'orbit-tasks', 'name' => $this->vm->name], 'parameters_json' => '{"artifacts":[]}'];
    match ($change) {
        'guest' => $input['guest']['name'] = 'tvm-999', 'project' => $input['guest']['project'] = 'operator',
        'json' => $input['parameters_json'] = 'bad', 'shape' => $input['parameters_json'] = '{"artifacts":{}}',
        'extra' => $input['parameters_json'] = '{"artifacts":[],"github_token":"literal"}',
        'count' => $input['parameters_json'] = json_encode(['artifacts' => array_fill(0, 2049, ['manager' => 'npm', 'package' => 'demo', 'version' => '1.0.0', 'url' => 'https://registry.npmjs.org/demo', 'integrity' => null, 'key' => 'demo'])], JSON_THROW_ON_ERROR),
        'url' => $input['parameters_json'] = json_encode(['artifacts' => [['manager' => 'npm', 'package' => 'demo', 'version' => '1.0.0', 'url' => 'https://u:p@registry.npmjs.org/demo', 'integrity' => null, 'key' => 'demo']]], JSON_THROW_ON_ERROR),
        'oversized' => $input['parameters_json'] = str_repeat(' ', 2 * 1024 * 1024 + 1),
        'polled' => config(['swamp.direct_writers' => ['task_vm.cache_seed' => 'polled']]),
    };
    if ($change === 'polled') {
        expect(fn () => app(OperationChildren::class)->admit($parent, ['key' => 'cache-seed']))->toThrow(ResourceOperationException::class);
    } else {
        expect(fn () => app(TaskVmIncusProjection::class)->admitCache('task_vm.cache_seed', $this->vm->id, $this->host->id, $input))->toThrow(ResourceOperationException::class);
    }
    expect($this->socket->sent)->toBe([]);
})->with(['guest', 'project', 'json', 'shape', 'extra', 'count', 'url', 'oversized', 'polled']);
