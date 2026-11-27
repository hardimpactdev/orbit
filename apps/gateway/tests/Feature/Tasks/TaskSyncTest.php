<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Tasks\StartTaskSyncAction;
use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineKinds;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\MachineBus;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskSyncEngineFlow;
use App\Domain\Tasks\TaskSyncProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Models\Project;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

it('fails with the step that failed and leaves the commits out', function (): void {
    $projected = app(TaskSyncProjection::class)->apply(syncOperation(), [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'failed', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Bring in the base' => ['status' => 'failed']], 'summary' => ['status' => 'failed', 'failed_steps' => ['Bring in the base']]],
        'failure' => ['failed_step' => 'Bring in the base', 'detail' => 'exec failed', 'stderr_recorded' => true],
    ]);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe('tasks.sync_failed')
        ->and($projected->result)->toBe(['error_code' => 'tasks.sync_failed', 'failed_step' => 'Bring in the base']);
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

/**
 * The engine's machine bus for a sync: the Node reports `$actions`, content goes to its keys, and every order is kept decoded,
 * unless its publication claim refuses.
 */
function syncEngineBus(array $actions, array $features = ['instance.fetch_seed']): MachineBus
{
    $pair = sodium_crypto_sign_keypair();
    config(['orbit.engine.kinds' => ['tasks.sync'], 'orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey($pair))]);
    $bus = new class($actions, $features) implements MachineBus
    {
        /** @var list<array<string, mixed>> */
        public array $orders = [];

        /** @var array<string, string> */
        public array $content = [];

        public function __construct(private readonly array $actions, private readonly array $features) {}

        public function publishOrder(int $node, SignedExecutorOrder $order, ?Closure $claim = null): void
        {
            if ($claim === null || $claim()) {
                $this->orders[] = json_decode((string) base64_decode(explode('.', $order->wire)[0], true), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        public function publishStart(int $operation): void {}

        public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void {}

        public function nudge(int $operation, string $id): void {}

        public function putContent(int $node, string $content): array
        {
            $this->content[$sha = hash('sha256', $content)] = $content;

            return ['sha256' => $sha];
        }

        public function nodeActions(int $node): array
        {
            return $this->actions;
        }

        public function nodeTargetActions(int $node): array
        {
            return [];
        }

        /** After this many reads, the Node's features can no longer be read. */
        public int $featureReads = PHP_INT_MAX;

        public function nodeFeatures(int $node): array
        {
            return $this->featureReads-- <= 0 ? throw new RuntimeException('$KV.NODES unreadable') : $this->features;
        }

        public function nodeClock(int $node): ?bool
        {
            return true;
        }
    };
    app()->instance(MachineBus::class, $bus);

    return $bus;
}

const SyncEngineActions = ['instance.fetch_branch', 'instance.sync_base', 'instance.clear_base'];

/** @return array{Operation, Task, Instance, object} */
function syncOnEngine(): array
{
    [$group, $copy] = syncCopyWorld();
    $bus = syncEngineBus(SyncEngineActions);
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);

    return [$operation->refresh(), $group, $copy, $bus];
}

/** @param array<string, mixed> $order */
function syncReceipt(int $node, array $order, array $fields): void
{
    app(Engine::class)->onReceipt(['node' => $node, 'line' => [
        'action' => $order['action'], 'target' => $order['target'], 'operation' => $order['operation'], ...$fields]]);
}

it('runs a task copy sync wholly on the engine: fetch on the Node, import into the copy, scratch cleared, Swamp result', function (): void {
    [$operation, $group, $copy, $bus] = syncOnEngine();
    $head = str_repeat('c', 40);

    expect(Engine::owns($operation))->toBeTrue()
        ->and($operation->lock_key)->toBe(OperationStore::lockKey('instance', $copy->id))
        ->and(OperationEngineStep::query()->where('operation_id', $operation->id)->orderBy('id')->pluck('action', 'name')->all())
        ->toBe(['fetch' => 'instance.fetch_branch', 'sync' => 'instance.sync_base', 'clear' => 'instance.clear_base', 'clear_after_failure' => 'instance.clear_base'])
        ->and($bus->orders)->toHaveCount(1);
    $fetch = $bus->orders[0];
    $credential = $fetch['input']['credential']['blob']['sha256'];
    // The token reaches the Node's fetch only, as content behind a digest; no copy input names it.
    expect($fetch)->toMatchArray(['action' => 'instance.fetch_branch', 'target' => $copy->node_id.':fetch-'.$copy->id, 'operation' => $operation->id.':fetch'])
        ->and($fetch['input'])->toBe(['mirror' => '/var/lib/orbit/instance-repositories/'.$copy->id.'.git', 'repository' => 'https://github.com/example/trial.git',
            'branch' => 'main', 'protocol' => 'https', 'credential' => ['blob' => ['sha256' => $credential]],
            'seed' => '/var/lib/orbit/instance-repositories/'.$copy->runtime->clone_source_instance_id.'.git'])
        ->and($bus->content)->toHaveKey($credential);

    syncReceipt($copy->node_id, $fetch, ['status' => 'output', 'stdout' => json_encode(['commit' => $head])]);
    expect($bus->orders)->toHaveCount(2);
    $sync = $bus->orders[1];
    expect($sync)->toMatchArray(['action' => 'instance.sync_base', 'target' => (string) $copy->id, 'operation' => $operation->id.':sync'])
        ->and($sync['input'])->toBe(['project' => 'orbit-instances', 'name' => 'instance-'.$copy->id, 'instance_id' => $copy->id,
            'mirror' => '/var/lib/orbit/instance-repositories/'.$copy->id.'.git', 'default_branch' => 'main', 'branch' => 'task-'.$group->id,
            'head' => $head, 'start' => str_repeat('a', 40), 'published' => ['blob' => ['sha256' => hash('sha256', '[]')]], 'approved' => ''])
        ->and($bus->content[hash('sha256', '[]')])->toBe('[]')
        ->and(json_encode($sync['input']))->not->toContain('credential');

    $work = ['state' => 'clean', 'head' => str_repeat('d', 40), 'default_commit' => $head, 'empty' => true, 'paths' => ['count' => 0, 'names' => []],
        'uncommitted' => 0, 'untracked' => 0, 'stashes' => 0, 'approved_held' => null];
    $evidence = ['default_branch' => 'main', 'default_commit' => $head, 'branch' => 'task-'.$group->id, 'branch_commit' => str_repeat('d', 40),
        'behind_by' => 2, 'start' => str_repeat('a', 40), 'bundled' => true, 'changed' => true, 'work' => $work];
    syncReceipt($copy->node_id, $sync, ['status' => 'output', 'stdout' => json_encode($evidence)]);
    $clear = $bus->orders[2];
    expect($clear)->toMatchArray(['action' => 'instance.clear_base', 'operation' => $operation->id.':clear'])
        ->and($clear['input'])->toBe(['project' => 'orbit-instances', 'name' => 'instance-'.$copy->id, 'instance_id' => $copy->id,
            'mirror' => '/var/lib/orbit/instance-repositories/'.$copy->id.'.git'])
        ->and($operation->refresh()->status)->toBe(OperationStatus::Running);

    syncReceipt($copy->node_id, $clear, ['status' => 'output', 'stdout' => '{"cleaned":true}']);
    $operation->refresh();
    // The same rows as a Swamp run's projection of its base evidence.
    expect($operation->status)->toBe(OperationStatus::Succeeded)->and($operation->lock_key)->toBeNull()
        ->and($operation->result)->toBe(['default_branch' => 'main', 'default_commit' => $head, 'branch' => 'task-'.$group->id,
            'branch_commit' => str_repeat('d', 40), 'behind_by' => 2, 'start' => str_repeat('a', 40), 'work' => $work])
        ->and(OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', 'clear_after_failure')->value('status'))->toBe('pending')
        ->and(json_encode($operation->getAttributes()))->not->toContain('x-access-token');
});

it('keeps the group fenced across a lost import receipt: the resend carries the same step and head, and the stored result ends it once', function (): void {
    [$operation, $group, $copy, $bus] = syncOnEngine();
    $head = str_repeat('c', 40);
    syncReceipt($copy->node_id, $bus->orders[0], ['status' => 'output', 'stdout' => json_encode(['commit' => $head])]);
    $first = $bus->orders[1];

    // No receipt: the budget passes, no expiry ends the Operation, and the deadline sends the same step again.
    $this->travel(400)->seconds();
    app(ExpireOperationAction::class)->execute($operation->refresh());
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->lock_key)->toBe(OperationStore::lockKey('instance', $copy->id))
        ->and(fn () => app(StartTaskSyncAction::class)->execute($group, 'test', null))
        ->toThrow(fn (ResourceOperationException $refusal) => expect($refusal->errorCode)->toBe('operation.target_busy'))
        ->and($bus->orders)->toHaveCount(3);
    $resend = $bus->orders[2];
    expect($resend['operation'])->toBe($first['operation'])->and($resend['nonce'])->not->toBe($first['nonce'])->and($resend['input'])->toBe($first['input']);

    // The journal repeats the first execution's stored result for the second send; a late duplicate changes nothing.
    $stored = ['status' => 'output', 'stdout' => json_encode(['default_branch' => 'main', 'default_commit' => $head, 'branch' => 'task-'.$group->id,
        'branch_commit' => str_repeat('d', 40), 'behind_by' => 0, 'start' => str_repeat('a', 40), 'bundled' => false, 'changed' => false, 'work' => ['state' => 'clean', 'head' => str_repeat('d', 40), 'default_commit' => $head, 'empty' => true,
            'paths' => ['count' => 0, 'names' => []], 'uncommitted' => 0, 'untracked' => 0, 'stashes' => 0, 'approved_held' => null]])];
    syncReceipt($copy->node_id, $resend, ['status' => 'refused', 'reason' => 'replayed_nonce', 'first' => [
        'action' => $first['action'], 'target' => $first['target'], 'operation' => $first['operation'], ...$stored, 'repeat' => true]]);
    syncReceipt($copy->node_id, $first, $stored);
    $step = OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', 'sync')->firstOrFail();
    expect($step->status)->toBe('done')->and($step->late)->toBe(1);
});

it('fails as a missing default branch when the origin lacks it, and still clears the scratch', function (): void {
    [$operation, , $copy, $bus] = syncOnEngine();
    syncReceipt($copy->node_id, $bus->orders[0], ['status' => 'output', 'stdout' => '{"commit":null}']);
    $sync = $bus->orders[1];
    expect($sync['input']['head'])->toBeNull();

    syncReceipt($copy->node_id, $sync, ['status' => 'failed', 'code' => 'tasks.default_branch_missing']);
    $rescue = $bus->orders[2];
    expect($rescue)->toMatchArray(['action' => 'instance.clear_base', 'operation' => $operation->id.':clear_after_failure'])
        ->and($operation->refresh()->status)->toBe(OperationStatus::Running);

    syncReceipt($copy->node_id, $rescue, ['status' => 'output', 'stdout' => '{"cleaned":true}']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('tasks.default_branch_missing')
        ->and($operation->result)->toBe(['error_code' => 'tasks.default_branch_missing', 'failed_step' => 'Bring in the base'])
        ->and($operation->lock_key)->toBeNull();
});

it('names the Swamp step of a failed fetch with the Swamp code', function (): void {
    [$operation, , $copy, $bus] = syncOnEngine();
    syncReceipt($copy->node_id, $bus->orders[0], ['status' => 'failed', 'code' => 'instance.branch_fetch_failed']);
    syncReceipt($copy->node_id, $bus->orders[1], ['status' => 'output', 'stdout' => '{"cleaned":true}']);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->result)->toBe(['error_code' => 'tasks.sync_failed', 'failed_step' => 'Bring in the base']);
});

it('starts the whole Swamp sync when the kind is off, the Node lacks an action or the intent is outside the flow', function (string $case): void {
    [$group] = syncCopyWorld();
    $bus = syncEngineBus($case === 'no clear action' ? ['instance.fetch_branch', 'instance.sync_base'] : SyncEngineActions);
    if ($case === 'kind off') {
        config(['orbit.engine.kinds' => []]);
    }
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    if ($case === 'no copy') {
        $operation->update(['input' => Arr::except($operation->input, ['copy'])]);
    }

    expect(app(EngineKinds::class)->routes($operation->refresh()))->toBeFalse()
        ->and(fn () => app(TaskSyncEngineFlow::class)->steps(syncOperation()))->toThrow(EngineUnsupported::class)
        ->and($bus->orders)->toBe([]);
})->with(['kind off', 'no clear action', 'no copy']);

it('fences the copy itself: a removal of the copy is refused until the sync has its terminal receipts', function (): void {
    [$operation, , $copy, $bus] = syncOnEngine();

    expect(fn () => app(StartInstanceDestroyAction::class)->execute($copy->refresh(), true))
        ->toThrow(fn (ResourceOperationException $refusal) => expect($refusal->errorCode)->toBe('operation.target_busy'));

    syncReceipt($copy->node_id, $bus->orders[0], ['status' => 'failed', 'code' => 'instance.branch_fetch_failed']);
    syncReceipt($copy->node_id, $bus->orders[1], ['status' => 'output', 'stdout' => '{"cleaned":true}']);
    expect($operation->refresh()->lock_key)->toBeNull()
        ->and(Operation::query()->where('kind', TaskSyncEngineFlow::Kind)->where('lock_key', OperationStore::lockKey('instance', $copy->id))->exists())->toBeFalse();
});

it('ends a cancel after the fetch was published once its receipt came: no new step, the scratch cleared, the Swamp cancel result', function (): void {
    [$operation, , $copy, $bus] = syncOnEngine();
    app(CancelOperationAction::class)->execute($operation->refresh());

    syncReceipt($copy->node_id, $bus->orders[0], ['status' => 'output', 'stdout' => json_encode(['commit' => str_repeat('c', 40)])]);
    expect(array_column($bus->orders, 'operation'))->toBe([$operation->id.':fetch', $operation->id.':clear_after_failure'])
        ->and($operation->refresh()->status)->toBe(OperationStatus::Running);

    syncReceipt($copy->node_id, $bus->orders[1], ['status' => 'output', 'stdout' => '{"cleaned":true}']);
    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Cancelled)->and($operation->error_code)->toBeNull()
        ->and($operation->result ?? [])->toBe([])->and($operation->lock_key)->toBeNull();
});

it('ends a cancel before any order was published at once, with no field and no error', function (): void {
    [$group] = syncCopyWorld();
    $bus = syncEngineBus(SyncEngineActions);
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    app(StartSwampRun::class)->start($operation);
    app(CancelOperationAction::class)->execute($operation->refresh());
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);

    $operation->refresh();
    expect($bus->orders)->toBe([])->and($operation->status)->toBe(OperationStatus::Cancelled)->and($operation->error_code)->toBeNull()
        ->and($operation->result ?? [])->toBe([])->and($operation->lock_key)->toBeNull();
});

it('accepts only each step\'s own evidence: a bare done, a malformed head and an unread blob do not settle a step as success', function (): void {
    [$operation, , $copy, $bus] = syncOnEngine();
    $fetch = $bus->orders[0];
    // Content the executor could not read never ran this order: the step waits for its terminal result.
    syncReceipt($copy->node_id, $fetch, ['status' => 'failed', 'code' => 'blob.missing']);
    expect(OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', 'fetch')->value('status'))->toBe('sent');

    syncReceipt($copy->node_id, $fetch, ['status' => 'output', 'stdout' => '{"commit":"not-a-commit"}']);
    syncReceipt($copy->node_id, $bus->orders[1], ['status' => 'output', 'stdout' => '{"cleaned":true}']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)->and($operation->error_code)->toBe('tasks.sync_failed')
        ->and(OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', 'fetch')->value('error_code'))->toBe('engine.receipt_invalid');
});

it('fails an import that answers a bare done instead of its base evidence', function (): void {
    [$second, , $copy, $bus] = syncOnEngine();
    syncReceipt($copy->node_id, $bus->orders[0], ['status' => 'output', 'stdout' => json_encode(['commit' => str_repeat('c', 40)])]);
    syncReceipt($copy->node_id, $bus->orders[1], ['status' => 'done']);
    expect(OperationEngineStep::query()->where('operation_id', $second->id)->where('name', 'sync')->value('error_code'))->toBe('engine.receipt_invalid')
        ->and($second->refresh()->result)->not->toHaveKey('default_commit');
});

it('keeps a long publish history out of the 4 KB order, and fails a step whose order cannot be signed instead of waiting', function (): void {
    [$group, $copy] = syncCopyWorld();
    $bus = syncEngineBus(SyncEngineActions);
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    $published = array_map(static fn (int $i): string => hash('sha1', (string) $i), range(1, 70));
    $operation->update(['input' => [...$operation->input, 'copy' => [...$operation->input['copy'], 'published' => $published]]]);
    app(StartSwampRun::class)->start($operation->refresh());
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    syncReceipt($copy->node_id, $bus->orders[0], ['status' => 'output', 'stdout' => json_encode(['commit' => str_repeat('c', 40)])]);
    $sha = $bus->orders[1]['input']['published']['blob']['sha256'];
    expect(json_decode($bus->content[$sha], true))->toBe($published);
});

it('fails a step whose order cannot be signed instead of waiting for a receipt that cannot come', function (): void {
    [$group] = syncCopyWorld();
    $bus = syncEngineBus(SyncEngineActions);
    $unsigned = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    app(StartSwampRun::class)->start($unsigned);
    // A target outside the signer's form: every send of this order is refused alike.
    OperationEngineStep::query()->where('operation_id', $unsigned->id)->where('name', 'fetch')->update(['target' => str_repeat('x', 129)]);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $unsigned->id]);
    expect(array_column($bus->orders, 'operation'))->toBe([$unsigned->id.':clear_after_failure']);
    syncReceipt($group->taskable->node_id, $bus->orders[0], ['status' => 'output', 'stdout' => '{"cleaned":true}']);
    expect($unsigned->refresh()->status)->toBe(OperationStatus::Failed)->and($unsigned->lock_key)->toBeNull()
        ->and(OperationEngineStep::query()->where('operation_id', $unsigned->id)->where('name', 'fetch')->value('error_code'))->toBe('engine.order_invalid');
});

it('runs on Swamp a plan the flow does not cover', function (Closure $change): void {
    [$group, $copy] = syncCopyWorld();
    syncEngineBus(SyncEngineActions);
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    $change($operation, $copy);

    expect(app(EngineKinds::class)->routes($operation->refresh()))->toBeFalse();
})->with([
    'copy branch differs' => [fn (Operation $o) => $o->update(['input' => [...$o->input, 'copy' => [...$o->input['copy'], 'branch' => 'task-999']]])],
    'empty runtime' => [fn (Operation $o) => $o->update(['input' => [...$o->input, 'copy' => [...$o->input['copy'], 'runtime' => []]]])],
    'another container' => [fn (Operation $o) => $o->update(['input' => [...$o->input, 'copy' => [...$o->input['copy'], 'runtime' => ['project' => 'orbit-instances', 'name' => 'instance-1']]]])],
    'lock file branch' => [fn (Operation $o) => $o->update(['input' => [...$o->input, 'workspace' => [...$o->input['workspace'], 'default_branch' => 'release.LOCK']]])],
    'published not a commit' => [fn (Operation $o) => $o->update(['input' => [...$o->input, 'copy' => [...$o->input['copy'], 'published' => ['HEAD']]]])],
    'GitHub CLI access' => [fn (Operation $o, Instance $copy) => $copy->project->update(['source_access' => ProjectSourceAccess::GhCli])],
    'no seed feature' => [fn () => syncEngineBus(SyncEngineActions, [])],
    'a project name the executor refuses' => [fn (Operation $o) => $o->update(['input' => [...$o->input, 'copy' => [...$o->input['copy'], 'runtime' => ['project' => 'Team_A', 'name' => $o->input['copy']['runtime']['name']]]]])],
    'a project the executor does not resolve' => [fn (Operation $o) => $o->update(['input' => [...$o->input, 'copy' => [...$o->input['copy'], 'runtime' => ['project' => 'team-a', 'name' => $o->input['copy']['runtime']['name']]]]])],
    'a container that is not instance-<id>' => [fn (Operation $o) => $o->update(['input' => [...$o->input, 'copy' => [...$o->input['copy'], 'runtime' => ['project' => 'orbit-instances', 'name' => 'guest-2']]]])],
    'an unrelated seed mirror' => [fn (Operation $o) => $o->update(['input' => [...$o->input, 'copy' => [...$o->input['copy'], 'source_mirror' => '/var/lib/orbit/instance-repositories/999.git']]])],
    'an unrelated repository' => [fn (Operation $o) => $o->update(['input' => [...$o->input, 'workspace' => [...$o->input['workspace'], 'repository' => 'https://github.com/other/repo.git']]])],
]);

it('admits a runtime whose members come in another order', function (): void {
    [$group] = syncCopyWorld();
    syncEngineBus(SyncEngineActions);
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    $runtime = $operation->input['copy']['runtime'];
    $operation->update(['input' => [...$operation->input, 'copy' => [...$operation->input['copy'], 'runtime' => ['name' => $runtime['name'], 'project' => $runtime['project']]]]]);

    expect(app(EngineKinds::class)->routes($operation->refresh()))->toBeTrue();
});

it('admits a Swamp sync exactly as before: it takes no Instance lock, beside another writer of the copy in either order', function (): void {
    [$group, $copy] = syncCopyWorld();
    $writer = static fn (): Operation => Operation::query()->create(['kind' => 'instance.mail_update', 'target_type' => 'instance', 'target_id' => $copy->id,
        'node_id' => $copy->node_id, 'status' => OperationStatus::Running, 'lock_key' => OperationStore::lockKey('instance', $copy->id), 'input' => [], 'state' => []]);
    $first = $writer();

    $sync = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    expect($sync->lock_key)->toBeNull();

    $first->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    expect($writer()->exists)->toBeTrue('a writer admitted after the sync');
});

it('refuses a sync whose copy\'s removal was admitted first, before it starts on either engine', function (): void {
    [$group, $copy] = syncCopyWorld();
    $bus = syncEngineBus(SyncEngineActions);
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    // The removal's own records are not the subject; the refusal reads only the status.
    DB::unprepared('DROP TRIGGER instances_removal_status_update');
    $copy->update(['status' => InstanceState::Removing]);

    expect(fn () => app(StartSwampRun::class)->start($operation->refresh()))
        ->toThrow(fn (ResourceOperationException $refusal) => expect($refusal->errorCode)->toBe('operation.target_busy'));
    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Failed)->and($operation->error_code)->toBe('operation.target_busy')
        ->and(Engine::owns($operation))->toBeFalse()->and($bus->orders)->toBe([])
        ->and($operation->state['swamp_run_id'] ?? null)->toBeNull('no Swamp run was started either');
});

it('mints the credential again and uploads it with the first order when the Gateway cache lost it before any publication', function (): void {
    [$group] = syncCopyWorld();
    $bus = syncEngineBus(SyncEngineActions);
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    app(StartSwampRun::class)->start($operation);
    Cache::forget('tasks.sync.credential.'.$operation->id);

    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);

    $sha = $bus->orders[0]['input']['credential']['blob']['sha256'];
    expect($bus->content)->toHaveKey($sha)
        ->and($operation->refresh()->state['engine_credential']['sha256'])->toBe($sha);
});

it('rebuilds a resend from the credential reference the Operation kept when the Gateway cache lost the credential', function (): void {
    [$operation, , , $bus] = syncOnEngine();
    $first = $bus->orders[0]['input']['credential'];
    Cache::forget('tasks.sync.credential.'.$operation->id);

    $this->travel(70)->seconds();
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);

    expect($bus->orders)->toHaveCount(2)->and($bus->orders[1]['input']['credential'])->toBe($first)
        ->and($operation->refresh()->state['engine_credential']['sha256'])->toBe($first['blob']['sha256']);
});

it('settles the import only by evidence of this sync: the pinned head, the admitted branch and start, and the whole work read', function (Closure $wrong): void {
    [$operation, $group, $copy, $bus] = syncOnEngine();
    $head = str_repeat('c', 40);
    syncReceipt($copy->node_id, $bus->orders[0], ['status' => 'output', 'stdout' => json_encode(['commit' => $head])]);
    $work = ['state' => 'clean', 'head' => str_repeat('d', 40), 'default_commit' => $head, 'empty' => true, 'paths' => ['count' => 0, 'names' => []],
        'uncommitted' => 0, 'untracked' => 0, 'stashes' => 0, 'approved_held' => null];
    $evidence = $wrong(['default_branch' => 'main', 'default_commit' => $head, 'branch' => 'task-'.$group->id, 'branch_commit' => str_repeat('d', 40),
        'behind_by' => 2, 'start' => str_repeat('a', 40), 'bundled' => true, 'changed' => true, 'work' => $work]);

    syncReceipt($copy->node_id, $bus->orders[1], ['status' => 'output', 'stdout' => json_encode($evidence)]);

    expect(OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', 'sync')->value('error_code'))->toBe('engine.receipt_invalid')
        ->and($operation->refresh()->status)->not->toBe(OperationStatus::Succeeded);
})->with([
    'another head' => [fn (array $e) => [...$e, 'default_commit' => str_repeat('e', 40), 'work' => [...$e['work'], 'default_commit' => str_repeat('e', 40)]]],
    'another branch' => [fn (array $e) => [...$e, 'branch' => 'task-999']],
    'another start' => [fn (array $e) => [...$e, 'start' => str_repeat('b', 40)]],
    'only a work state' => [fn (array $e) => [...$e, 'work' => ['state' => 'clean']]],
]);

it('keeps the admitted credential for every resend of the Operation, past the token\'s hour', function (): void {
    [$operation, , , $bus] = syncOnEngine();
    $first = $bus->orders[0]['input']['credential'];

    $this->travel(61)->minutes();
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);

    expect($bus->orders)->toHaveCount(2)->and($bus->orders[1]['input']['credential'])->toBe($first);
});

it('builds resends, the import and cleanup from the pinned intent after the Project changed its access', function (): void {
    [$operation, , $copy, $bus] = syncOnEngine();
    $copy->project->update(['source_access' => ProjectSourceAccess::GhCli]);

    $this->travel(70)->seconds();
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);
    expect($bus->orders)->toHaveCount(2)->and($bus->orders[1]['operation'])->toBe($operation->id.':fetch');

    syncReceipt($copy->node_id, $bus->orders[1], ['status' => 'failed', 'code' => 'instance.branch_fetch_failed']);
    expect(array_column($bus->orders, 'operation'))->toBe([$operation->id.':fetch', $operation->id.':fetch', $operation->id.':clear_after_failure']);
    syncReceipt($copy->node_id, $bus->orders[2], ['status' => 'output', 'stdout' => '{"cleaned":true}']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)->and($operation->lock_key)->toBeNull();
});

