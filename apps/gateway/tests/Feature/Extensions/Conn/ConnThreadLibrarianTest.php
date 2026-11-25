<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Models\Instance;
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
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Domain\T3ServerException;
use Orbit\Extensions\Conn\Jobs\ClassifyConnThreadJob;
use Orbit\Extensions\Conn\Jobs\DiscoverConnThreadJob;
use Orbit\Extensions\Conn\Jobs\PollConnHostThreadsJob;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnThread;
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
    $this->missingReads = 0;
    $this->dropFilingReplies = 0;
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
        if ($request['tag'] === 'orchestration.dispatchCommand' && $this->dropFilingReplies-- > 0) {
            throw new WebSocketException('The filing reply was lost.');
        }
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

function librarianQueuedJob(): ClassifyConnThreadJob
{
    Queue::assertPushedOn('tasks', ClassifyConnThreadJob::class);

    return Queue::pushed(ClassifyConnThreadJob::class)->first();
}

function librarianRun(ClassifyConnThreadJob $job): void
{
    app()->call([$job, 'handle']);
}

/** The host Conn Server's write-through of the thread, which stands for its first user message. */
function librarianPush(object $test, string $title = 'Fix the index', string $threadId = 'general-thread'): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => $test->host->wireguard_ip])
        ->postJson('/api/v1/conn/environments/env-host/threads', ['thread_id' => $threadId, 'title' => $title]);
}

/** A push whose queued discovery a worker runs at once. */
function librarianPushed(object $test): void
{
    librarianPush($test)->assertSuccessful();
    librarianRunPush();
}

/** The real database queue, for the retry and give-up rules. */
function librarianRealQueue(object $test): void
{
    Queue::swap($test->actualQueue);
    config(['queue.connections.gateway' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'tasks', 'retry_after' => 90]]);
}

/** Runs the latest push's queued discovery, as a worker would. */
function librarianRunPush(): void
{
    app()->call([Queue::pushed(DiscoverConnThreadJob::class)->last(), 'handle']);
}

/** The scheduled host poll, run as a worker would. */
function librarianHostPoll(): void
{
    app()->call([new PollConnHostThreadsJob, 'handle']);
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
        'http://conn.test:3773/api/orchestration/threads/general-thread' => static fn () => $test->missingReads-- > 0
            ? Http::response(['error' => 'not found'], 404)
            : Http::response(['snapshotSequence' => 4, 'projection' => ['thread' => $test->thread, 'messages' => $test->messages]]),
        'http://ai-router.test/v1/messages' => $model,
    ]);
}

function librarianExisting(object $test): Closure
{
    return static fn (): PromiseInterface => librarianModelResponse(['result' => 'existing', 'project_id' => $test->project->id, 'reason' => 'Orbit Gateway.']);
}

it('queues the librarian from a host push, files the thread once, and keeps the response', function (): void {
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
    $response = librarianPush($this);
    $response->assertCreated()->assertExactJson(['data' => ['environment_id' => 'env-host', 'instance_id' => null,
        'project_id' => null, 'thread_id' => 'general-thread', 'title' => 'Fix the index', 'state' => null, 'needs_input' => false,
        'last_user_message_at' => null, 'last_run_completed_at' => null, 'seen_at' => now()->toIso8601String(), 'classification' => null],
        'meta' => ['request_id' => $response->json('meta.request_id')]]);
    expect($response->json('meta.request_id'))->toBeString()->not->toBe('');
    // The request reads no Conn Server: it records the row and queues the push's discovery.
    Http::assertNothingSent();
    Queue::assertPushedOn('tasks', DiscoverConnThreadJob::class);
    Queue::assertNotPushed(ClassifyConnThreadJob::class);

    librarianRunPush();
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
        ->assertJsonPath('data.0.classification', $answer);

    // A repeat push, also after someone unfiles the thread, neither asks again nor refiles.
    Queue::releaseUniqueJobLocks();
    $this->thread['orbitProject'] = null;
    librarianPush($this, 'Fix the index again')->assertOk()->assertJsonPath('data.title', 'Fix the index again')
        ->assertJsonPath('data.classification', $answer);
    librarianRunPush();
    librarianRun($job);
    Queue::assertPushedTimes(ClassifyConnThreadJob::class, 1);
    expect(librarianWrites($this->rpc))->toHaveCount(1);
});

