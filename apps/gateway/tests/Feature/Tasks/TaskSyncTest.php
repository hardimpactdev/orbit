<?php

declare(strict_types=1);

use App\Actions\Tasks\StartTaskSyncAction;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskSyncProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;
use Tests\Support\WorkflowStartIntents;

uses(LazilyRefreshDatabase::class);

function syncOperation(): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'tvm-1', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.137', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.137', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));

    return Operation::query()->create(['kind' => OperationKinds::TasksSync, 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $node->id, 'status' => OperationStatus::Running,
        'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'],
        'input' => ['group_id' => 1, 'workspace' => ['repository' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'branch' => 'task-1']]]);
}

it('records the two commits that the fetch stored', function (): void {
    $main = str_repeat('a', 40);
    $task = str_repeat('b', 40);

    $projected = app(TaskSyncProjection::class)->apply(syncOperation(), [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'succeeded', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Read the refs' => ['status' => 'succeeded']], 'summary' => ['status' => 'succeeded', 'failed_steps' => []], 'refs' => "{$main}\n{$task}\n"],
    ]);

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toBe(['default_branch' => 'main', 'default_commit' => $main, 'branch' => 'task-1', 'branch_commit' => $task]);
});

it('fails with the step that failed and leaves the commits out', function (): void {
    $projected = app(TaskSyncProjection::class)->apply(syncOperation(), [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'failed', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Clone the refs' => ['status' => 'failed']], 'summary' => ['status' => 'failed', 'failed_steps' => ['Clone the refs']]],
        'failure' => ['failed_step' => 'Clone the refs', 'detail' => 'exec failed', 'stderr_recorded' => true],
    ]);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe('tasks.sync_failed')
        ->and($projected->result)->toBe(['error_code' => 'tasks.sync_failed', 'failed_step' => 'Clone the refs']);
});

/**
 * A task copy of a Project whose default is a container: the default Instance with its runtime, and the group's copy cloned from it,
 * running, with its start commit. No task VM.
 *
 * @return array{Task, Instance, Instance}
 */
function syncCopyWorld(string $power = 'running', bool $source = true): array
{
    SwampNodeFixture::ensure();
    Queue::fake([StartAdmittedSwampRun::class]);
    app(TaskExtensionState::class)->enable();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'work', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.71', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.71', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $node->forceFill(['instance_bridge' => '10.253.0.0/24'])->saveQuietly();
    $project = Project::query()->create(['name' => 'Trial', 'slug' => 'trial', 'repository_url' => 'git@github.com:example/trial.git', 'default_branch' => 'main']);
    $default = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/var/lib/incus/rootfs/default/srv/workspace',
        'status' => InstanceState::Active, 'branch' => 'main']);
    InstanceRuntime::query()->create(['node_id' => $node->id, 'power_state' => 'running', 'runtime_version' => 'test', 'agent_version' => 'test', 'instance_id' => $default->id,
        'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$default->id, 'bridge_address' => '10.253.0.4', 'seed_commit' => str_repeat('a', 40)]);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running, 'orchestration' => 'external']);
    $copy = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'checkout_path' => '/var/lib/incus/rootfs/task/srv/workspace',
        'status' => InstanceState::Active, 'branch' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'starting_commit' => str_repeat('a', 40)]);
    InstanceRuntime::query()->create(['node_id' => $node->id, 'power_state' => $power, 'runtime_version' => 'test', 'agent_version' => 'test', 'instance_id' => $copy->id,
        'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$copy->id, 'bridge_address' => '10.253.0.5', 'clone_source_instance_id' => $source ? $default->id : null]);
    $group->update(['taskable_type' => $copy->getMorphClass(), 'taskable_id' => $copy->id]);

    return [$group->refresh(), $copy, $default];
}

it('admits a task copy sync with its copy intent and no task VM', function (): void {
    [$group, $copy, $default] = syncCopyWorld();

    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);

    $intent = ValidatedData::object(app(TaskSyncProjection::class)->input($operation)['intent']);
    $target = ValidatedData::object($intent['copy']);
    expect($operation->kind)->toBe(OperationKinds::TasksSync)
        ->and($operation->status)->toBe(OperationStatus::Queued)
        ->and($intent)->not->toHaveKey('checkout_path')
        ->and($intent['instance_id'])->toBe($copy->id)
        ->and($intent['node_id'])->toBe($copy->node_id)
        ->and($intent['workspace'])->toBe(['repository' => 'https://github.com/example/trial.git', 'default_branch' => 'main', 'branch' => 'task-'.$group->id])
        ->and(array_keys($target))->toBe(['runtime', 'branch', 'snapshot_id', 'start', 'mirror', 'source_mirror', 'published', 'approved'])
        ->and([$target['published'], $target['approved']])->toBe([[], ''])
        ->and($target['runtime'])->toBe(['project' => 'orbit-instances', 'name' => 'instance-'.$copy->id])
        ->and($target['branch'])->toBe('task-'.$group->id)
        ->and($target['start'])->toBe(str_repeat('a', 40))
        ->and($target['mirror'])->toBe('/var/lib/orbit/instance-repositories/'.$copy->id.'.git')
        ->and($target['source_mirror'])->toBe('/var/lib/orbit/instance-repositories/'.$default->id.'.git')
        ->and($intent['git_authorization']['vault'])->toBe('orbit-control-inputs')
        ->and($intent['git_authorization']['key'])->toMatch('/\\Atask-sync-token-[a-f0-9-]{36}\\z/')
        ->and(Operation::query()->where('kind', 'task_vm.resume')->count())->toBe(0);
});

