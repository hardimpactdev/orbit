<?php

declare(strict_types=1);

use App\Actions\Operations\ReadOperationAction;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampConnection;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProcessDefinition;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../Instances/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->socket = new class implements WebSocketClient
    {
        public array $runs = [];

        public array $sent = [];

        public int $connects = 0;

        public int $latencyMicroseconds = 0;

        private array $pending = [];

        private bool $open = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->connects++;
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->sent[] = $message;
            $this->pending[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            if ($this->latencyMicroseconds > 0) {
                usleep($this->latencyMicroseconds);
            }
            $replies = [];
            foreach ($this->pending as $message) {
                $data = $message['type'] === 'workflow.history.get'
                    ? ($this->runs[$message['payload']['workflowIdOrName']] ?? [])
                    : ['results' => []];
                $replies[] = ['id' => $message['id'], 'type' => $message['type'], 'payload' => ['data' => $data]];
            }
            $this->pending = [];

            return $replies;
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
    app(SwampConnection::class)->release();
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'fixture-read-token']);
});

afterEach(fn () => app(SwampConnection::class)->release());

function passiveRun(Operation $operation, string $status, array $result, string $job = 'create', string $step = 'finish'): array
{
    return ['id' => $operation->state['swamp_run_id'], 'workflowName' => app(DirectOperation::class)->definition($operation->kind)['workflow'],
        'status' => $status, 'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => now()->subMinute()->toISOString(), 'completedAt' => now()->toISOString(),
        'jobs' => [['name' => $job, 'steps' => [['name' => $step, 'status' => 'succeeded',
            'outputs' => ['stdout' => json_encode($result, JSON_THROW_ON_ERROR)]]]]]];
}

it('returns recorded state twice without a Swamp call then the job keeps the real create failure and domain state', function (bool $readFirst): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $control = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit']));
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'inactive-fixture', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/bin/false']]]);
    $operation = containerCreate($project, $node);
    $instance = Instance::query()->findOrFail($operation->target_id);
    $operation->update(['status' => 'running', 'attempts' => 1, 'started_at' => now()->subMinute(),
        'state' => ['swamp_run_id' => '249be7ac-a6ee-4cd0-a954-4556948eb3bb']]);
    $result = ['instance_id' => $instance->id, 'ready' => false, 'failed_step' => 'register', 'commit' => str_repeat('a', 40),
        'error_code' => 'instance.container_process_inactive',
        'process_failure' => ['process_id' => $instance->processes()->sole()->id, 'active_state' => 'failed', 'result' => 'exit-code', 'exec_main_status' => 1]];
    $this->socket->runs[$operation->state['swamp_run_id']] = passiveRun($operation, 'failed', $result);
    if ($readFirst) {
        $before = $operation->refresh()->getAttributes();
        foreach ([1, 2] as $unused) {
            expect(app(ReadOperationAction::class)->execute($operation)->getAttributes())->toBe($before);
        }
        expect($operation->fresh()->getAttributes())->toBe($before)
            ->and($this->socket->sent)->toBe([])->and($this->socket->connects)->toBe(0)
            ->and($instance->fresh()->status)->toBe(InstanceState::Reserved);
    }
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($operation->fresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->fresh()->error_code)->toBe('instance.container_process_inactive')
        ->and($operation->fresh()->result)->toMatchArray($result)
        ->and($instance->fresh()->status)->toBe(InstanceState::SourceResolved)
        ->and($instance->fresh()->starting_commit)->toBe(str_repeat('a', 40));
})->with(['two shows first' => true, 'only the job' => false]);

it('does not expire on show and the job alone expires an unavailable native run', function (bool $readFirst): void {
    $node = containerDefaultNode();
    $operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node_footprint', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => 'running', 'attempts' => 1, 'started_at' => now()->subHours(4),
        'input' => [], 'lock_key' => 'passive-expiry', 'state' => ['swamp_run_id' => '249be7ac-a6ee-4cd0-a954-4556948eb3bb']]);
    expect(OperationRunBudgets::deadline($operation)->isPast())->toBeTrue();
    $before = $operation->refresh()->getAttributes();
    if ($readFirst) {
        expect(app(ReadOperationAction::class)->execute($operation)->getAttributes())->toBe($before)
            ->and($this->socket->sent)->toBe([]);
    }
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($operation->fresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->fresh()->error_code)->toBe('operation.timed_out');
})->with(['show first' => true, 'only the job' => false]);

it('returns a succeeded Operation without remote reads or writes', function (): void {
    $operation = Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => null,
        'status' => 'succeeded', 'result' => ['outcome' => 'succeeded'], 'finished_at' => now(), 'input' => [], 'state' => []]);
    $before = $operation->refresh()->getAttributes();
    expect(app(ReadOperationAction::class)->execute($operation)->getAttributes())->toBe($before)
        ->and($operation->fresh()->getAttributes())->toBe($before)
        ->and($this->socket->sent)->toBe([])->and($this->socket->connects)->toBe(0);
});

