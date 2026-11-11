<?php

declare(strict_types=1);

use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

afterEach(fn () => app(SwampConnection::class)->release());

/** @return array{job:string,name:string,status:string,started_at:?string,finished_at:?string} */
function eventStep(string $job, string $name, ?string $started = null, ?string $finished = null): array
{
    return ['job' => $job, 'name' => $name, 'status' => 'succeeded', 'started_at' => $started, 'finished_at' => $finished];
}

it('records the times of the root and nested steps when a run ends', function (): void {
    $root = '986bc349-54dc-4dea-9f22-76e3353d1cb0';
    $child = '986bc349-54dc-4dea-9f22-76e3353d1cb1';
    $operation = Operation::query()->create(['kind' => 'tasks.prep', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => null,
        'status' => OperationStatus::Failed, 'input' => [], 'state' => ['swamp_run_id' => $root, 'native_progress' => ['run' => $root, 'seq' => 4, 'active' => [], 'steps' => [
            'k1' => eventStep('provision', 'Create and prepare the VM'), 'k2' => eventStep('provider', 'seed-plan'),
        ]]]]);
    $socket = new class($root, $child) implements WebSocketClient
    {
        private ?array $frame = null;

        private bool $open = false;

        public function __construct(private readonly string $root, private readonly string $child) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frame = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = $this->frame;
            $this->frame = null;
            if ($frame === null) {
                return [];
            }
            $data = $frame['type'] === 'workflow.run.search'
                ? ['results' => [['runId' => $this->child, 'status' => 'failed', 'parentRun' => ['runId' => $this->root, 'stepName' => 'Create and prepare the VM']],
                    ['runId' => '986bc349-54dc-4dea-9f22-76e3353d1cbf', 'status' => 'succeeded']]]
                : ['id' => $this->child, 'jobs' => [['name' => 'provider', 'steps' => [
                    ['name' => 'seed-plan', 'status' => 'failed', 'startedAt' => '2026-11-06T03:06:22.000Z', 'completedAt' => '2026-11-06T03:06:23.000Z'],
                ]]]];

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $data]]];
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
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.read_token' => 'read-token']);
    $history = ['id' => $root, 'startedAt' => '2026-11-06T03:06:21.000Z', 'jobs' => [['name' => 'provision', 'steps' => [
        ['name' => 'Create and prepare the VM', 'status' => 'failed', 'startedAt' => '2026-11-06T03:06:21.100Z', 'completedAt' => '2026-11-06T03:06:30.000Z'],
    ]]]];

    (new ReflectionMethod(SwampCommandStep::class, 'fillStepTimes'))->invoke(app(SwampCommandStep::class), $operation, $root, $history);

    $steps = OperationData::summary($operation->refresh())->steps;
    expect($steps[0]->started_at)->toBe('2026-11-06T03:06:21.100Z')->and($steps[0]->finished_at)->toBe('2026-11-06T03:06:30.000Z')
        ->and($steps[1]->started_at)->toBe('2026-11-06T03:06:22.000Z')->and($steps[1]->finished_at)->toBe('2026-11-06T03:06:23.000Z');
});

it('reconstructs a completed destroy receipt from native history when every progress event was missed', function (): void {
    $run = '656d243e-e760-42de-aa95-1f149061a5b8';
    $operation = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => 678,
        'status' => OperationStatus::Succeeded, 'input' => [], 'state' => ['swamp_run_id' => $run]]);
    $socket = Mockery::mock(WebSocketClient::class);
    $frame = [];
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $message) use (&$frame): void {
        $frame = $message;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame): array {
        return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => ['results' => []]]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'read-token']);
    $names = ['Prepare container removal', 'Write the git credential', 'Check work is pushed', 'Remove the git credential',
        'Withdraw the Route', 'Remove hibernation files-0', 'Remove hibernation files-1', 'Remove hibernation files-2',
        'Remove the source', 'Check it is gone', 'Delete the container', 'Read container removal', 'Clean container files'];
    $history = ['id' => $run, 'startedAt' => '2026-11-11T00:01:58.000Z', 'jobs' => [['name' => 'destroy', 'steps' => array_map(
        static fn (string $name): array => ['name' => $name, 'status' => 'succeeded', 'startedAt' => '2026-11-11T00:01:58.000Z', 'completedAt' => '2026-11-11T00:02:07.000Z'], $names,
    )]]];

    (new ReflectionMethod(SwampCommandStep::class, 'fillStepTimes'))->invoke(app(SwampCommandStep::class), $operation, $run, $history);

    $steps = OperationData::summary($operation->refresh())->steps;
    expect(array_map(static fn ($step) => $step->name, $steps))->toBe($names)
        ->and($steps[12]->status)->toBe('succeeded')
        ->and($steps[12]->finished_at)->toBe('2026-11-11T00:02:07.000Z');
});
