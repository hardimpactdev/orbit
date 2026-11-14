<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampConnection;
use App\Jobs\Operations\ProjectEndedNativeRun;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../Instances/ContainerInstanceHelpers.php';

afterEach(fn () => app(SwampConnection::class)->release());

const PROMPT_RUN = '7a1e2c3d-4b5f-4a60-8b71-9c2d3e4f5a61';

/** A tasks.publish bound to its run and holding its group: the shape of the unreadable-result settlement (#3685). */
function promptPublish(): Operation
{
    $group = Task::query()->create(['title' => 'Copy', 'brief' => 'Publish it', 'status' => TaskGroupStatus::Running]);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast-'.$group->id, 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $node->roles()->firstOrCreate(['role' => 'gateway'], ['status' => 'active']);

    return Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $node->id,
        'lock_key' => OperationStore::lockKey('task_group', $group->id), 'input' => ['branch' => 'task/1', 'commit' => str_repeat('a', 40)],
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => PROMPT_RUN], 'sequence' => 1, 'attempts' => 1, 'started_at' => now()->subMinutes(2)]);
}

/**
 * Serve and the vault behind one WebSocket: the run's history, every secret read answered with `gateway-session`. `$onHistory` runs
 * once, inside the first history read, to put a second projection between that read and the first projection's lock.
 *
 * @param  array<string, mixed>  $history
 * @return WebSocketClient&object{frames: list<array<string,mixed>>, historyReads: int, fail: bool, onHistory: ?Closure}
 */
function promptSocket(array $history): WebSocketClient
{
    $socket = new class($history) implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        public int $historyReads = 0;

        public bool $fail = false;

        public ?Closure $onHistory = null;

        private bool $open = false;

        /** @param array<string,mixed> $history */
        public function __construct(private readonly array $history) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);
            if ($frame === false) {
                return [];
            }
            if ($frame['type'] === 'workflow.history.get') {
                $this->historyReads++;
                if ($this->fail) {
                    return [['id' => $frame['id'], 'type' => 'error', 'error' => ['code' => 'unavailable']]];
                }
                if ($this->onHistory !== null) {
                    $second = $this->onHistory;
                    $this->onHistory = null;
                    $second();
                }

                return [['id' => $frame['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $this->history]]];
            }
            $key = (string) ($frame['payload']['secretKey'] ?? $frame['payload']['key'] ?? '');
            $data = ['vaultName' => $frame['payload']['vaultName'] ?? 'orbit-control-inputs', 'secretKey' => $key, 'vaultType' => 'local_encryption'];

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $frame['type'] === 'vault.read-secret' ? [...$data, 'value' => 'gateway-session'] : $data]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    app(SwampConnection::class)->release();
    config(['swamp.run_token' => 'run-token', 'swamp.read_token' => 'read-token', 'swamp.address' => '10.44.0.15', 'swamp.ca_path' => __FILE__]);

    return $socket;
}

/** @param list<array<string, mixed>> $jobs */
function promptHistory(Operation $operation, string $workflow, string $status, array $jobs): array
{
    return ['id' => PROMPT_RUN, 'workflowName' => $workflow, 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => now()->subMinute()->toIso8601ZuluString('millisecond'), 'completedAt' => now()->subSeconds(2)->toIso8601ZuluString('millisecond'),
        'jobs' => $jobs];
}

function promptJob(int $operationId): void
{
    app()->call([new ProjectEndedNativeRun($operationId), 'handle']);
}

function promptPass(): void
{
    app()->call([new ReconcileSwampOperations, 'handle']);
}

/** A clone create bound to its run, between admission and its end, as the clone tests make it. */
function promptCloneCreate(): Operation
{
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $default = Instance::query()->findOrFail(containerCreate($project, $node)->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);
    $create = containerCreate($project, $node, 'feature');
    $create->update(['status' => OperationStatus::Running, 'attempts' => 1, 'state' => ['swamp_run_id' => PROMPT_RUN]]);

    return $create->refresh();
}

/** The create run's history: its `finish` step prints the receipt the projection reads. */
function promptCreateHistory(Operation $create, bool $ready): array
{
    $receipt = $ready ? ['ready' => true, 'instance_id' => $create->target_id, 'commit' => str_repeat('a', 40), 't3' => [
        'environment_id' => 'env-prompt', 'server_version' => '0.1.7', 'expires_at' => '2026-12-10T10:00:00.000Z',
        'gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-gateway-1-token'],
        'swamp_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-swamp-1-token']]]
        : ['ready' => false, 'instance_id' => $create->target_id, 'failed_step' => 'clone', 'error_code' => 'instance.create_clone_failed'];

    return promptHistory($create, 'operation-instance-create', $ready ? 'succeeded' : 'failed',
        [['name' => 'create', 'status' => $ready ? 'succeeded' : 'failed', 'steps' => [['name' => 'finish', 'status' => 'succeeded',
            'outputs' => ['stdout' => json_encode($receipt, JSON_THROW_ON_ERROR)]]]]]);
}

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->freezeSecond();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(ExtensionStore::class)->set('conn', true);
    app()->instance(TaskVmModelRoute::class, new class implements TaskVmModelRoute
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'placeholder-model-key';
        }
    });
});

