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
        'node_id' => $node->id, 'status' => 'running', 'attempts' => 1, 'started_at' => now()->subHours(3),
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

it('keeps exactly the two Fleet no-selector generic failures in the job', function (string $kind): void {
    $node = containerDefaultNode();
    $missing = array_keys(array_filter(app(DirectOperation::class)->definitions(), fn (array $definition): bool => ! isset($definition['terminal_result'])));
    sort($missing);
    expect($missing)->toBe(['fleet.cli_converge', 'fleet.rollout']);
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'fleet', 'target_id' => 1,
        'node_id' => $node->id, 'status' => 'running', 'attempts' => 1, 'started_at' => now()->subMinute(),
        'input' => [], 'state' => ['swamp_run_id' => '249be7ac-a6ee-4cd0-a954-4556948eb3bb']]);
    $this->socket->runs[$operation->state['swamp_run_id']] = passiveRun($operation, 'failed', []);
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect($operation->fresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->fresh()->error_code)->toBe('operation.native_failed')
        ->and($operation->fresh()->result)->toBe(['error_code' => 'operation.native_failed']);
})->with(['fleet.rollout', 'fleet.cli_converge']);

it('measures reconcile pass cost with and without one pending no-selector Operation', function (): void {
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

it('keeps the legacy input GET passive for exactly the two kinds without full workflow inputs', function (string $kind): void {
    $legacy = array_keys(array_filter(app(DirectOperation::class)->definitions(),
        fn (array $definition): bool => ($definition['workflow_inputs'] ?? false) !== true));
    sort($legacy);
    expect($legacy)->toBe(['fleet.cli_converge', 'fleet.rollout']);
    $node = containerDefaultNode();
    $run = '249be7ac-a6ee-4cd0-a954-4556948eb3bb';
    $input = ['nodes' => [], 'commit' => str_repeat('a', 40)];
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'fleet', 'target_id' => 1,
        'node_id' => $node->id, 'status' => 'running', 'input' => $input, 'state' => ['swamp_run_id' => $run]]);
    $before = $operation->refresh()->getAttributes();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->getJson("/api/v1/direct-operations/{$operation->id}?run_id={$run}")
        ->assertOk()->assertJsonPath('data.input', $input);
    expect($operation->refresh()->getAttributes())->toBe($before)
        ->and($this->socket->sent)->toBe([])->and($this->socket->connects)->toBe(0);
})->with(['fleet.rollout', 'fleet.cli_converge']);
