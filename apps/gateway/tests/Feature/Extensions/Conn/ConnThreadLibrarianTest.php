<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Node;
use App\Models\Project;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Jobs\ClassifyConnThreadJob;
use Orbit\Extensions\Conn\Jobs\PollConnHostThreadsJob;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnThreadClassification;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(now()->startOfSecond());
    $this->actualQueue = Queue::getFacadeRoot();
    Queue::fake();
    Http::preventStrayRequests();
    config(['orbit.conn.thread_index_poll' => false, 'orbit.conn.librarian.router_url' => 'http://ai-router.test',
        'orbit.conn.librarian.router_key' => 'test-model-credential']);
    app(ExtensionStore::class)->set('conn', true);
    $this->host = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'agent', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']));
    $this->host->accessibleNodes()->attach($this->host->id);
    $this->environment = ConnEnvironment::query()->create(['environment_id' => 'env-host', 'label' => 'Agent', 'url' => 'http://conn.test:3773',
        'node_id' => $this->host->id, 'admin_session' => 'test-admin-session', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $this->project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'type' => 'generic', 'node_id' => $this->host->id]);
    $this->thread = ['id' => 'general-thread', 'projectId' => 'scratch-id', 'title' => str_repeat('á', 300),
        'status' => 'running', 'lineage' => ['parentThreadId' => null], 'createdAt' => now()->toIso8601String(),
        'latestUserAuthoredMessageAt' => now()->subSeconds(2)->toIso8601String()];
    $this->firstText = 'Fix the Conn thread index in Orbit. '.str_repeat('é', 5000);
    $this->messages = [
        ['role' => 'user', 'createdBy' => 'agent', 'createdAt' => now()->subSeconds(4)->toIso8601String(), 'text' => 'ignored agent message'],
        ['role' => 'user', 'createdBy' => 'user', 'createdAt' => now()->subSeconds(2)->toIso8601String(), 'text' => $this->firstText,
            'attachments' => [['name' => 'ignored attachment']]],
        ['role' => 'user', 'createdBy' => 'user', 'createdAt' => now()->toIso8601String(), 'text' => 'ignored later message'],
    ];
    $this->rpc = [];
    $this->archived = false;
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->expects('connect')->withArgs(fn (WebSocketEndpoint $endpoint, float $timeout): bool => $endpoint->address === 'conn.test' && $endpoint->port === 3773 && $endpoint->path === '/ws?orchestrationProtocol=2'
        && $endpoint->bearerToken === 'test-admin-session' && ! $endpoint->secure && $timeout === 2.0)->zeroOrMoreTimes();
    $socket->allows('isConnected')->andReturn(true);
    $socket->allows('close');
    $socket->allows('send')->andReturnUsing(function (array $frame): void {
        $this->rpc[] = $frame;
        if ($frame['tag'] === 'orchestration.dispatchCommand') {
            $this->thread['orbitProject'] = $frame['payload']->orbitProject;
        }
    });
    $socket->allows('receive')->andReturnUsing(function (): array {
        $request = end($this->rpc);
        $value = $request['tag'] === 'server.getConfig' ? ['scratchWorkspaceRoot' => '/home/agent/scratch'] : ['sequence' => 12];

        return [['_tag' => 'Exit', 'requestId' => $request['id'], 'exit' => ['_tag' => 'Success', 'value' => $value]]];
    });
    app()->instance(WebSocketClient::class, $socket);
});

