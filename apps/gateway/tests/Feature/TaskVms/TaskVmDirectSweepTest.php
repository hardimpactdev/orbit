<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmSweepOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmSweepProjection;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    $this->host = Node::query()->create(['name' => 'sweep-host', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit']);
    $this->host->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-pin']);
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    $this->gateway = Node::query()->create(['name' => 'sweep-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.14', 'public_ssh_host' => '192.0.2.14', 'user' => 'orbit']);
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $worker = Node::query()->create(['name' => 'sweep-control', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '192.0.2.209', 'user' => 'orbit']);
    $worker->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $worker->id, 'swamp.run_token' => 'fixture.run']);
    $this->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->host->id)]]);
    $this->run = '3fc3ad54-423a-4b4b-8187-99514098426b';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = function (array $message): array {
        if ($message['type'] === 'vault.put') {
            return [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        return [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-vm-sweep', 'runId' => $this->run]]];
    };
    app()->instance(WebSocketClient::class, $this->socket);
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
});

function directSweepAdmit(): Operation
{
    test()->run = (string) Str::uuid();

    return app(StartTaskVmSweepOperationAction::class)->execute(test()->host->id, 'incus');
}

function directSweepProject(Operation $operation, array $names = []): void
{
    app(TaskVmSweepProjection::class)->apply($operation, [
        'run_id' => $operation->fresh()->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => '2026-10-31T00:00:00.000Z',
        'finished_at' => '2026-10-31T00:00:01.000Z', 'result' => ['ready' => true, 'observed_names' => $names, 'deleted_names' => []],
    ]);
}

function directSweepVm(string $state, string $address): TaskVm
{
    $vm = TaskVm::query()->create(['name' => 'tvm-'.str_replace('.', '', $address), 'host_node_id' => test()->host->id,
        'provider' => 'incus', 'state' => $state, 'wireguard_ip' => $address]);
    $vm->update(['name' => 'tvm-'.$vm->id]);

    return $vm;
}

it('starts one native sweep after commit with immutable scope and no PHP execution', function (): void {
    DB::beginTransaction();
    $operation = directSweepAdmit();
    expect($this->socket->sent)->toBe([])->and($operation->state)->not->toHaveKey('task_vm_sweep_writer')
        ->and($operation->lock_key)->toBe(OperationStore::lockKey('task_vm_sweep', $this->host->id))->and($operation->worker)->toBeNull();
    DB::commit();
    expect($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-task-vm-sweep');
    expect(app(DirectOperation::class)->workflowInputs($operation)['intent']['scope'])->toMatchArray(['project' => 'orbit-tasks', 'pool' => 'orbit-tasks'])
        ->and($this->socket->sent[0]['payload']['inputs']['intent'])->toBe(app(DirectOperation::class)->workflowInputs($operation)['intent']);
    expect(directSweepAdmit()->id)->toBe($operation->id);
    foreach (['observe', 'delete', 'delete_upcloud', 'stop_upcloud'] as $verb) {
        expect(app(OperationKinds::class)->definition('task_vm.sweep_'.$verb))->toBeNull();
    }
});

it('requires two distinct native roots and never counts a replay as a second miss', function (): void {
    $ready = directSweepVm('ready', '10.44.0.129');
    $parked = directSweepVm('parked', '10.44.0.130');
    $provisioning = directSweepVm('provisioning', '10.44.0.131');
    $first = directSweepAdmit();
    directSweepProject($first);
    directSweepProject($first);
    expect($ready->fresh()->state)->toBe(TaskVmState::Ready)->and($parked->fresh()->state)->toBe(TaskVmState::Parked);
    directSweepProject(directSweepAdmit());
    expect($ready->fresh()->state)->toBe(TaskVmState::Failed)->and($parked->fresh()->state)->toBe(TaskVmState::Failed)
        ->and($ready->fresh()->error_code)->toBe('task_vm.vm_missing')->and($provisioning->fresh()->state)->toBe(TaskVmState::Provisioning);
});

it('clears missing evidence when an intervening sweep finds the VM', function (): void {
    $vm = directSweepVm('ready', '10.44.0.129');
    directSweepProject(directSweepAdmit());
    directSweepProject(directSweepAdmit(), [$vm->name]);
    directSweepProject(directSweepAdmit());
    expect($vm->fresh()->state)->toBe(TaskVmState::Ready);
});

it('freezes ownership before dispatch and excludes post-admission IDs', function (): void {
    $operation = directSweepAdmit();
    $vm = directSweepVm('provisioning', '10.44.0.129');
    expect(app(DirectOperation::class)->workflowInputs($operation)['intent']['scope']['live_names'])->toBe([])
        ->and(app(DirectOperation::class)->workflowInputs($operation)['intent']['scope']['max_task_vm_id'])->toBeLessThan($vm->id);
});

it('protects allocations committed between the ownership capture queries', function (): void {
    $allocated = null;
    DB::listen(function (QueryExecuted $query) use (&$allocated): void {
        if ($allocated === null && str_contains($query->sql, 'select "name" from "task_vms"')) {
            $allocated = false;
            $allocated = directSweepVm('provisioning', '10.44.0.132');
        }
    });
    $operation = directSweepAdmit();
    expect($allocated)->toBeInstanceOf(TaskVm::class);
    expect($operation->input['scope']['live_names'])->not->toContain($allocated->name)
        ->and($operation->input['scope']['max_task_vm_id'])->toBeLessThan($allocated->id);
});

it('retires only expired nonrunning build intent before native dispatch', function (): void {
    $running = TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id]);
    $recent = TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id, 'state' => 'failed']);
    $old = TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id, 'state' => 'failed', 'updated_at' => now()->subHours(25)]);
    TaskVmImageBuild::query()->whereKey($old->id)->update(['updated_at' => now()->subHours(25)]);
    $operation = directSweepAdmit();
    expect($operation->input['scope']['retired_build_ids'])->toBe([$old->id])->and($old->fresh()->cleanup_started_at)->not->toBeNull()
        ->and($running->fresh()->cleanup_started_at)->toBeNull()->and($recent->fresh()->cleanup_started_at)->toBeNull();
    expect(app(DirectOperation::class)->workflowInputs($operation)['intent']['scope']['protected_build_ids'])->toBe([$running->id, $recent->id]);
});