it('queues nothing for an ineligible thread', function (Closure $arrange): void {
    librarianHttpFake($this, static fn () => throw new LogicException('The model must not be called.'));
    $arrange->call($this);
    librarianPushed($this);
    Queue::assertNotPushed(ClassifyConnThreadJob::class);
    expect(librarianWrites($this->rpc))->toBeEmpty();
})->with([
    'filed' => [function (): void {
        $this->thread['orbitProject'] = ['id' => 19, 'slug' => 'existing'];
    }],
    'manually unfiled' => [function (): void {
        $this->thread['orbitProject'] = null;
    }],
    'child' => [function (): void {
        $this->thread['lineage'] = ['parentThreadId' => 'parent'];
    }],
    'delegated child' => [function (): void {
        $this->thread['delegatedFrom'] = ['threadId' => 'parent'];
    }],
    'archived' => [function (): void {
        $this->archived = true;
    }],
    'completed receipt' => [function (): void {
        ConnThreadClassification::query()->create(['conn_environment_id' => $this->environment->id, 'thread_id' => 'general-thread',
            'result' => ['result' => 'given_up', 'error_code' => 'conn.ai_router_unavailable'], 'completed_at' => now()]);
    }],
]);

it('refuses a checkout thread once, without asking the server again on the next push', function (): void {
    $this->thread['projectId'] = 'repo-id';
    librarianHttpFake($this, static fn () => throw new LogicException('The model must not be called.'));
    librarianPushed($this);
    librarianPushed($this);
    Queue::assertNotPushed(ClassifyConnThreadJob::class);
    expect(librarianWrites($this->rpc))->toBeEmpty()
        ->and(collect($this->rpc)->where('tag', 'server.getConfig'))->toHaveCount(1)
        ->and(ConnThreadClassification::query()->sole())->completed_at->not->toBeNull()->result->toBeNull();
});

it('sets the host cutoff from the first pushed thread, never from the time of the push', function (): void {
    $this->thread['createdAt'] = now()->subDay()->toIso8601String();
    librarianHttpFake($this, librarianExisting($this));
    librarianPushed($this);
    Queue::assertPushedTimes(ClassifyConnThreadJob::class, 1);
    expect(DB::table('conn_librarian_watermarks')->sole()->started_at_ms)->toBe(now()->subDay()->getTimestampMs());

    // A thread created before the first pushed one is out of scope, and does not move the cutoff.
    $this->thread = [...$this->thread, 'id' => 'older-thread', 'createdAt' => now()->subDays(2)->toIso8601String()];
    librarianPush($this, threadId: 'older-thread')->assertCreated();
    librarianRunPush();
    Queue::assertPushedTimes(ClassifyConnThreadJob::class, 1);
    expect(DB::table('conn_librarian_watermarks')->sole()->started_at_ms)->toBe(now()->subDay()->getTimestampMs());
});

