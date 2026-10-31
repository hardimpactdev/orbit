<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmDestroyAction;
use App\Actions\TaskVms\StartTaskVmLifecycleOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskReviewedCommitSource;
use App\Domain\TaskVms\TaskVmDestroyIntent;
use App\Domain\TaskVms\TaskVmDestroyProjection;
use App\Domain\TaskVms\TaskVmProvider;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\RouteRemovalResidue;
use App\Models\Task;
use App\Models\TaskReviewedCommit;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    $this->host = Node::query()->create(['name' => 'destroy-host', 'user' => 'nckrtl', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.13', 'public_ssh_host' => '192.0.2.13']);
    $this->gateway = Node::query()->create(['name' => 'destroy-gateway', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.14', 'public_ssh_host' => '192.0.2.14']);
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    config(['dependency_detection.services_node_id' => $this->gateway->id]);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->host->id)]]);
    $this->vm = TaskVm::query()->create(['name' => 'pending', 'provider' => 'incus', 'state' => 'failed', 'host_node_id' => $this->host->id, 'wireguard_ip' => '10.44.0.208', 'pi_token' => 'private-pi-token']);
    $this->vm->update(['name' => 'tvm-'.$this->vm->id]);
    app()->instance(TaskVmProvider::class, Mockery::mock(TaskVmProvider::class));
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
});

function savedDestroyIntent(): Operation
{
    $input = app(TaskVmDestroyIntent::class)->capture(test()->vm);

    return Operation::query()->create(['kind' => 'task_vm.destroy', 'target_type' => 'task_vm', 'target_id' => test()->vm->id,
        'node_id' => $input['node_id'], 'status' => 'queued', 'triggered_by' => 'fixture', 'input' => $input]);
}

it('records exact provider removal scope without deleting resources or exposing private material', function (): void {
    $before = $this->vm->fresh()->getRawOriginal();
    $operation = savedDestroyIntent();
    $input = app(TaskVmDestroyIntent::class)->input($operation);

    expect($input['removal_id'])->toBeUuid();
    expect($input['node_id'])->toBe($this->host->id)->and($input['guest_node_id'])->toBeNull()
        ->and($input['workspace_ids'])->toBe([])->and($input['route_ids'])->toBe([])
        ->and($input['pool'])->toBe('orbit-tasks')
        ->and($input['vm'])->toBe(['project' => 'orbit-tasks', 'name' => $this->vm->name, 'cidr' => '10.251.77.0/24']);
    expect(json_encode($input))->not->toContain('private-pi-token');
    expect($this->vm->fresh()->getRawOriginal())->toBe($before);
});

it('keeps UpCloud intent on the Gateway and strips its existing credential reference from public input', function (): void {
    $this->vm->update(['provider' => 'upcloud', 'host_node_id' => null, 'provider_id' => '8330af24-e530-48a0-810c-803f101c70ec']);
    $operation = savedDestroyIntent();

    expect($operation->node_id)->toBe($this->gateway->id);
    expect($operation->input['upcloud_token'])->toBe(['$secret' => 'upcloud.api', 'id' => $this->vm->id]);
    expect(app(TaskVmDestroyIntent::class)->input($operation))->not->toHaveKey('upcloud_token');
});

it('refuses stale ownership before any private read or provider effect', function (array $changes): void {
    $operation = savedDestroyIntent();
    $this->vm->update($changes);

    expect(fn () => app(TaskVmDestroyIntent::class)->owned($operation->fresh()))->toThrow(ResourceOperationException::class);
})->with([
    [['provider_id' => '8330af24-e530-48a0-810c-803f101c70ec']],
    [['wireguard_ip' => '10.44.0.207']],
    [['worker_stop_required' => true]],
    [['model_key' => str_repeat('c', 64)]],
]);

it('retains an earlier uncertain Operation instead of admitting a parallel destroy', function (): void {
    $earlier = Operation::query()->create(['kind' => 'task_vm.provision', 'target_type' => 'task_vm', 'target_id' => $this->vm->id,
        'node_id' => $this->host->id, 'status' => 'queued', 'triggered_by' => 'fixture', 'input' => []]);

    expect(fn () => app(StartTaskVmDestroyAction::class)->execute($this->vm->id))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->where('target_type', 'task_vm')->where('target_id', $this->vm->id)->count())->toBe(1);
    expect($earlier->fresh()->status->value)->toBe('queued');
});

it('does not admit work for a destroyed VM', function (): void {
    $this->vm->update(['state' => 'destroyed']);

    expect(app(StartTaskVmDestroyAction::class)->execute($this->vm->id))->toBeNull();
    expect(Operation::query()->where('target_type', 'task_vm')->where('target_id', $this->vm->id)->exists())->toBeFalse();
});