it('queues on its own worker, one per Operation, and is not dropped by a pass that waits or runs', function (): void {
    ReconcileSwampOperations::dispatch();
    ReconcileSwampOperations::dispatch();
    ProjectEndedNativeRun::dispatch(41);
    ProjectEndedNativeRun::dispatch(41);
    ProjectEndedNativeRun::dispatch(42);

    // The pass is unique: its second request is dropped, as a run's end was before. The prompt job is unique per Operation only.
    Queue::assertPushed(ReconcileSwampOperations::class, 1);
    Queue::assertPushed(ProjectEndedNativeRun::class, 2);
    Queue::assertPushed(ProjectEndedNativeRun::class, fn (ProjectEndedNativeRun $job): bool => $job->operationId === 41
        && $job->queue === GatewayQueue::OperationsPrompt->value && $job->connection === GatewayQueue::Connection);
});

it('settles a run whose end check is unreadable as the pass does: failed operation.result_unreadable, target freed', function (): void {
    $operation = promptPublish();
    promptSocket(promptHistory($operation, 'operation-task-publish', 'succeeded', [['name' => 'cleanup', 'status' => 'succeeded', 'steps' => [
        ['name' => 'Revoke the token', 'status' => 'succeeded'], ['name' => 'Confirm publication', 'status' => 'skipped']]]]));

    promptJob($operation->id);

    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('operation.result_unreadable')
        ->and($operation->lock_key)->toBeNull();
});

it('leaves a failed read for the pass, which projects it', function (): void {
    $operation = promptPublish();
    $socket = promptSocket(promptHistory($operation, 'operation-task-publish', 'succeeded', [['name' => 'cleanup', 'status' => 'succeeded', 'steps' => [
        ['name' => 'Confirm publication', 'status' => 'skipped']]]]));
    $socket->fail = true;

    promptJob($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running);

    $socket->fail = false;
    promptPass();
    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('operation.result_unreadable');
});

it('projects two ends of the same run once', function (): void {
    $create = promptCloneCreate();
    swampVaultStandIn();
    workspaceReader();
    promptSocket(promptCreateHistory($create, ready: true));

    promptJob($create->id);
    promptJob($create->id);

    expect($create->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and(Operation::query()->where('kind', 'instance.complete')->where('target_id', $create->target_id)->count())->toBe(1);
});

it('projects once when the pass and the prompt job hit the same ended create at once', function (bool $ready): void {
    $create = promptCloneCreate();
    workspaceReader();
    $socket = promptSocket(promptCreateHistory($create, $ready));
    // The prompt job runs inside the pass's history read, so both have read the ended run before either has projected it.
    $socket->onHistory = fn () => promptJob($create->id);

    promptPass();

    $create->refresh();
    expect($create->status)->toBe($ready ? OperationStatus::Succeeded : OperationStatus::Failed)
        ->and($socket->historyReads)->toBe(2)
        ->and(Operation::query()->where('kind', 'instance.complete')->where('target_id', $create->target_id)->count())->toBe($ready ? 1 : 0)
        ->and(Activity::query()->where('description', 'instance.operation_failed')->count())->toBe($ready ? 0 : 1);
})->with(['succeeded: one completion' => [true], 'failed: one alert' => [false]]);

it('leaves an Operation the pass would not read alone', function (): void {
    $operation = promptPublish();
    $operation->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
    $socket = promptSocket([]);

    promptJob($operation->id);
    promptJob(999999);

    expect($socket->historyReads)->toBe(0);
});
