<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmSweepOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    $this->host = Node::query()->create(['name' => 'sweep-host', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit']);
    $this->gateway = Node::query()->create(['name' => 'sweep-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.14', 'public_ssh_host' => '192.0.2.14', 'user' => 'orbit']);
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $worker = Node::query()->create(['name' => 'sweep-control', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '192.0.2.209', 'user' => 'orbit']);
    $worker->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $worker->id, 'swamp.run_token' => 'fixture.run']);
    $this->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->host->id)]]);
    $this->run = '3fc3ad54-423a-4b4b-8187-99514098426b';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-task-vm-sweep', 'runId' => $this->run,
    ]]];
    app()->instance(WebSocketClient::class, $this->socket);
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
});

function directSweepAdmit(): Operation
{
    return app(StartTaskVmSweepOperationAction::class)->execute(test()->host->id, 'incus');
}

function directSweepProject(Operation $operation, array $names = []): void
{
    test()->postJson('/api/v1/direct-operations/'.$operation->id.'/result', [
        'run_id' => test()->run, 'status' => 'succeeded', 'started_at' => '2026-10-31T00:00:00.000Z',
        'finished_at' => '2026-10-31T00:00:01.000Z', 'result' => ['ready' => true, 'observed_names' => $names, 'deleted_names' => []],
    ])->assertOk();
}

function directSweepVm(string $state, string $address): TaskVm
{
    return TaskVm::query()->create(['name' => 'tvm-'.str_replace('.', '', $address), 'host_node_id' => test()->host->id,
        'provider' => 'incus', 'state' => $state, 'wireguard_ip' => $address]);
}

it('starts one native sweep after commit with immutable scope and no PHP execution', function (): void {
    DB::beginTransaction();
    $operation = directSweepAdmit();
    expect($this->socket->sent)->toBe([])->and($operation->state['task_vm_sweep_writer'])->toBe('direct')
        ->and($operation->lock_key)->toBeNull()->and($operation->worker)->toBeNull();
    DB::commit();
    expect($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-task-vm-sweep');
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->run)->assertOk()
        ->assertJsonPath('data.input.host.project', 'orbit-tasks')->assertJsonPath('data.input.host.pool', 'orbit-tasks');
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

it('reads newly recorded live ownership without querying the provider', function (): void {
    $operation = directSweepAdmit();
    $vm = directSweepVm('provisioning', '10.44.0.129');
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.live_names', [$vm->name]);
});

it('retires only expired nonrunning build intent before native dispatch', function (): void {
    $running = TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id]);
    $recent = TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id, 'state' => 'failed']);
    $old = TaskVmImageBuild::query()->create(['host_node_id' => $this->host->id, 'state' => 'failed', 'updated_at' => now()->subHours(25)]);
    TaskVmImageBuild::query()->whereKey($old->id)->update(['updated_at' => now()->subHours(25)]);
    $operation = directSweepAdmit();
    expect($operation->input['retired_build_ids'])->toBe([$old->id])->and($old->fresh()->cleanup_started_at)->not->toBeNull()
        ->and($running->fresh()->cleanup_started_at)->toBeNull()->and($recent->fresh()->cleanup_started_at)->toBeNull();
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->run)->assertOk()
        ->assertJsonPath('data.input.protected_build_ids', [$running->id, $recent->id]);
});

it('refuses late and malformed observations without changing VM projection', function (): void {
    $vm = directSweepVm('ready', '10.44.0.129');
    $operation = directSweepAdmit();
    $path = '/api/v1/direct-operations/'.$operation->id.'/result';
    $this->postJson($path, ['run_id' => $this->run, 'status' => 'succeeded', 'result' => ['ready' => true]])->assertUnprocessable();
    directSweepProject($operation, [$vm->name]);
    $before = $vm->fresh()->getAttributes();
    $this->postJson($path, ['run_id' => $this->run, 'status' => 'running', 'started_at' => '2026-10-31T00:00:00.000Z', 'result' => []])->assertConflict();
    expect($vm->fresh()->getAttributes())->toBe($before);
});

it('refuses explicit polled fallback before recording or retiring intent', function (): void {
    config(['swamp.direct_writers' => ['task_vm.sweep_host' => 'polled']]);
    expect(fn () => directSweepAdmit())->toThrow(ResourceOperationException::class, 'This kind requires its direct Swamp writer.');
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});


it('hydrates UpCloud material only for its bound exact Gateway sweep and omits the reference from safe input', function (): void {
    $cloud = new App\Domain\TaskVms\TaskVmUpCloud(false, [], '/fixture/upcloud', 1, 'fi-hel1', '1xCPU-2GB', 20, '00000000-0000-4000-8000-000000000001');
    $settings = TaskVmSettingsBuilder::bind(['upcloud' => $cloud]);
    $api = new App\Infrastructure\TaskVms\UpCloudApi($settings, 'ucat_fixture_private_sweep');
    app()->instance(App\Infrastructure\TaskVms\UpCloudApi::class, $api);
    $operation = app(StartTaskVmSweepOperationAction::class)->execute($this->gateway->id, 'upcloud');
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->run)->assertOk()->assertJsonMissingPath('data.input.upcloud_token');
    $this->getJson($path.'/private-material?run_id='.$this->run)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->getJson($path.'/private-material?run_id='.$this->run)->assertOk()->assertJsonPath('data.upcloud_token', 'ucat_fixture_private_sweep');
    $this->getJson($path.'/private-material?run_id=f5518d34-4395-4454-ab37-7f3cdcae1ad4')->assertConflict();
    $this->postJson($path.'/result', ['run_id' => $this->run, 'status' => 'cancelled', 'started_at' => '2026-10-31T00:00:00.000Z',
        'finished_at' => '2026-10-31T00:00:01.000Z', 'result' => []])->assertOk();
    $this->getJson($path.'/private-material?run_id='.$this->run)->assertConflict();
    expect(json_encode($operation->fresh()->getAttributes()))->not->toContain('ucat_fixture_private_sweep');
});