it('preserves unpublished approved work both at admission and after an intent was recorded', function (bool $admitted): void {
    $project = Project::query()->create(['name' => 'Destroy canary', 'slug' => 'destroy-canary', 'repository_url' => 'https://github.com/acme/destroy.git', 'default_branch' => 'main', 'review_and_merge' => true, 'merge_check' => 'Required checks']);
    $group = Task::topLevel()->create(['orchestration' => 'external', 'project_id' => $project->id,
        'title' => 'Preserve work', 'brief' => 'Keep this approved work', 'status' => 'cancelled', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    $this->vm->update(['group_id' => $group->id]);
    $operation = $admitted ? savedDestroyIntent() : null;
    TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => str_repeat('c', 40), 'source' => TaskReviewedCommitSource::OrbitPush]);

    expect(fn () => $operation === null ? app(TaskVmDestroyIntent::class)->capture($this->vm->fresh())
        : app(TaskVmDestroyIntent::class)->input($operation))->toThrow(ResourceOperationException::class, 'Publish the approved Task work');
    expect($this->vm->fresh()->state->value)->toBe('failed');
    $this->assertModelExists($group);
})->with([false, true]);

it('returns kind unavailable before admission when complete cleanup participants are missing', function (array $changes): void {
    $this->vm->update($changes);
    $error = null;
    try {
        app(StartTaskVmDestroyAction::class)->execute($this->vm->id);
    } catch (ResourceOperationException $exception) {
        $error = $exception;
    }

    expect($error)->toBeInstanceOf(ResourceOperationException::class);
    expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(409);
    expect(Operation::query()->where('target_type', 'task_vm')->where('target_id', $this->vm->id)->exists())->toBeFalse();
    expect($this->vm->fresh()->state->value)->toBe('failed');
})->with([[['model_key' => str_repeat('c', 64)]]]);

it('projects the provider-only removal inside the truthful parent transaction without an Operation child', function (): void {
    $input = app(TaskVmDestroyIntent::class)->capture($this->vm);

    DB::transaction(fn () => app(TaskVmDestroyProjection::class)->projectRemoval($input));

    expect($this->vm->fresh()->state->value)->toBe('destroyed');
    expect($this->vm->fresh()->pi_token)->toBeNull();
    expect(Operation::query()->where('target_type', 'task_vm')->where('target_id', $this->vm->id)->exists())->toBeFalse();
});

it('preserves VM metadata when the owning Task parent projection rolls back', function (): void {
    $input = app(TaskVmDestroyIntent::class)->capture($this->vm);
    $before = $this->vm->fresh()->getRawOriginal();

    expect(fn () => DB::transaction(function () use ($input): void {
        app(TaskVmDestroyProjection::class)->projectRemoval($input);
        throw new RuntimeException('Parent projection rolled back');
    }))->toThrow(RuntimeException::class, 'Parent projection rolled back');

    expect($this->vm->fresh()->getRawOriginal())->toBe($before);
});

it('composes the owning Node projector and VM record projection in the same Task transaction', function (): void {
    $guest = Node::query()->create(['name' => $this->vm->name, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'status' => LifecycleStatus::Active, 'wireguard_ip' => $this->vm->wireguard_ip, 'public_ssh_host' => '192.0.2.208']);
    $this->vm->update(['node_id' => $guest->id]);
    $input = app(TaskVmDestroyIntent::class)->capture($this->vm->fresh());
    expect($input['node_removal'])->toMatchArray(['schema_version' => 1, 'task_vm_id' => $this->vm->id, 'node_id' => $guest->id, 'machine_deleted' => true, 'offline' => true]);
    expect($input['participant_node_ids'])->toBe([$this->host->id, $guest->id, $this->gateway->id]);

    DB::transaction(fn () => app(TaskVmDestroyProjection::class)->projectRemoval($input));

    $this->assertModelMissing($guest);
    $this->assertModelExists($this->host);
    $this->assertModelExists($this->gateway);
    expect($this->vm->fresh()->node_id)->toBeNull();
    expect($this->vm->fresh()->state->value)->toBe('destroyed');
});