it('refuses a task copy sync while a copy turn of the group may still run', function (): void {
    [$group] = syncCopyWorld();
    $turn = Operation::query()->create(['kind' => OperationKinds::TasksCopyTurn, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id,
        'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1, 'input' => [], 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'],
        'result' => ['ended' => false]]);

    expect(fn () => app(StartTaskSyncAction::class)->execute($group, 'test', null))
        ->toThrow(fn (ResourceOperationException $error) => expect([$error->errorCode, $error->details['operation_id'] ?? null])->toBe(['tasks.copy_turn_unended', $turn->id]))
        ->and(Operation::query()->where('kind', OperationKinds::TasksSync)->count())->toBe(0);
});

it('refuses a task copy sync when the copy does not run', function (): void {
    [$group] = syncCopyWorld('stopped');

    expect(fn () => app(StartTaskSyncAction::class)->execute($group, 'test', null))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tasks.instance_not_running'))
        ->and(Operation::query()->where('kind', OperationKinds::TasksSync)->count())->toBe(0);
});

it('mints the copy read credential as the run starts, and lists its key for cleanup', function (): void {
    [$group] = syncCopyWorld();
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    $key = ValidatedData::string($operation->input['git_authorization']['key']);
    $vault = new class implements WebSocketClient
    {
        /** @var list<array{key: string, value: string}> */
        public array $puts = [];

        /** @var list<array<string, mixed>> */
        private array $frames = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);
            $this->puts[] = ['key' => $frame['payload']['key'], 'value' => (string) $frame['payload']['value']];

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $vault);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);
    // A Project without GitHub App source access fetches anonymously: the header is empty, but the run still gets its own key.

    $inputs = app(DirectOperation::class)->workflowInputs($operation);

    $patterns = array_column(ValidatedData::object(app(DirectOperation::class)->definition(OperationKinds::TasksSync))['execution_copies'], 'key_pattern');
    expect($vault->puts)->toBe([['key' => $key, 'value' => '']])
        ->and($inputs['intent']['git_authorization'])->toBe(['vault' => 'orbit-control-inputs', 'key' => $key])
        ->and(array_filter($patterns, fn (string $pattern): bool => preg_match('/'.$pattern.'/', $key) === 1))->toHaveCount(1);
});

it('records the base of a task copy from its base phase', function (): void {
    $operation = syncOperation();
    $operation->update(['input' => [...$operation->input, 'copy' => ['branch' => 'task-1']]]);
    $main = str_repeat('c', 40);
    $branch = str_repeat('d', 40);
    $start = str_repeat('a', 40);

    $projected = app(TaskSyncProjection::class)->apply($operation->refresh(), [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'succeeded', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Bring in the base' => ['status' => 'succeeded']], 'summary' => ['status' => 'succeeded', 'failed_steps' => []],
            'base' => ['phase' => 'base', 'evidence' => ['default_branch' => 'main', 'default_commit' => $main, 'branch' => 'task-1', 'branch_commit' => $branch,
                'behind_by' => 3, 'start' => $start, 'bundled' => true, 'changed' => true]]],
    ]);

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toBe(['default_branch' => 'main', 'default_commit' => $main, 'branch' => 'task-1', 'branch_commit' => $branch,
            'behind_by' => 3, 'start' => $start]);
});

it('admits a copy whose default is gone and names no source mirror; the copy syncs from its own', function (): void {
    [$group] = syncCopyWorld(source: false);

    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);

    expect($operation->input['copy']['source_mirror'])->toBe('')
        ->and($operation->input['copy']['mirror'])->toBe('/var/lib/orbit/instance-repositories/'.$group->taskable_id.'.git');
});

