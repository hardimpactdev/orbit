<?php

declare(strict_types=1);

use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\NativeRunStepTimes;
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

/** @return array{job:string,name:string,started_at:string,finished_at:string|null} */
function historyStep(string $job, string $name, string $started, ?string $finished): array
{
    return ['job' => $job, 'name' => $name, 'started_at' => $started, 'finished_at' => $finished];
}

it('fills the times of a step from the history by job and name', function (): void {
    $steps = ['a' => eventStep('provision', 'eligible'), 'b' => eventStep('provision', 'cloud')];

    $merged = (new NativeRunStepTimes)->merge($steps, [
        historyStep('provision', 'cloud', '2026-11-06T03:06:21.500Z', '2026-11-06T03:06:21.700Z'),
        historyStep('provision', 'eligible', '2026-11-06T03:06:21.100Z', '2026-11-06T03:06:21.400Z'),
    ]);

    expect($merged['a']['started_at'])->toBe('2026-11-06T03:06:21.100Z')
        ->and($merged['a']['finished_at'])->toBe('2026-11-06T03:06:21.400Z')
        ->and($merged['b']['started_at'])->toBe('2026-11-06T03:06:21.500Z');
});

it('keeps a time the events already carried', function (): void {
    $merged = (new NativeRunStepTimes)->merge(
        ['a' => eventStep('j', 's', '2026-11-06T03:00:00.000Z', null)],
        [historyStep('j', 's', '2026-11-06T03:00:09.000Z', '2026-11-06T03:00:10.000Z')],
    );

    expect($merged['a']['started_at'])->toBe('2026-11-06T03:00:00.000Z')->and($merged['a']['finished_at'])->toBe('2026-11-06T03:00:10.000Z');
});

it('matches a repeated step name by position when both lists have the same number', function (): void {
    $steps = ['a' => eventStep('provision', 'result'), 'b' => eventStep('provision', 'result')];

    $merged = (new NativeRunStepTimes)->merge($steps, [
        historyStep('provision', 'result', '2026-11-06T03:00:01.000Z', '2026-11-06T03:00:02.000Z'),
        historyStep('provision', 'result', '2026-11-06T03:00:05.000Z', '2026-11-06T03:00:06.000Z'),
    ]);

    expect($merged['a']['started_at'])->toBe('2026-11-06T03:00:01.000Z')->and($merged['b']['started_at'])->toBe('2026-11-06T03:00:05.000Z');
});

it('leaves a repeated step alone when the counts differ', function (): void {
    $steps = ['a' => eventStep('provision', 'result'), 'b' => eventStep('provision', 'result')];

    $merged = (new NativeRunStepTimes)->merge($steps, [historyStep('provision', 'result', '2026-11-06T03:00:01.000Z', '2026-11-06T03:00:02.000Z')]);

    expect($merged)->toBe($steps);
});

it('matches a looped step, named with its index, to the history step name', function (): void {
    $merged = (new NativeRunStepTimes)->merge(
        ['a' => eventStep('source', 'setup-0-0'), 'b' => eventStep('source', 'setup-0-1')],
        [historyStep('source', 'setup-0', '2026-11-06T03:00:01.000Z', '2026-11-06T03:00:02.000Z'), historyStep('source', 'setup-0', '2026-11-06T03:00:03.000Z', '2026-11-06T03:00:04.000Z')],
    );

    expect($merged['a']['finished_at'])->toBe('2026-11-06T03:00:02.000Z')->and($merged['b']['started_at'])->toBe('2026-11-06T03:00:03.000Z');
});

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