it('rolls back both Node removal and VM finalization with the parent Task projection', function (): void {
    $guest = Node::query()->create(['name' => $this->vm->name, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'status' => LifecycleStatus::Active, 'wireguard_ip' => $this->vm->wireguard_ip, 'public_ssh_host' => '192.0.2.208']);
    $this->vm->update(['node_id' => $guest->id]);
    $input = app(TaskVmDestroyIntent::class)->capture($this->vm->fresh());

    expect(fn () => DB::transaction(function () use ($input): void {
        app(TaskVmDestroyProjection::class)->projectRemoval($input);
        throw new RuntimeException('Task rollback');
    }))->toThrow(RuntimeException::class, 'Task rollback');

    $this->assertModelExists($guest);
    expect($this->vm->fresh()->node_id)->toBe($guest->id);
    expect($this->vm->fresh()->state->value)->toBe('failed');
});

it('returns only the canonical public input for the exact admitted cleanup participant', function (): void {
    $this->host->update(['ssh_host_fingerprint' => 'SHA256:destroy-host']);
    config(['swamp_workers.nodes' => [$this->host->id]]);
    RouteRemovalResidue::query()->create(['node_id' => $this->host->id, 'route_id' => 987650, 'domain' => 'gone.orbit', 'steps' => ['caddy']]);
    $guest = Node::query()->create(['name' => $this->vm->name, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'status' => LifecycleStatus::Active, 'wireguard_ip' => $this->vm->wireguard_ip, 'public_ssh_host' => '192.0.2.208']);
    $this->vm->update(['node_id' => $guest->id]);
    $operation = savedDestroyIntent();

    $view = app(TaskVmDestroyProjection::class)->publicInput($operation, $this->host->id);

    expect($view['node_id'])->toBe($this->host->id);
    expect($view['task_vm_id'])->toBe($this->vm->id);
    expect($view['node_cleanup_participant']['removal_node_id'])->toBe($guest->id);
    expect($view['node_cleanup_participant']['node_id'])->toBe($this->host->id);
    expect($view['node_cleanup_participant']['network'])->toBeNull();
    expect($view['node_cleanup_participant']['residue'])->toBeArray();
    expect(app(TaskVmDestroyProjection::class)->input($operation))->toBe($view);
    expect($view)->not->toHaveKey('upcloud_token')->not->toHaveKey('model_key')->not->toHaveKey('pi_token');
    expect(json_encode($view))->not->toContain('private-pi-token');
    expect(app(TaskVmDestroyProjection::class)->publicInput($operation, $this->gateway->id)['task_vm_id'])->toBe($this->vm->id);
    expect(fn () => app(TaskVmDestroyProjection::class)->publicInput($operation, $guest->id))->toThrow(ResourceOperationException::class);
});

it('refuses a public cleanup view after the captured Node identity changes', function (): void {
    $this->host->update(['ssh_host_fingerprint' => 'SHA256:destroy-host']);
    config(['swamp_workers.nodes' => [$this->host->id]]);
    RouteRemovalResidue::query()->create(['node_id' => $this->host->id, 'route_id' => 987650, 'domain' => 'gone.orbit', 'steps' => ['caddy']]);
    $guest = Node::query()->create(['name' => $this->vm->name, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'status' => LifecycleStatus::Active, 'wireguard_ip' => $this->vm->wireguard_ip, 'public_ssh_host' => '192.0.2.208']);
    $this->vm->update(['node_id' => $guest->id]);
    $operation = savedDestroyIntent();
    $guest->update(['name' => 'another-vm']);

    expect(fn () => app(TaskVmDestroyProjection::class)->publicInput($operation, $this->host->id))->toThrow(ResourceOperationException::class);
});

it('routes the normal lifecycle entry to one direct destroy root without changing VM state', function (): void {
    $before = $this->vm->fresh()->getRawOriginal();
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-vm-destroy', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'destroy-fixture-token']);
    $operation = app(StartTaskVmLifecycleOperationAction::class)->execute($this->vm->id, 'destroy');

    expect($operation?->kind)->toBe('task_vm.destroy');
    expect($operation?->input['task_vm_id'])->toBe($this->vm->id);
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-task-vm-destroy');
    expect(app(StartTaskVmLifecycleOperationAction::class)->execute($this->vm->id, 'destroy')?->id)->toBe($operation?->id);
    expect(Operation::query()->where('target_type', 'task_vm')->where('target_id', $this->vm->id)->count())->toBe(1);
    expect($this->vm->fresh()->getRawOriginal())->toBe($before);
});