it('starts the run routing admitted even when the Node\'s features can no longer be read', function (): void {
    [$group, $copy] = syncCopyWorld();
    $bus = syncEngineBus(SyncEngineActions);
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    // Routing reads them once; binding and every later step build from the pinned intent alone.
    $bus->featureReads = 1;
    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);

    expect(Engine::owns($operation->refresh()))->toBeTrue()->and(array_column($bus->orders, 'operation'))->toBe([$operation->id.':fetch']);
});

it('mints the read credential once per Operation and sends the same one again', function (): void {
    [$operation, , , $bus] = syncOnEngine();
    $first = $bus->orders[0]['input']['credential'];

    $this->travel(70)->seconds();
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);

    expect($bus->orders)->toHaveCount(2)->and($bus->orders[1]['input']['credential'])->toBe($first)
        ->and(Cache::has('tasks.sync.credential.'.$operation->id))->toBeTrue();
});

it('starts the unchanged Swamp workflow for a plan the engine does not cover, through the start itself', function (Closure $change): void {
    [$group] = syncCopyWorld();
    $bus = syncEngineBus(SyncEngineActions);
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    $change($operation);
    $socket = syncSwampSocket();

    try {
        app(StartSwampRun::class)->start($operation->refresh());
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->not->toBe('operation.target_busy');
    }

    $runs = array_values(array_filter($socket->sent, static fn (array $frame): bool => $frame['type'] === 'workflow.run'));
    expect($runs)->toHaveCount(1)->and($runs[0]['payload']['workflowIdOrName'])->toBe('operation-task-sync-copy')
        ->and(Engine::owns($operation->refresh()))->toBeFalse()->and($bus->orders)->toBe([]);
})->with([
    'a numeric-string instance id' => [fn (Operation $o) => $o->update(['input' => [...$o->input, 'instance_id' => (string) $o->input['instance_id']]])],
    'no instance id' => [fn (Operation $o) => $o->update(['input' => Arr::except($o->input, ['instance_id'])])],
    'an unknown instance id' => [fn (Operation $o) => $o->update(['input' => [...$o->input, 'instance_id' => 999_999]])],
]);

