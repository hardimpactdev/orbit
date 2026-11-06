<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampConnection;
use App\Infrastructure\Swamp\SwampScheduledResults;
use App\Jobs\Operations\ProjectSwampScheduledTerminal;
use App\Jobs\Operations\ProjectSwampScheduleFire;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

const POLL_CHECK_WORKFLOW = '4a2d9dd5-9f83-41d4-b8df-8e46367ed230';

/** A read connection that answers each `workflow.run.search` with the next page the test names. */
function pollSocket(array $pages): WebSocketClient
{
    $socket = new class($pages) implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        private bool $open = false;

        /** @param list<list<array<string,mixed>>> $pages */
        public function __construct(private array $pages) {}

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

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => ['results' => array_shift($this->pages) ?? []]]]];
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

    return $socket;
}

/** @return array<string,mixed> */
function pollRun(string $run, string $workflow = POLL_CHECK_WORKFLOW, string $name = 'task-check-ci', string $status = 'succeeded'): array
{
    return ['runId' => $run, 'workflowId' => $workflow, 'workflowName' => $name, 'status' => $status,
        'startedAt' => '2026-11-06T01:00:00.000Z', 'completedAt' => '2026-11-06T01:00:05.000Z'];
}

afterEach(fn () => app(SwampConnection::class)->release());

it('asks Swamp for the runs that finished in the last hour, with no commit feed', function (): void {
    Bus::fake();
    $socket = pollSocket([[]]);

    app(SwampScheduledResults::class)->poll();

    expect($socket->frames)->toHaveCount(1)
        ->and($socket->frames[0]['type'])->toBe('workflow.run.search')
        ->and($socket->frames[0]['payload']['statuses'])->toBe(['succeeded', 'failed', 'cancelled', 'interrupted'])
        ->and($socket->frames[0]['payload']['since'])->toMatch('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/D');
    expect(array_column($socket->frames, 'type'))->not->toContain('commits.subscribe');
});

it('queues the projection of a finished declared run once, even when a later read lists it again', function (): void {
    Bus::fake();
    $run = '986bc349-54dc-4dea-9f22-76e3353d1c81';
    pollSocket([[pollRun($run)], [pollRun($run)]]);

    app(SwampScheduledResults::class)->poll();
    app(SwampScheduledResults::class)->poll();

    Bus::assertDispatchedTimes(ProjectSwampScheduledTerminal::class, 1);
    Bus::assertDispatched(ProjectSwampScheduledTerminal::class, fn (ProjectSwampScheduledTerminal $job): bool => $job->workflowId === POLL_CHECK_WORKFLOW
        && $job->runId === $run && $job->status === 'succeeded');
});

it('does not queue a run whose projector already accepted it', function (): void {
    Bus::fake();
    $run = '986bc349-54dc-4dea-9f22-76e3353d1c82';
    pollSocket([[pollRun($run)]]);
    SwampScheduledResults::done($run);

    app(SwampScheduledResults::class)->poll();

    Bus::assertNotDispatched(ProjectSwampScheduledTerminal::class);
});

it('ignores runs of other workflows and a declared id that carries another name', function (): void {
    Bus::fake();
    pollSocket([[
        pollRun('986bc349-54dc-4dea-9f22-76e3353d1c83', '11111111-2222-4333-8444-555555555555', 'something-else'),
        pollRun('986bc349-54dc-4dea-9f22-76e3353d1c84', POLL_CHECK_WORKFLOW, 'not-the-declared-name'),
        pollRun('986bc349-54dc-4dea-9f22-76e3353d1c85', '22222222-2222-4333-8444-555555555555', 'schedule-33333333-4444-4555-8666-777777777777'),
    ]]);

    app(SwampScheduledResults::class)->poll();

    Bus::assertNotDispatched(ProjectSwampScheduledTerminal::class);
    Bus::assertNotDispatched(ProjectSwampScheduleFire::class);
});

it('does nothing without the read credential', function (): void {
    Bus::fake();
    $socket = pollSocket([[pollRun('986bc349-54dc-4dea-9f22-76e3353d1c86')]]);
    config(['swamp.read_token' => '']);

    app(SwampScheduledResults::class)->poll();

    expect($socket->frames)->toBe([]);
    Bus::assertNotDispatched(ProjectSwampScheduledTerminal::class);
});

it('keeps the read cursor from one poll to the next', function (): void {
    Bus::fake();
    pollSocket([[], []]);

    app(SwampScheduledResults::class)->poll();

    expect(Cache::get('swamp:scheduled-results:'.hash('sha256', 'swamp.test').':cursor'))->toBeString();
});