it('refuses late and malformed observations without changing VM projection', function (): void {
    $vm = directSweepVm('ready', '10.44.0.129');
    $operation = directSweepAdmit();
    $path = '/api/v1/direct-operations/'.$operation->id.'/result';
    expect(fn () => app(TaskVmSweepProjection::class)->apply($operation, ['run_id' => $this->run, 'status' => 'succeeded', 'result' => ['ready' => true]]))->toThrow(ResourceOperationException::class);
    directSweepProject($operation, [$vm->name]);
    $before = $vm->fresh()->getAttributes();
    $this->postJson($path, ['run_id' => $this->run, 'status' => 'running', 'started_at' => '2026-10-31T00:00:00.000Z', 'result' => []])->assertForbidden();
    expect($vm->fresh()->getAttributes())->toBe($before);
});

it('has no writer-mode branch or polled fallback', function (): void {
    config(['swamp.direct_writers' => ['task_vm.sweep_host' => 'polled']]);
    $operation = directSweepAdmit();
    expect($operation->state)->not->toHaveKey('task_vm_sweep_writer')->and($this->socket->sent)->toHaveCount(1);
});

it('writes UpCloud execution copies once and supplies only names in the full native envelope', function (): void {
    $cloud = new TaskVmUpCloud(false, [], '/fixture/upcloud', 1, 'fi-hel1', '1xCPU-2GB', 20, '00000000-0000-4000-8000-000000000001');
    $settings = TaskVmSettingsBuilder::bind(['upcloud' => $cloud]);
    app()->instance(UpCloudApi::class, new UpCloudApi($settings, 'ucat_fixture_private_sweep'));
    $operation = app(StartTaskVmSweepOperationAction::class)->execute($this->gateway->id, 'upcloud');
    $envelope = app(DirectOperation::class)->workflowInputs($operation);
    expect($envelope['execution_secrets'])->toBe(['upcloud' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-upcloud-token']])
        ->and(json_encode($operation->fresh()->getAttributes()))->not->toContain('ucat_fixture_private_sweep')
        ->and(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run']);
    app(StartTaskVmSweepOperationAction::class)->execute($this->gateway->id, 'upcloud');
    expect($this->socket->sent)->toHaveCount(2);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'/private-material?run_id='.$this->run)->assertForbidden();
});

it('never reprojects an old missing observation after a newer root found the VM', function (): void {
    $vm = directSweepVm('ready', '10.44.0.129');
    $old = directSweepAdmit();
    directSweepProject($old);
    directSweepProject(directSweepAdmit(), [$vm->name]);
    directSweepProject($old);
    directSweepProject(directSweepAdmit());
    expect($vm->fresh()->state)->toBe(TaskVmState::Ready);
    directSweepProject(directSweepAdmit());
    expect($vm->fresh()->state)->toBe(TaskVmState::Failed);
});

it('fails an unreadable native sweep without inventing a missing-VM observation', function (): void {
    $vm = directSweepVm('ready', '10.44.0.129');
    $operation = directSweepAdmit();
    app(TaskVmSweepProjection::class)->apply($operation, ['run_id' => $operation->fresh()->state['swamp_run_id'], 'status' => 'failed',
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => '2026-10-31T00:00:01.000Z',
        'result' => ['error_code' => 'operation.native_failed'], 'error_code' => 'operation.native_failed']);
    directSweepProject(directSweepAdmit());
    expect($vm->fresh()->state)->toBe(TaskVmState::Ready);
});

it('settles cancellation and interruption without inventing a missing observation or reopening the root', function (string $status): void {
    $vm = directSweepVm('ready', '10.44.0.129');
    $operation = directSweepAdmit();
    app(TaskVmSweepProjection::class)->apply($operation, ['run_id' => $operation->fresh()->state['swamp_run_id'], 'status' => $status,
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => '2026-10-31T00:00:01.000Z', 'result' => [],
        'error_code' => $status === 'interrupted' ? 'operation.native_failed' : null]);
    expect($operation->fresh()->status)->toBe($status === 'interrupted' ? OperationStatus::Failed : OperationStatus::Cancelled)
        ->and($operation->fresh()->lock_key)->toBeNull()->and($vm->fresh()->state)->toBe(TaskVmState::Ready);
})->with(['cancelled', 'interrupted']);