it('projects native terminal status with only its bounded VM changes', function (string $status): void {
    $bootstrap = ['private' => 'staged-provider-private-key', 'public' => 'staged-provider-public-key'];
    $this->vm->update(['provider_bootstrap' => $bootstrap]);
    $operation = savedDestroyIntent();
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $operation->update(['state' => ['swamp_run_id' => $run]]);
    $data = ['run_id' => $run, 'status' => $status, 'started_at' => '2026-10-31T09:00:00.000Z', 'finished_at' => '2026-10-31T09:00:01.000Z',
        'error_code' => $status === 'failed' ? 'task_vm.destroy_failed' : null,
        'result' => $status === 'succeeded' ? ['task_vm_id' => $this->vm->id, 'removal_id' => $operation->input['removal_id'], 'removed' => true] : []];

    app(TaskVmDestroyProjection::class)->apply($operation, $data);
    app(TaskVmDestroyProjection::class)->apply($operation->fresh(), $data);

    expect($operation->fresh()->status->value)->toBe($status);
    expect($this->vm->fresh()->state->value)->toBe($status === 'succeeded' ? 'destroyed' : 'failed');
    expect($this->vm->fresh()->pi_token)->toBe($status === 'succeeded' ? null : 'private-pi-token');
    expect($this->vm->fresh()->provider_bootstrap)->toBe($status === 'succeeded' ? null : $bootstrap);
})->with(['succeeded', 'failed', 'cancelled']);

it('records the native Node cleanup checkpoint without removing records or credentials', function (bool $started): void {
    $guest = Node::query()->create(['name' => $this->vm->name, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'status' => LifecycleStatus::Active, 'wireguard_ip' => $this->vm->wireguard_ip, 'public_ssh_host' => '192.0.2.208']);
    $this->vm->update(['node_id' => $guest->id, 'provider_bootstrap' => ['private' => 'provider-key', 'public' => 'provider-public']]);
    $operation = savedDestroyIntent();
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $operation->update(['state' => ['swamp_run_id' => $run]]);
    app(TaskVmDestroyProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'running',
        'started_at' => '2026-10-31T00:00:00.000Z', 'result' => $started ? ['node_cleanup_started' => true] : []]);

    expect($guest->fresh()->status)->toBe($started ? LifecycleStatus::Removing : LifecycleStatus::Active)
        ->and($this->vm->fresh()->state->value)->toBe($started ? 'destroying' : 'failed')
        ->and($this->vm->fresh()->node_id)->toBe($guest->id)
        ->and($this->vm->fresh()->pi_token)->toBe('private-pi-token')
        ->and($this->vm->fresh()->provider_bootstrap['private'])->toBe('provider-key');
})->with([true, false]);

function destroyGuestFixture(): Node
{
    $guest = Node::query()->create(['name' => test()->vm->name, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'status' => LifecycleStatus::Active, 'wireguard_ip' => test()->vm->wireguard_ip, 'public_ssh_host' => '192.0.2.208']);
    test()->vm->update(['node_id' => $guest->id]);

    return $guest;
}