it('queues nothing for a container server', function (): void {
    $instance = Instance::query()->forceCreate(['node_id' => $this->host->id, 'project_id' => $this->project->id, 'name' => 'clone',
        'checkout_path' => '/workspace/clone', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false]);
    $this->environment->forceFill(['instance_id' => $instance->id])->save();
    Http::fake();
    librarianPush($this)->assertCreated()->assertJsonPath('data.instance_id', $instance->id);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('records the thread when the librarian cannot be queued', function (): void {
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('Redis is down.'));
    librarianPush($this)->assertCreated()->assertJsonPath('data.thread_id', 'general-thread');
});

it('refuses the push while Conn is disabled, and a queued push does nothing once Conn is disabled', function (): void {
    librarianHttpFake($this, static fn () => throw new LogicException('The model must not be called.'));
    app(ExtensionStore::class)->set('conn', false);
    librarianPush($this)->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
    Queue::assertNothingPushed();
    app(ExtensionStore::class)->set('conn', true);
    librarianPush($this)->assertCreated();
    app(ExtensionStore::class)->set('conn', false);
    librarianRunPush();
    Queue::assertNotPushed(ClassifyConnThreadJob::class);
    Http::assertNothingSent();
});

it('classifies once when two pushes meet', function (): void {
    $calls = 0;
    librarianHttpFake($this, function () use (&$calls): PromiseInterface {
        $calls++;

        return librarianModelResponse(['result' => 'existing', 'project_id' => $this->project->id, 'reason' => 'Orbit Gateway.']);
    });
    // The unique lock holds the second push off while the first push's job waits.
    librarianPushed($this);
    librarianPushed($this);
    Queue::assertPushedTimes(ClassifyConnThreadJob::class, 1);
    $job = librarianQueuedJob();
    librarianRun($job);
    // Once the job frees its lock, the completed receipt keeps a later push, or a copy that passed the lock, from asking again.
    Queue::releaseUniqueJobLocks();
    librarianPushed($this);
    Queue::assertPushedTimes(ClassifyConnThreadJob::class, 1);
    librarianRun(new ClassifyConnThreadJob($job->environmentId, $job->threadId, $job->connProjectId, $job->connWorkspaceRoot));
    expect($calls)->toBe(1)->and(librarianWrites($this->rpc))->toHaveCount(1);
});

it('leaves Conn unchanged for none and new, and a later push does not ask again', function (array $answer): void {
    librarianHttpFake($this, static fn () => librarianModelResponse($answer));
    librarianPushed($this);
    librarianRun(librarianQueuedJob());
    expect(librarianWrites($this->rpc))->toBeEmpty()->and(Project::query()->count())->toBe(1);
    Queue::releaseUniqueJobLocks();
    librarianPushed($this);
    Queue::assertPushedTimes(ClassifyConnThreadJob::class, 1);
    $this->withServerVariables(['REMOTE_ADDR' => $this->host->wireguard_ip])->getJson('/api/v1/conn/threads')
        ->assertOk()->assertJsonPath('data.0.project_id', null)->assertJsonPath('data.0.classification', $answer);
})->with(['none' => [['result' => 'none']], 'new' => [['result' => 'new', 'name' => 'A New Project', 'slug' => 'a-new-project']]]);

it('does not refile when someone unfiles during the model call', function (): void {
    librarianHttpFake($this, function (): PromiseInterface {
        $this->thread['orbitProject'] = null;

        return librarianModelResponse(['result' => 'existing', 'project_id' => $this->project->id, 'reason' => 'Orbit Gateway.']);
    });
    librarianPushed($this);
    librarianRun(librarianQueuedJob());
    expect(librarianWrites($this->rpc))->toBeEmpty();
});

it('classifies the first user-authored message, also after later messages', function (bool $hasLaterMessage): void {
    if (! $hasLaterMessage) {
        unset($this->messages[2]);
    }
    librarianHttpFake($this, function (Request $request): PromiseInterface {
        expect(json_decode($request['messages'][0]['content'], associative: true)['first_message'])->toBe(mb_substr($this->firstText, 0, 4000));

        return librarianModelResponse(['result' => 'existing', 'project_id' => $this->project->id, 'reason' => 'Orbit Gateway.']);
    });
    librarianPushed($this);
    librarianRun(librarianQueuedJob());
    expect(librarianWrites($this->rpc))->toHaveCount(1);
})->with(['only the first message' => [false], 'a later message arrived' => [true]]);

describe('a first message that is not readable yet', function (): void {
    it('reads again, a few seconds apart, then files the thread without a failure', function (Closure $arrange): void {
        Sleep::fake();
        $messages = $this->messages;
        $arrange->call($this);
        $slept = 0;
        Sleep::whenFakingSleep(function () use ($messages, &$slept): void {
            if (++$slept === 2) {
                $this->messages = $messages;
            }
        });
        librarianHttpFake($this, librarianExisting($this));
        librarianPushed($this);
        librarianRun(librarianQueuedJob());
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(2)->seconds()]);
        expect(librarianWrites($this->rpc))->toHaveCount(1)
            ->and(ConnThreadClassification::query()->sole()->result['result'])->toBe('existing');
    })->with([
        'no user message yet' => [function (): void {
            $this->messages = [$this->messages[0]];
        }],
        'a user message without text yet' => [function (): void {
            $this->messages = [$this->messages[0], ['role' => 'user', 'createdBy' => 'user', 'createdAt' => now()->toIso8601String()]];
        }],
        'no thread snapshot yet' => [function (): void {
            $this->missingReads = 2;
        }],
    ]);

    it('leaves no receipt when it stays unreadable, so the next push classifies it', function (): void {
        Sleep::fake();
        $messages = $this->messages;
        $this->messages = [$messages[0]];
        librarianHttpFake($this, librarianExisting($this));
        librarianPushed($this);
        librarianRun(librarianQueuedJob());
        Sleep::assertSleptTimes(3);
        expect(ConnThreadClassification::query()->count())->toBe(0)->and(librarianWrites($this->rpc))->toBeEmpty();

        Queue::releaseUniqueJobLocks();
        $this->messages = $messages;
        librarianPushed($this);
        librarianRun(Queue::pushed(ClassifyConnThreadJob::class)->last());
        expect(librarianWrites($this->rpc))->toHaveCount(1);
    });

    it('judges each read on its own thread, and never asks the model for one filed during the wait', function (): void {
        Sleep::fake();
        $messages = $this->messages;
        $this->messages = [$messages[0]];
        Sleep::whenFakingSleep(function () use ($messages): void {
            $this->thread['orbitProject'] = ['id' => 19, 'slug' => 'existing'];
            $this->messages = $messages;
        });
        librarianHttpFake($this, static fn () => throw new LogicException('The model must not be called.'));
        librarianPushed($this);
        librarianRun(librarianQueuedJob());
        Sleep::assertSleptTimes(1);
        expect(librarianWrites($this->rpc))->toBeEmpty()
            ->and(ConnThreadClassification::query()->sole())->completed_at->not->toBeNull()->result->toBeNull();
    });
});