it('settles Fleet native results through the shared reader', function (string $kind, string $status): void {
    $node = containerDefaultNode();
    $missing = array_keys(array_filter(app(DirectOperation::class)->definitions(), fn (array $definition): bool => ! isset($definition['terminal_result'])));
    expect($missing)->toBe([]);
    $result = ['node_id' => $node->id, 'outcome' => 'unchanged', 'error_code' => null,
        'cli_version' => '0.100.0', 'agent_version' => null, 'step' => 'cli', 'ok' => true];
    $output = ['nodes' => [$result], 'error_code' => null];
    if ($status === 'failed') {
        $output = ['nodes' => [$result,
            [...$result, 'node_id' => $node->id + 1, 'outcome' => 'failed', 'error_code' => 'cli.install_failed', 'ok' => false],
            [...$result, 'node_id' => $node->id + 2, 'outcome' => 'pending', 'cli_version' => null, 'step' => 'admission', 'ok' => false],
        ], 'error_code' => 'cli.install_failed'];
    }
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'fleet', 'target_id' => 1,
        'node_id' => $node->id, 'status' => 'running', 'attempts' => 1, 'started_at' => now()->subMinute(),
        'input' => ['nodes' => array_map(static fn (array $node): array => ['node_id' => $node['node_id']], $output['nodes'])],
        'state' => ['swamp_run_id' => '249be7ac-a6ee-4cd0-a954-4556948eb3bb']]);
    $run = passiveRun($operation, $status, [], 'finish', 'finish');
    $run['jobs'][0]['steps'][0]['outputs'] = $output;
    $this->socket->runs[$operation->state['swamp_run_id']] = $run;

    app()->call([new ReconcileSwampOperations, 'handle']);

    expect($operation->fresh()->status)->toBe(OperationStatus::from($status))
        ->and($operation->fresh()->result)->toBe($output)
        ->and($operation->fresh()->error_code)->toBe(match ($status) {
            'failed' => 'cli.install_failed', 'interrupted' => 'operation.native_failed', default => null,
        });
})->with(['fleet.rollout', 'fleet.cli_converge'])->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

it('settles Fleet cancellation or interruption before aggregation without inventing Node outcomes', function (string $kind, string $status): void {
    $node = containerDefaultNode();
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'fleet', 'target_id' => 1,
        'node_id' => $node->id, 'status' => 'running', 'attempts' => 1, 'started_at' => now()->subMinute(),
        'input' => ['nodes' => [['node_id' => $node->id]]],
        'state' => ['swamp_run_id' => '249be7ac-a6ee-4cd0-a954-4556948eb3bb']]);
    $run = passiveRun($operation, $status, [], 'finish', 'finish');
    $run['jobs'][0]['steps'][0]['status'] = 'skipped';
    unset($run['jobs'][0]['steps'][0]['outputs']);
    $this->socket->runs[$operation->state['swamp_run_id']] = $run;

    app()->call([new ReconcileSwampOperations, 'handle']);

    expect($operation->fresh()->status)->toBe(OperationStatus::from($status))
        ->and($operation->fresh()->result)->not->toHaveKey('nodes')
        ->and($operation->fresh()->error_code)->toBe($status === 'interrupted' ? 'operation.native_failed' : null);
})->with(['fleet.rollout', 'fleet.cli_converge'])->with(['cancelled', 'interrupted']);

it('measures reconcile pass cost with and without one pending Fleet Operation', function (): void {
    $node = containerDefaultNode();
    $samples = ['without' => [], 'with' => []];
    // A controlled 20 ms transport reply makes the extra Fleet request visible without a live Node.
    $this->socket->latencyMicroseconds = 20000;
    for ($index = 0; $index < 10; $index++) {
        $before = count($this->socket->sent);
        $started = hrtime(true);
        app()->call([new ReconcileSwampOperations, 'handle']);
        $samples['without'][] = (hrtime(true) - $started) / 1e6;
        expect(count($this->socket->sent) - $before)->toBe(0);
    }
    $operation = Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'fleet', 'target_id' => 1,
        'node_id' => $node->id, 'status' => 'running', 'attempts' => 1, 'started_at' => now()->subMinute(),
        'input' => [], 'state' => ['swamp_run_id' => '249be7ac-a6ee-4cd0-a954-4556948eb3bb']]);
    $this->socket->runs[$operation->state['swamp_run_id']] = passiveRun($operation, 'running', []);
    for ($index = 0; $index < 10; $index++) {
        $before = count($this->socket->sent);
        $started = hrtime(true);
        app()->call([new ReconcileSwampOperations, 'handle']);
        $samples['with'][] = (hrtime(true) - $started) / 1e6;
        expect(array_column(array_slice($this->socket->sent, $before), 'type'))->toBe(['workflow.history.get']);
    }
    file_put_contents('/tmp/orbit-passive-reconcile-cost.json', json_encode(['fixture_reply_ms' => 20, 'samples_ms' => $samples], JSON_THROW_ON_ERROR));
});

it('passes only admitted Node intent to Fleet runs and keeps rollout bookkeeping in the Gateway', function (string $kind): void {
    $legacy = array_keys(array_filter(app(DirectOperation::class)->definitions(),
        fn (array $definition): bool => ($definition['workflow_inputs'] ?? false) !== true));
    expect($legacy)->toBe([]);
    $nodes = [['node_id' => 12], ['node_id' => 9]];
    $operation = new Operation(['kind' => $kind, 'node_id' => 209,
        'input' => ['nodes' => $nodes, 'commit' => str_repeat('a', 40), 'remaining' => 2, 'rollout_id' => 668]]);
    $operation->id = 17;

    expect(app(DirectOperation::class)->workflowInputs($operation))->toEqual([
        'intent' => ['nodes' => $nodes], 'execution_secrets' => (object) [], 'operation_id' => 17, 'node_id' => 209, 'kind' => $kind,
    ]);
    expect($operation->input['commit'])->toBe(str_repeat('a', 40));
})->with(['fleet.rollout', 'fleet.cli_converge']);