function destroyHubFixture(): Node
{
    $hub = Node::query()->create(['name' => 'destroy-hub', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1', 'ssh_host_fingerprint' => 'SHA256:hub']);
    $hub->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    config(['swamp_workers.nodes' => [$hub->id]]);

    return $hub;
}

function destroyRunningCheckpoint(Operation $operation, array $result): Operation
{
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $operation->update(['state' => [...($operation->state ?? []), 'swamp_run_id' => $run]]);

    return app(TaskVmDestroyProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'running',
        'started_at' => '2026-10-31T00:00:00.000Z', 'result' => $result]);
}

it('keeps shared hub custody separate from its independent local DNS effect', function (): void {
    $guest = destroyGuestFixture();
    $guest->update(['wireguard_public_key' => base64_encode(str_repeat('g', 32))]);
    $hub = destroyHubFixture();
    $operation = savedDestroyIntent();

    expect($operation->input['_wireguard'])->toMatchArray(['hub_id' => $hub->id, 'context_node_id' => $guest->id,
        'executor_node_id' => $this->gateway->id, 'excluded_node_id' => $guest->id]);
    expect($operation->input['_wireguard_restore'])->toBe([...$operation->input['_wireguard'], 'excluded_node_id' => null]);
    expect($operation->input['node_placements'])->toBe([['effect_node_id' => $hub->id, 'execution_node_id' => $hub->id, 'transport' => 'local']]);
    expect($operation->input['private_bindings']['configuration'])->toBe(['owner_node_id' => $hub->id,
        'read_node_ids' => [$this->gateway->id], 'write_node_ids' => []]);
    expect($operation->input['configuration'])->toBe(['$secret' => 'node.vpn_configuration', 'id' => $hub->id]);
    $view = app(TaskVmDestroyProjection::class)->publicInput($operation, $hub->id);
    expect($view['node_cleanup_participant']['dns'])->toBeArray();
    expect($view['node_cleanup_participant']['network'])->toBeNull();
    expect($view['node_cleanup_participant']['residue'])->toBeNull();
    expect($view)->not->toHaveKey('configuration')->not->toHaveKey('private_bindings');
    expect(app(TaskVmDestroyProjection::class)->publicInput($operation, $this->gateway->id)['_wireguard'])->toBe($operation->input['_wireguard']);
});

it('restores Node status before selecting the hub restore view without mutating input', function (): void {
    $guest = destroyGuestFixture();
    $guest->update(['wireguard_public_key' => base64_encode(str_repeat('g', 32))]);
    destroyHubFixture();
    $operation = savedDestroyIntent();
    $input = $operation->input;
    destroyRunningCheckpoint($operation, ['node_cleanup_started' => true]);
    expect($guest->fresh()->status)->toBe(LifecycleStatus::Removing);

    destroyRunningCheckpoint($operation->fresh(), ['node_cleanup_started' => true, 'node_cleanup_restoring' => true]);

    expect($guest->fresh()->status)->toBe(LifecycleStatus::Active);
    expect($operation->fresh()->input)->toBe($input);
    expect($operation->fresh()->state['node_wireguard_restoring'])->toBeTrue();
    expect(app(TaskVmDestroyProjection::class)->input($operation->fresh())['_wireguard'])->toBe($input['_wireguard_restore']);
    expect($this->vm->fresh()->state->value)->toBe('destroying');
    expect($this->vm->fresh()->pi_token)->toBe('private-pi-token');
});

it('rolls back restoration when its hub authority differs from cleanup', function (): void {
    $guest = destroyGuestFixture();
    $guest->update(['wireguard_public_key' => base64_encode(str_repeat('g', 32))]);
    destroyHubFixture();
    $operation = savedDestroyIntent();
    destroyRunningCheckpoint($operation, ['node_cleanup_started' => true]);
    $input = $operation->fresh()->input;
    $input['_wireguard_restore']['executor_node_id'] = $this->host->id;
    $operation->update(['input' => $input]);

    expect(fn () => destroyRunningCheckpoint($operation->fresh(), ['node_cleanup_restoring' => true]))->toThrow(ResourceOperationException::class);

    expect($guest->fresh()->status)->toBe(LifecycleStatus::Removing);
    expect($operation->fresh()->state)->not->toHaveKey('node_wireguard_restoring')->not->toHaveKey('node_cleanup_restoring');
    expect($operation->fresh()->result)->toBe(['node_cleanup_started' => true]);
});

it('omits unrelated TaskVMs but refuses a workerless residue actor before admission', function (bool $hasResidue): void {
    destroyGuestFixture();
    $other = Node::query()->create(['name' => 'unrelated-tvm', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '192.0.2.209', 'ssh_host_fingerprint' => 'SHA256:other']);
    TaskVm::query()->create(['name' => $other->name, 'provider' => 'incus', 'host_node_id' => $this->host->id,
        'node_id' => $other->id, 'wireguard_ip' => $other->wireguard_ip, 'state' => 'ready']);
    if ($hasResidue) {
        RouteRemovalResidue::query()->create(['node_id' => $other->id, 'route_id' => 987651, 'domain' => 'gone.orbit', 'steps' => ['caddy']]);
        expect(fn () => savedDestroyIntent())->toThrow(ResourceOperationException::class, 'Every required Node cleanup effect');
        expect(Operation::query()->count())->toBe(0);
    } else {
        expect(savedDestroyIntent()->input['node_removal']['participants'])->toBe([]);
    }
    expect($other->fresh()->status)->toBe(LifecycleStatus::Active);
})->with([false, true]);

it('restores local participant input when no hub was admitted', function (): void {
    $guest = destroyGuestFixture();
    $this->host->update(['ssh_host_fingerprint' => 'SHA256:destroy-host', 'wireguard_public_key' => base64_encode(str_repeat('h', 32)), 'dns_server_override' => '10.44.0.1']);
    $this->host->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    config(['swamp_workers.nodes' => [$this->host->id]]);
    $operation = savedDestroyIntent();
    destroyRunningCheckpoint($operation, ['node_cleanup_started' => true]);

    destroyRunningCheckpoint($operation->fresh(), ['node_cleanup_restoring' => true]);

    expect($guest->fresh()->status)->toBe(LifecycleStatus::Active);
    expect($operation->fresh()->state['node_cleanup_restoring'])->toBeTrue();
    expect(app(TaskVmDestroyProjection::class)->input($operation->fresh())['node_cleanup_participant']['network']['router_lan'])->toBeNull();
    expect($operation->fresh()->input['_wireguard'])->toBeNull();
});