it('refuses a copy sync while the copy is not ready, or after its completion failed', function (bool $failed, string $code): void {
    [$group, $copy] = syncCopyWorld();
    $copy->update(['status' => InstanceState::SourceResolved]);
    if ($failed) {
        Operation::query()->create(['kind' => 'instance.complete', 'target_type' => 'instance', 'target_id' => $copy->id, 'status' => OperationStatus::Failed,
            'sequence' => 1, 'attempts' => 1, 'input' => [], 'error_code' => 'instance.complete_failed', 'result' => ['failed_step' => 'Start the Processes']]);
    }

    expect(fn () => app(StartTaskSyncAction::class)->execute($group->refresh(), 'test', null))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe($code))
        ->and(Operation::query()->where('kind', OperationKinds::TasksSync)->count())->toBe(0);
})->with([
    'a create or completion that still runs' => [false, 'tasks.copy_not_ready'],
    'a failed completion' => [true, 'tasks.copy_completion_failed'],
]);

it('records no base field for a copy run whose base phase left no evidence', function (): void {
    $operation = syncOperation();
    $operation->update(['input' => [...$operation->input, 'copy' => ['branch' => 'task-1']]]);

    $projected = app(TaskSyncProjection::class)->apply($operation->refresh(), [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'succeeded', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => [], 'summary' => ['status' => 'succeeded', 'failed_steps' => []]],
    ]);

    expect($projected->status)->toBe(OperationStatus::Succeeded)->and($projected->result)->toBe([]);
});

/**
 * An external group whose workspace sat on a task VM, as the task VM sync admitted it: its Instance owns the workspace on the VM's Node.
 *
 * @return array{Task}
 */
function syncVmWorld(): array
{
    SwampNodeFixture::ensure();
    Queue::fake([StartAdmittedSwampRun::class]);
    app(TaskExtensionState::class)->enable();
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $guest = $node('tvm-598', '10.44.0.137');
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'orchestration' => 'external', 'status' => TaskGroupStatus::Running]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Active]);
    $group->update(['taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id]);

    return [$group->refresh()];
}

// The task VM sync is removed with the task VMs (docs/reference/old-paths.md): a group whose workspace sat on a task VM has no task copy.
it('starts operation-task-sync-copy for a task copy and refuses a group whose workspace is on a task VM by name', function (): void {
    [$copyGroup] = syncCopyWorld();
    $copy = app(StartTaskSyncAction::class)->execute($copyGroup, 'test', null);
    [$vmGroup] = syncVmWorld();

    expect(app(DirectOperation::class)->workflowFor($copy))->toBe('operation-task-sync-copy')
        ->and($copy->state['swamp_start']['workflow'])->toBe('operation-task-sync-copy')
        ->and($copy->input)->not->toHaveKey('checkout_path')
        ->and(fn () => app(StartTaskSyncAction::class)->execute($vmGroup, 'test', null))
        ->toThrow(fn (ResourceOperationException $refusal) => expect([$refusal->errorCode, $refusal->getMessage()])->toBe(['tasks.no_workspace', 'The group requires its task copy.']))
        ->and(Operation::query()->where('kind', 'tasks.sync')->where('target_id', $vmGroup->id)->exists())->toBeFalse();
});

it('keeps the workflow that admission recorded, so a copy sync an older Gateway admitted is still searched and settled', function (): void {
    [$group] = syncCopyWorld();
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    // As the Gateway before the copy workflow recorded it: one workflow for every sync, and a lost start confirmation.
    $operation->forceFill(['status' => OperationStatus::Running, 'started_at' => now(), 'error_code' => 'swamp.start_uncertain',
        'state' => [...$operation->state, 'swamp_start' => ['key' => 'operation:'.$operation->id, 'workflow' => 'operation-task-sync']]])->save();
    config(['swamp.read_token' => 'read-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem']);
    $socket = new class implements WebSocketClient
    {
        /** @var list<array<string, mixed>> */
        public array $sent = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->sent[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->sent);

            return [['type' => 'workflow.run.search', 'id' => is_array($frame) ? $frame['id'] : null, 'payload' => ['total' => 0, 'data' => ['results' => []]]]];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $socket);

    expect(app(DirectOperation::class)->workflowFor($operation))->toBe('operation-task-sync');
    // Before, the computed name differed from the recorded one and recovery returned before any search, holding the group lock.
    expect(fn () => app(SwampCommandStep::class)->recoverUnbound($operation))->toThrow(ResourceOperationException::class);

    expect($socket->sent)->toHaveCount(1)
        ->and($socket->sent[0]['type'])->toBe('workflow.run.search')
        ->and($socket->sent[0]['payload']['workflow'])->toBe('operation-task-sync')
        ->and($socket->sent[0]['payload']['tags'])->toBe(['orbit_start_key' => 'operation:'.$operation->id]);
});

it('keeps the Swamp start fixture equal to the inputs a copy sync starts with', function (): void {
    app()->instance(WebSocketClient::class, WorkflowStartIntents::vault());
    [$copyGroup] = syncCopyWorld();
    $copy = app(StartTaskSyncAction::class)->execute($copyGroup, 'test', null);
    WorkflowStartIntents::match('sync_copy', app(DirectOperation::class)->workflowInputs($copy));
});