function librarianModelResponse(array $result): PromiseInterface
{
    return Http::response(['model' => 'claude-haiku-5-5', 'content' => [['type' => 'thinking', 'thinking' => 'ignored'],
        ['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]]]);
}

function librarianHostPoll(): void
{
    app()->call([new PollConnHostThreadsJob, 'handle']);
}

function librarianQueuedJob(): ClassifyConnThreadJob
{
    Queue::assertPushedOn('tasks', ClassifyConnThreadJob::class);

    return Queue::pushed(ClassifyConnThreadJob::class)->first();
}

function librarianRun(ClassifyConnThreadJob $job): void
{
    app()->call([$job, 'handle']);
}

function librarianWrites(array $frames): array
{
    return array_values(array_filter($frames, static fn (array $frame): bool => $frame['tag'] === 'orchestration.dispatchCommand'));
}

function librarianHttpFake(object $test, Closure $model): void
{
    Http::fake([
        'http://conn.test:3773/health' => Http::response(['server' => 'conn-server', 'version' => '0.5.2']),
        'http://conn.test:3773/api/orchestration/shell' => static fn () => Http::response([
            'projects' => [['id' => 'scratch-id', 'workspaceRoot' => '/home/agent/scratch/'], ['id' => 'repo-id', 'workspaceRoot' => '/home/agent/repo']],
            'threads' => $test->archived ? [] : [$test->thread],
        ]),
        'http://conn.test:3773/api/orchestration/threads/general-thread' => static fn () => Http::response([
            'snapshotSequence' => 4, 'projection' => ['thread' => $test->thread, 'messages' => $test->messages],
        ]),
        'http://ai-router.test/v1/messages' => $model,
    ]);
}

it('polls a host after its first user message, queues and files it once, and lists the answer', function (): void {
    $answer = ['result' => 'existing', 'project_id' => $this->project->id, 'reason' => 'The question is about Orbit Gateway.'];
    librarianHttpFake($this, function (Request $request) use ($answer): PromiseInterface {
        expect($request->hasHeader('Authorization', 'Bearer test-model-credential'))->toBeTrue()
            ->and($request['model'])->toBe('claude-haiku-5-5')
            ->and($request['output_config'])->toBe(['effort' => 'medium'])
            ->and(json_decode($request['messages'][0]['content'], associative: true))->toBe([
                'title' => str_repeat('á', 255), 'first_message' => mb_substr($this->firstText, 0, 4000),
                'projects' => [['id' => $this->project->id, 'slug' => 'orbit', 'name' => 'Orbit', 'description' => 'Work for host agent']],
            ]);

        return librarianModelResponse($answer);
    });
    $event = collect(app(Schedule::class)->events())->firstWhere('description', PollConnHostThreadsJob::class);
    expect($event->repeatSeconds)->toBe(5);

    $authoredAt = $this->thread['latestUserAuthoredMessageAt'];
    $this->thread['latestUserAuthoredMessageAt'] = null;
    librarianHostPoll();
    Queue::assertNotPushed(ClassifyConnThreadJob::class);
    $this->thread['latestUserAuthoredMessageAt'] = $authoredAt;
    librarianHostPoll();
    $job = librarianQueuedJob();
    librarianRun($job);
    expect(librarianWrites($this->rpc))->toHaveCount(1)
        ->and((array) librarianWrites($this->rpc)[0]['payload'])->toMatchArray([
            'type' => 'thread.metadata.update', 'threadId' => 'general-thread', 'orbitProject' => ['id' => $this->project->id, 'slug' => 'orbit'],
        ]);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/orchestration/')
        && $request->hasHeader('Authorization', 'Bearer test-admin-session') && $request->hasHeader('x-t3-orchestration-protocol', '2'));
    $this->withServerVariables(['REMOTE_ADDR' => $this->host->wireguard_ip])->getJson('/api/v1/conn/threads?project='.$this->project->id)
        ->assertOk()->assertJsonPath('data.0.instance_id', null)->assertJsonPath('data.0.project_id', $this->project->id)
        ->assertJsonPath('data.0.classification', $answer)->assertJsonPath('meta.environments.0.environment_id', 'env-host');

    $this->thread['orbitProject'] = null;
    librarianHostPoll();
    librarianRun($job);
    Queue::assertPushedTimes(ClassifyConnThreadJob::class, 1);
    expect(librarianWrites($this->rpc))->toHaveCount(1);
});

it('skips a filed thread, a manually unfiled thread, and a child before calling the model', function (array $metadata): void {
    $this->thread = [...$this->thread, ...$metadata];
    librarianHttpFake($this, static fn () => throw new LogicException('The model must not be called.'));
    librarianHostPoll();
    Queue::assertNotPushed(ClassifyConnThreadJob::class);
    Http::assertSentCount(1);
    expect($this->rpc)->toBeEmpty();
})->with([
    'filed' => [['orbitProject' => ['id' => 19, 'slug' => 'existing']]], 'unfiled' => [['orbitProject' => null]],
    'child' => [['lineage' => ['parentThreadId' => 'parent']]], 'delegated child' => [['delegatedFrom' => ['threadId' => 'parent']]],
]);

it('leaves Conn unchanged for none and new, and keeps the answer even after archiving', function (array $answer): void {
    librarianHttpFake($this, static fn () => librarianModelResponse($answer));
    librarianHostPoll();
    librarianRun(librarianQueuedJob());
    expect(librarianWrites($this->rpc))->toBeEmpty()->and(Project::query()->count())->toBe(1);
    $this->travel(5)->seconds();
    $this->archived = true;
    librarianHostPoll();
    $this->archived = false;
    librarianHostPoll();
    Queue::assertPushedTimes(ClassifyConnThreadJob::class, 1);
    $this->withServerVariables(['REMOTE_ADDR' => $this->host->wireguard_ip])->getJson('/api/v1/conn/threads')
        ->assertOk()->assertJsonPath('data.0.project_id', null)->assertJsonPath('data.0.classification', $answer);
})->with(['none' => [['result' => 'none']], 'new' => [['result' => 'new', 'name' => 'A New Project', 'slug' => 'a-new-project']]]);

it('does not refile when someone unfiles during the model call', function (): void {
    librarianHttpFake($this, function (): PromiseInterface {
        $this->thread['orbitProject'] = null;

        return librarianModelResponse(['result' => 'existing', 'project_id' => $this->project->id, 'reason' => 'Orbit Gateway.']);
    });
    librarianHostPoll();
    librarianRun(librarianQueuedJob());
    expect(librarianWrites($this->rpc))->toBeEmpty();
});

it('does not classify a checkout thread', function (): void {
    $this->thread['projectId'] = 'repo-id';
    librarianHttpFake($this, static fn () => throw new LogicException('The model must not be called.'));
    librarianHostPoll();
    librarianRun(librarianQueuedJob());
    expect(librarianWrites($this->rpc))->toBeEmpty();
});

it('does not backfill threads created before the host’s first librarian poll', function (): void {
    $this->thread['createdAt'] = now()->subDay()->toIso8601String();
    $this->messages[1]['createdAt'] = $this->thread['createdAt'];
    librarianHttpFake($this, static fn () => throw new LogicException('The model must not be called.'));
    librarianHostPoll();
    $this->travel(2)->minutes();
    $this->thread['latestUserAuthoredMessageAt'] = now()->toIso8601String();
    librarianHostPoll();
    Queue::assertNotPushed(ClassifyConnThreadJob::class);
    expect($this->rpc)->toBeEmpty();
});

it('classifies a missed first message on the next poll even after the old window', function (bool $hasLaterMessage): void {
    librarianHttpFake($this, function (Request $request): PromiseInterface {
        expect(json_decode($request['messages'][0]['content'], associative: true)['first_message'])->toBe(mb_substr($this->firstText, 0, 4000));

        return librarianModelResponse(['result' => 'existing', 'project_id' => $this->project->id, 'reason' => 'Orbit Gateway.']);
    });
    $this->archived = true;
    librarianHostPoll();
    Queue::assertNotPushed(ClassifyConnThreadJob::class);

    $this->thread['createdAt'] = now()->addSecond()->toIso8601String();
    $this->messages[1]['createdAt'] = now()->addSeconds(2)->toIso8601String();
    $this->travel(2)->minutes();
    if ($hasLaterMessage) {
        $this->messages[2]['createdAt'] = now()->subSecond()->toIso8601String();
        $this->thread['latestUserAuthoredMessageAt'] = $this->messages[2]['createdAt'];
    } else {
        unset($this->messages[2]);
        $this->thread['latestUserAuthoredMessageAt'] = $this->messages[1]['createdAt'];
    }
    $this->archived = false;
    librarianHostPoll();
    librarianRun(librarianQueuedJob());
    expect(librarianWrites($this->rpc))->toHaveCount(1);
})->with(['only the first message' => [false], 'a later message arrived' => [true]]);

it('releases the job for retry on a model failure and invalid JSON without filing', function (): void {
    Queue::swap($this->actualQueue);
    config(['queue.connections.gateway' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'tasks', 'retry_after' => 90]]);
    $attempt = 0;
    librarianHttpFake($this, function () use (&$attempt): PromiseInterface {
        return match (++$attempt) {
            1 => Http::response(['error' => 'fake outage'], 503),
            2 => Http::response(['content' => [['type' => 'text', 'text' => '```not json```']]]),
            default => librarianModelResponse(['result' => 'existing', 'project_id' => $this->project->id, 'reason' => 'Orbit Gateway.']),
        };
    });
    librarianHostPoll();
    $connection = Queue::connection('gateway');
    $worker = app('queue.worker');
    foreach ([15, 60] as $delay) {
        $job = $connection->pop('tasks');
        expect($job)->not->toBeNull();
        expect(fn () => $worker->process('gateway', $job, new WorkerOptions(maxTries: 1)))->toThrow(RuntimeException::class, 'Conn librarian');
        expect($job->isReleased())->toBeTrue()->and(DB::table('jobs')->value('available_at'))->toBe(now()->getTimestamp() + $delay)
            ->and(librarianWrites($this->rpc))->toBeEmpty()->and(ConnThreadClassification::query()->count())->toBe(0);
        $this->travel($delay)->seconds();
    }
    $worker->process('gateway', $connection->pop('tasks'), new WorkerOptions(maxTries: 1));
    expect(librarianWrites($this->rpc))->toHaveCount(1)->and(DB::table('jobs')->count())->toBe(0);
});

it('gives up after three failed model attempts and later polls do not requeue the thread', function (): void {
    Queue::swap($this->actualQueue);
    config(['queue.connections.gateway' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'tasks', 'retry_after' => 90]]);
    $attempt = 0;
    librarianHttpFake($this, static function () use (&$attempt): PromiseInterface {
        return ++$attempt < 3 ? Http::response(['error' => 'fake outage'], 503)
            : Http::response(['content' => [['type' => 'text', 'text' => 'invalid JSON']]]);
    });
    librarianHostPoll();
    $connection = Queue::connection('gateway');
    $worker = app('queue.worker');
    foreach ([15, 60, 0] as $delay) {
        $job = $connection->pop('tasks');
        expect($job)->not->toBeNull();
        expect(fn () => $worker->process('gateway', $job, new WorkerOptions(maxTries: 1)))->toThrow(RuntimeException::class, 'Conn librarian');
        $this->travel($delay)->seconds();
    }

    expect($attempt)->toBe(3)->and(DB::table('jobs')->count())->toBe(0)->and(librarianWrites($this->rpc))->toBeEmpty();
    $this->thread['latestUserAuthoredMessageAt'] = now()->toIso8601String();
    librarianHostPoll();
    expect(DB::table('jobs')->count())->toBe(0);
    $this->withServerVariables(['REMOTE_ADDR' => $this->host->wireguard_ip])->getJson('/api/v1/conn/threads')
        ->assertOk()->assertJsonPath('data.0.project_id', null)
        ->assertJsonPath('data.0.classification', ['result' => 'given_up', 'error_code' => 'conn.ai_router_invalid_response']);
});