it('releases the job for retry on a model failure and invalid JSON without filing', function (): void {
    librarianRealQueue($this);
    $attempt = 0;
    librarianHttpFake($this, function () use (&$attempt): PromiseInterface {
        return match (++$attempt) {
            1 => Http::response(['error' => 'fake outage'], 503),
            2 => Http::response(['content' => [['type' => 'text', 'text' => '```not json```']]]),
            default => librarianModelResponse(['result' => 'existing', 'project_id' => $this->project->id, 'reason' => 'Orbit Gateway.']),
        };
    });
    librarianPush($this)->assertCreated();
    $connection = Queue::connection('gateway');
    $worker = app('queue.worker');
    $worker->process('gateway', $connection->pop('tasks'), new WorkerOptions(maxTries: 1));
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

it('gives up after three failed model attempts and a later push does not requeue the thread', function (): void {
    librarianRealQueue($this);
    $attempt = 0;
    librarianHttpFake($this, static function () use (&$attempt): PromiseInterface {
        return ++$attempt < 3 ? Http::response(['error' => 'fake outage'], 503)
            : Http::response(['content' => [['type' => 'text', 'text' => 'invalid JSON']]]);
    });
    librarianPush($this)->assertCreated();
    $connection = Queue::connection('gateway');
    $worker = app('queue.worker');
    $worker->process('gateway', $connection->pop('tasks'), new WorkerOptions(maxTries: 1));
    foreach ([15, 60, 0] as $delay) {
        $job = $connection->pop('tasks');
        expect($job)->not->toBeNull();
        expect(fn () => $worker->process('gateway', $job, new WorkerOptions(maxTries: 1)))->toThrow(RuntimeException::class, 'Conn librarian');
        $this->travel($delay)->seconds();
    }

    expect($attempt)->toBe(3)->and(DB::table('jobs')->count())->toBe(0)->and(librarianWrites($this->rpc))->toBeEmpty();
    librarianPush($this)->assertOk();
    $worker->process('gateway', $connection->pop('tasks'), new WorkerOptions(maxTries: 1));
    expect(DB::table('jobs')->count())->toBe(0);
    $this->withServerVariables(['REMOTE_ADDR' => $this->host->wireguard_ip])->getJson('/api/v1/conn/threads')
        ->assertOk()->assertJsonPath('data.0.project_id', null)
        ->assertJsonPath('data.0.classification', ['result' => 'given_up', 'error_code' => 'conn.ai_router_invalid_response']);
});

describe('a filing whose reply was lost', function (): void {
    it('records the thread in the index on the retry without filing twice', function (): void {
        $this->dropFilingReplies = 1;
        librarianHttpFake($this, librarianExisting($this));
        librarianPushed($this);
        $job = librarianQueuedJob();
        expect(fn () => librarianRun($job))->toThrow(T3ServerException::class);
        expect(librarianWrites($this->rpc))->toHaveCount(1)->and(ConnThread::hostQuery()->sole()->orbit_project_id)->toBeNull();

        librarianRun($job);
        expect(librarianWrites($this->rpc))->toHaveCount(1)
            ->and(ConnThreadClassification::query()->sole()->completed_at)->not->toBeNull();
        $this->withServerVariables(['REMOTE_ADDR' => $this->host->wireguard_ip])->getJson('/api/v1/conn/threads?project='.$this->project->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.thread_id', 'general-thread')
            ->assertJsonPath('data.0.project_id', $this->project->id);
    });

    it('changes nothing in the index when the thread was refiled or unfiled by hand before the retry', function (mixed $orbitProject): void {
        $this->dropFilingReplies = 1;
        librarianHttpFake($this, librarianExisting($this));
        librarianPushed($this);
        $job = librarianQueuedJob();
        expect(fn () => librarianRun($job))->toThrow(T3ServerException::class);
        $this->thread['orbitProject'] = $orbitProject;

        librarianRun($job);
        expect(librarianWrites($this->rpc))->toHaveCount(1)->and(ConnThread::hostQuery()->sole()->orbit_project_id)->toBeNull()
            ->and(ConnThreadClassification::query()->sole()->completed_at)->not->toBeNull();
    })->with(['filed to another Project' => [['id' => 19, 'slug' => 'existing']], 'unfiled by hand' => [null]]);
});

describe('the host poll, the fallback for a push that never came', function (): void {
    it('indexes a host whose push never came and files its General thread after the first user message', function (): void {
        $calls = 0;
        librarianHttpFake($this, function () use (&$calls): PromiseInterface {
            $calls++;

            return librarianModelResponse(['result' => 'existing', 'project_id' => $this->project->id, 'reason' => 'Orbit Gateway.']);
        });
        $event = collect(app(Schedule::class)->events())->firstWhere('description', PollConnHostThreadsJob::class);
        expect($event->repeatSeconds)->toBe(5);

        $authoredAt = $this->thread['latestUserAuthoredMessageAt'];
        $this->thread['latestUserAuthoredMessageAt'] = null;
        librarianHostPoll();
        Queue::assertNotPushed(ClassifyConnThreadJob::class);
        $this->withServerVariables(['REMOTE_ADDR' => $this->host->wireguard_ip])->getJson('/api/v1/conn/threads')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.thread_id', 'general-thread')
            ->assertJsonPath('data.0.instance_id', null)->assertJsonPath('meta.environments.0.environment_id', 'env-host');

        $this->thread['latestUserAuthoredMessageAt'] = $authoredAt;
        librarianHostPoll();
        librarianRun(librarianQueuedJob());
        expect($calls)->toBe(1)->and(librarianWrites($this->rpc))->toHaveCount(1);
        $this->withServerVariables(['REMOTE_ADDR' => $this->host->wireguard_ip])->getJson('/api/v1/conn/threads?project='.$this->project->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.project_id', $this->project->id);
    });

    it('does not backfill threads created before the host’s first librarian pass', function (): void {
        $this->thread['createdAt'] = now()->subSecond()->toIso8601String();
        librarianHttpFake($this, static fn () => throw new LogicException('The model must not be called.'));
        librarianHostPoll();
        librarianPushed($this);
        Queue::assertNotPushed(ClassifyConnThreadJob::class);
        expect(DB::table('conn_librarian_watermarks')->sole()->started_at_ms)->toBe(now()->getTimestampMs());
    });

    it('classifies a thread once when its push and a poll interleave', function (string $order): void {
        $calls = 0;
        librarianHttpFake($this, function () use (&$calls): PromiseInterface {
            $calls++;

            return librarianModelResponse(['result' => 'existing', 'project_id' => $this->project->id, 'reason' => 'Orbit Gateway.']);
        });
        if ($order === 'push first') {
            librarianPushed($this);
            librarianHostPoll();
        } else {
            librarianHostPoll();
            librarianPushed($this);
        }
        Queue::assertPushedTimes(ClassifyConnThreadJob::class, 1);
        $job = librarianQueuedJob();
        librarianRun($job);
        // After the job frees its lock, the completed receipt keeps the next push, poll or a stray copy from asking again.
        Queue::releaseUniqueJobLocks();
        librarianHostPoll();
        librarianPushed($this);
        librarianRun(new ClassifyConnThreadJob($job->environmentId, $job->threadId, $job->connProjectId, $job->connWorkspaceRoot));
        Queue::assertPushedTimes(ClassifyConnThreadJob::class, 1);
        expect($calls)->toBe(1)->and(librarianWrites($this->rpc))->toHaveCount(1)
            ->and(ConnThread::hostQuery()->sole()->orbit_project_id)->toBe($this->project->id);
    })->with(['push first', 'poll first']);

    it('lists the stored activity of a host row and refreshes it from the shell', function (): void {
        librarianHttpFake($this, static fn () => throw new LogicException('The model must not be called.'));
        ConnThread::hostQuery()->insert(['conn_environment_id' => $this->environment->id, 'thread_id' => 'general-thread', 'title' => 'Old',
            'state' => 'running', 'needs_input' => true, 'last_user_message_at' => now()->subHour(), 'last_run_completed_at' => now()->subMinute(),
            'seen_at' => now()->subMinute()]);
        $list = fn (): TestResponse => $this->withServerVariables(['REMOTE_ADDR' => $this->host->wireguard_ip])->getJson('/api/v1/conn/threads')
            ->assertOk()->assertJsonCount(1, 'data');
        $list()->assertJsonPath('data.0.state', 'running')->assertJsonPath('data.0.needs_input', true)
            ->assertJsonPath('data.0.last_user_message_at', now()->subHour()->toIso8601String())
            ->assertJsonPath('data.0.last_run_completed_at', now()->subMinute()->toIso8601String());

        ConnThreadClassification::query()->create(['conn_environment_id' => $this->environment->id, 'thread_id' => 'general-thread',
            'result' => ['result' => 'none'], 'completed_at' => now()]);
        $this->thread = [...$this->thread, 'status' => 'idle', 'latestRunCompletedAt' => now()->toIso8601String()];
        librarianHostPoll();
        $list()->assertJsonPath('data.0.state', 'idle')->assertJsonPath('data.0.needs_input', false)
            ->assertJsonPath('data.0.last_user_message_at', now()->subSeconds(2)->toIso8601String())
            ->assertJsonPath('data.0.last_run_completed_at', now()->toIso8601String());
    });

    it('follows a manual move or unfiling in Conn without asking the model again', function (): void {
        $calls = 0;
        librarianHttpFake($this, function () use (&$calls): PromiseInterface {
            $calls++;

            return librarianModelResponse(['result' => 'existing', 'project_id' => $this->project->id, 'reason' => 'Orbit Gateway.']);
        });
        $other = Project::query()->create(['name' => 'Other', 'slug' => 'other', 'type' => 'generic']);
        librarianPushed($this);
        librarianRun(librarianQueuedJob());
        $list = fn (int $project): TestResponse => $this->withServerVariables(['REMOTE_ADDR' => $this->host->wireguard_ip])
            ->getJson('/api/v1/conn/threads?project='.$project)->assertOk();
        $list($this->project->id)->assertJsonCount(1, 'data');

        $this->thread['orbitProject'] = ['id' => $other->id, 'slug' => 'other'];
        librarianHostPoll();
        $list($this->project->id)->assertJsonCount(0, 'data');
        $list($other->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.project_id', $other->id);

        unset($this->thread['orbitProject']);
        Queue::releaseUniqueJobLocks();
        librarianHostPoll();
        expect(ConnThread::hostQuery()->sole()->orbit_project_id)->toBeNull();
        Queue::assertPushedTimes(ClassifyConnThreadJob::class, 1);
        expect($calls)->toBe(1)->and(librarianWrites($this->rpc))->toHaveCount(1);
    });
});