/** A Swamp socket that answers the run-time vault copies and records every frame; a `workflow.run` frame ends the start there. */
function syncSwampSocket(): object
{
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
            if ($frame['type'] === 'workflow.run') {
                throw new RuntimeException('the fixture ends here');
            }

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'] ?? '', 'secretKey' => $frame['payload']['key'] ?? '', 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    return $socket;
}

/** Marks the copy as being removed; the removal's own records are not the subject. */
function syncCopyRemoving(Instance $copy): void
{
    DB::unprepared('DROP TRIGGER instances_removal_status_update');
    $copy->update(['status' => InstanceState::Removing]);
}

/** @return list<array<string, mixed>> */
function syncSwampRuns(object $socket): array
{
    return array_values(array_filter($socket->sent, static fn (array $frame): bool => $frame['type'] === 'workflow.run'));
}

it('refuses a sync beside a confirmed removal of its copy before routing, also when the Swamp fallback would take it', function (Closure $unsupported): void {
    [$group, $copy] = syncCopyWorld();
    $bus = syncEngineBus(SyncEngineActions);
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    $unsupported($operation);
    syncCopyRemoving($copy);
    $socket = syncSwampSocket();

    expect(fn () => app(StartSwampRun::class)->start($operation->refresh()))
        ->toThrow(fn (ResourceOperationException $refusal) => expect($refusal->errorCode)->toBe('operation.target_busy'));
    expect(syncSwampRuns($socket))->toBe([])->and($bus->orders)->toBe([])->and($operation->refresh()->error_code)->toBe('operation.target_busy')
        ->and(Cache::has('tasks.sync.credential.'.$operation->id))->toBeFalse('no credential was minted');
})->with([
    'the Node lacks the cleanup action' => [fn () => syncEngineBus(['instance.fetch_branch', 'instance.sync_base'])],
    'the Node lacks the seed feature' => [fn () => syncEngineBus(SyncEngineActions, [])],
    'a runtime the executor does not resolve' => [fn (Operation $o) => $o->update(['input' => [...$o->input, 'copy' => [...$o->input['copy'], 'runtime' => ['project' => 'team-a', 'name' => $o->input['copy']['runtime']['name']]]]])],
]);

it('refuses a sync while a removal of its copy is admitted and not ended', function (): void {
    [$group, $copy] = syncCopyWorld();
    $bus = syncEngineBus(['instance.fetch_branch']);
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $copy->id, 'node_id' => $copy->node_id,
        'status' => OperationStatus::Queued, 'input' => [], 'state' => []]);
    $socket = syncSwampSocket();

    expect(fn () => app(StartSwampRun::class)->start($operation->refresh()))
        ->toThrow(fn (ResourceOperationException $refusal) => expect($refusal->errorCode)->toBe('operation.target_busy'));
    expect(syncSwampRuns($socket))->toBe([]);
});

it('starts the Swamp sync beside a removal exactly as before while the kind is off', function (): void {
    [$group, $copy] = syncCopyWorld();
    syncEngineBus(SyncEngineActions);
    config(['orbit.engine.kinds' => []]);
    $operation = app(StartTaskSyncAction::class)->execute($group, 'test', null);
    syncCopyRemoving($copy);
    $socket = syncSwampSocket();

    try {
        app(StartSwampRun::class)->start($operation->refresh());
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->not->toBe('operation.target_busy');
    }

    expect(syncSwampRuns($socket))->toHaveCount(1);
});
