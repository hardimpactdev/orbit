<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceThreadContribution;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Projects\ProjectType;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Data\ConnThreadData;
use Orbit\Extensions\Conn\Domain\ConnInstanceLifecycle;
use Orbit\Extensions\Conn\Domain\ConnThreadIndex;
use Orbit\Extensions\Conn\Domain\T3AdminSession;
use Orbit\Extensions\Conn\Domain\T3EnvironmentDescriptor;
use Orbit\Extensions\Conn\Domain\T3ServerClient;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnThread;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/../../Domain/Instances/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    app(ExtensionStore::class)->set('conn', true);
    config(['orbit.conn.thread_index_poll' => true]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 12:00:00'));
    $this->beast = containerDefaultNode();
    $this->project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $this->phone = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.77', 'wireguard_ip' => '10.44.0.77', 'user' => 'orbit']));
    $this->phone->accessibleNodes()->attach($this->beast->id);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A running container clone of the Project's default with its registered T3 server at `$url`. */
function indexedClone(Node $node, Project $project, string $name, string $url): ConnEnvironment
{
    $default = Instance::query()->where('project_id', $project->id)->where('name', 'default')->first();
    if (! $default instanceof Instance) {
        $default = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
            'checkout_path' => '/workspace/default', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
        app(InstanceContainer::class)->reserve($default, $node)->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    }
    $clone = Instance::query()->forceCreate(['node_id' => $node->id, 'project_id' => $project->id, 'name' => $name,
        'checkout_path' => '/workspace/'.$name, 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($clone, $node, $default)->update(['power_state' => 'running']);
    $environment = ConnEnvironment::query()->create(['environment_id' => 'env-'.$name, 'label' => 'tool / '.$name, 'url' => $url,
        'node_id' => $node->id, 'admin_session' => 'session-'.$name, 'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now()]);
    $environment->forceFill(['instance_id' => $clone->id])->save();

    return $environment;
}

/**
 * A thread of the stock T3 0.1.7 shell, as `GET /api/orchestration/shell` answers it (fields the index does not read are left out).
 *
 * @param  array<string, mixed>  $thread
 * @return array<string, mixed>
 */
function stockThread(string $id, array $thread = []): array
{
    return ['id' => $id, 'title' => 'Thread '.$id, 'status' => 'idle', 'activityRunStatus' => null, 'pendingRuntimeRequest' => null,
        'latestUserMessageAt' => '2026-11-14T11:50:00.000Z', 'latestUserAuthoredMessageAt' => null, 'latestRunCompletedAt' => '2026-11-14T11:51:00.000Z',
        'latestVisibleMessage' => ['text' => 'secret message text'], 'archivedAt' => null, ...$thread];
}

/** @param list<array<string, mixed>> $threads */
function stockShell(array $threads): array
{
    return ['schemaVersion' => 2, 'snapshotSequence' => 7, 'threads' => $threads, 'archivedThreads' => [], 'projects' => []];
}

/** @param array<string, mixed> $body */
function threadsCall(Node $caller, string $method, string $path, array $body = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->json($method, '/api/v1/conn/'.$path, $body);
}

describe('the poll', function (): void {
    it('reads every running server once with its admin session and the protocol header, and the index equals its shell', function (): void {
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        Http::fake(['http://10.44.0.7:3840/api/orchestration/shell' => Http::response(stockShell([
            stockThread('t1', ['status' => 'running', 'activityRunStatus' => 'running', 'latestUserAuthoredMessageAt' => '2026-11-14T11:55:00.000Z']),
            stockThread('t2', ['status' => 'waiting', 'title' => str_repeat('a', 300),
                'pendingRuntimeRequest' => ['id' => 'r', 'kind' => 'approval', 'createdAt' => '2026-11-14T11:58:00.000Z']]),
            stockThread('t3', ['status' => 'waiting', 'pendingRuntimeRequest' => ['id' => 'q', 'kind' => 'question', 'blocking' => false]]),
        ]))]);

        $results = app(ConnThreadIndex::class)->pass();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer session-clone')
            && $request->hasHeader('x-t3-orchestration-protocol', '2'));
        expect($results)->toHaveCount(1)->and($results[0])->toMatchArray(['environment_id' => 'env-clone', 'outcome' => 'read', 'threads' => 3])
            ->and(ConnThread::query()->orderBy('thread_id')->get()->map(fn (ConnThread $thread): array => [$thread->thread_id, mb_strlen($thread->title),
                $thread->state, $thread->needs_input, $thread->last_user_message_at?->toIso8601String(), $thread->seen_at->toIso8601String()])->all())
            ->toBe([
                ['t1', 9, 'running', false, '2026-11-14T11:55:00+00:00', '2026-11-14T12:00:00+00:00'],
                ['t2', 255, 'waiting', true, '2026-11-14T11:50:00+00:00', '2026-11-14T12:00:00+00:00'],
                ['t3', 9, 'waiting', false, '2026-11-14T11:50:00+00:00', '2026-11-14T12:00:00+00:00'],
            ])
            ->and($tool->refresh()->threads_read_at?->toIso8601String())->toBe('2026-11-14T12:00:00+00:00')
            ->and(json_encode(ConnThread::query()->get()))->not->toContain('secret message text');
    });

    it('drops a thread that left the shell, but keeps one written through after the read began', function (): void {
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        ConnThread::query()->create(['conn_environment_id' => $tool->id, 'instance_id' => $tool->instance_id, 'thread_id' => 'gone', 'title' => 'Gone',
            'seen_at' => now()->subMinute()]);
        Http::fake(['*' => function () use ($tool): mixed {
            // Conn creates a thread while the read is on its way: the shell does not list it yet.
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 12:00:01'));
            app(ConnThreadIndex::class)->record($tool, 'new', 'New thread');

            return Http::response(stockShell([stockThread('kept')]));
        }]);

        app(ConnThreadIndex::class)->pass();

        expect(ConnThread::query()->orderBy('thread_id')->pluck('thread_id')->all())->toBe(['kept', 'new']);
    });

    it('skips a server that does not answer, refuses or answers nonsense, with its reason, keeps its rows, and still reads the others', function (string $case, string $reason): void {
        $broken = indexedClone($this->beast, $this->project, 'broken', 'http://10.44.0.7:3841');
        $fine = indexedClone($this->beast, $this->project, 'fine', 'http://10.44.0.7:3842');
        ConnThread::query()->create(['conn_environment_id' => $broken->id, 'instance_id' => $broken->instance_id, 'thread_id' => 'old', 'title' => 'Old',
            'state' => 'idle', 'seen_at' => now()->subHour()]);
        Http::fake([
            'http://10.44.0.7:3841/*' => match ($case) {
                'unreachable' => fn () => throw new ConnectionException('timed out'),
                'refused' => Http::response(['reason' => 'invalid_credential'], 401),
                'malformed' => Http::response(['threads' => [['id' => 'x']]]),
            },
            'http://10.44.0.7:3842/*' => Http::response(stockShell([stockThread('f1')])),
        ]);

        $results = collect(app(ConnThreadIndex::class)->pass())->keyBy('environment_id');

        expect($results['env-broken'])->toMatchArray(['outcome' => 'skipped', 'reason' => $reason, 'threads' => 1])
            ->and($results['env-fine'])->toMatchArray(['outcome' => 'read', 'threads' => 1])
            ->and(ConnThread::query()->where('conn_environment_id', $broken->id)->sole()->seen_at->toIso8601String())->toBe('2026-11-14T11:00:00+00:00')
            ->and($broken->refresh()->threads_read_error)->toBe($reason);
    })->with([
        ['unreachable', 'conn.server_unreachable'], ['refused', 'conn.session_rejected'], ['malformed', 'conn.response_invalid'],
    ]);

    it('does not read an asleep or stopped Instance, nor a server whose session ended, and keeps their rows with their seen_at', function (): void {
        $asleep = indexedClone($this->beast, $this->project, 'asleep', 'http://10.44.0.7:3841');
        $asleep->instance->runtime()->update(['power_state' => 'stopped']);
        $expired = indexedClone($this->beast, $this->project, 'expired', 'http://10.44.0.7:3842');
        $expired->forceFill(['admin_session_expires_at' => now()->subMinute()])->save();
        foreach ([$asleep, $expired] as $environment) {
            ConnThread::query()->create(['conn_environment_id' => $environment->id, 'instance_id' => $environment->instance_id, 'thread_id' => 'old',
                'title' => 'Old', 'seen_at' => now()->subHour()]);
        }
        Http::fake();

        $results = collect(app(ConnThreadIndex::class)->pass())->keyBy('environment_id');

        Http::assertNothingSent();
        expect($results['env-asleep']['reason'])->toBe('conn.environment_asleep')->and($results['env-expired']['reason'])->toBe('conn.session_expired')
            ->and(ConnThread::query()->pluck('seen_at')->map->toIso8601String()->all())->toBe(['2026-11-14T11:00:00+00:00', '2026-11-14T11:00:00+00:00']);
    });

    it('reads nothing while the switch is off: the command, the list and the last read before a sleep', function (): void {
        config(['orbit.conn.thread_index_poll' => false]);
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        Http::fake();

        $this->artisan('conn:threads:read')->expectsOutputToContain('ORBIT_CONN_THREAD_INDEX_POLL is off')->assertSuccessful();
        threadsCall($this->phone, 'GET', 'threads')->assertOk()->assertJsonPath('meta.environments.0.read_at', null);
        app(ConnThreadIndex::class)->beforeSleep($tool->instance);

        Http::assertNothingSent();
    });

    it('drops a read that began before the server\'s last recorded read, so it never brings back a removed thread', function (): void {
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        Http::fake(['*' => function () use ($tool): mixed {
            // While this read is on its way, a newer read ends: it removed `gone` and keeps `kept`.
            $tool->forceFill(['threads_read_at' => now()->addSeconds(5)])->save();
            ConnThread::query()->create(['conn_environment_id' => $tool->id, 'instance_id' => $tool->instance_id, 'thread_id' => 'kept', 'title' => 'Kept',
                'seen_at' => now()->addSeconds(5)]);

            return Http::response(stockShell([stockThread('gone'), stockThread('kept')]));
        }]);

        $results = app(ConnThreadIndex::class)->pass();

        expect($results[0]['outcome'])->toBe('superseded')->and(ConnThread::query()->pluck('thread_id')->all())->toBe(['kept']);
    });

    it('skips a server whose write fails, reports it, and still writes the others', function (): void {
        Exceptions::fake();
        $broken = indexedClone($this->beast, $this->project, 'broken', 'http://10.44.0.7:3841');
        indexedClone($this->beast, $this->project, 'fine', 'http://10.44.0.7:3842');
        ConnEnvironment::updating(function (ConnEnvironment $environment) use ($broken): void {
            if ($environment->id === $broken->id) {
                throw new RuntimeException('database is locked');
            }
        });
        Http::fake(['*' => Http::response(stockShell([stockThread('t1')]))]);

        $results = collect(app(ConnThreadIndex::class)->pass())->keyBy('environment_id');

        expect($results['env-broken'])->toMatchArray(['outcome' => 'skipped', 'reason' => 'conn.index_write_failed'])
            ->and($results['env-fine']['outcome'])->toBe('read')
            ->and(ConnThread::query()->where('conn_environment_id', $broken->id)->count())->toBe(0);
        // A busy SQLite says "database is locked"; the transaction reports it as a deadlock.
        Exceptions::assertReported(DeadlockException::class);
    });

    it('writes a shell of 3,000 threads, more than one statement may bind', function (): void {
        indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        Http::fake(['*' => Http::response(stockShell(array_map(fn (int $i): array => stockThread('t'.$i), range(1, 3000))))]);

        expect(app(ConnThreadIndex::class)->pass()[0]['outcome'])->toBe('read')->and(ConnThread::query()->count())->toBe(3000);
    });

    it('is scheduled every minute on the problems queue only while the switch is on and Conn is enabled', function (bool $switch, bool $conn, bool $due): void {
        config(['orbit.conn.thread_index_poll' => $switch]);
        app(ExtensionStore::class)->set('conn', $conn);
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'conn:threads:read'));

        expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')
            ->and($event->filtersPass(app()))->toBe($due);
    })->with([[true, true, true], [false, true, false], [true, false, false]]);
});

describe('the list', function (): void {
    it('lists the rows of the servers the caller reaches, by Project or Instance, with each server\'s last read', function (): void {
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        $mini = containerDefaultNode('mini', '10.44.0.9');
        $other = Project::query()->create(['name' => 'other', 'slug' => 'other', 'repository_url' => 'https://github.com/nckrtl/other.git', 'default_branch' => 'main', 'root' => 'public']);
        $hidden = indexedClone($mini, $other, 'hidden', 'http://10.44.0.9:3840');
        foreach ([$tool, $hidden] as $environment) {
            ConnThread::query()->create(['conn_environment_id' => $environment->id, 'instance_id' => $environment->instance_id, 'thread_id' => 'on-'.$environment->environment_id,
                'title' => 'A thread', 'state' => 'idle', 'seen_at' => now()->subSeconds(5)]);
            $environment->forceFill(['threads_read_at' => now()->subSeconds(5)])->save();
        }

        $all = threadsCall($this->phone, 'GET', 'threads')->assertOk();
        expect($all->json('data'))->toBe([[
            'environment_id' => 'env-clone', 'instance_id' => $tool->instance_id, 'project_id' => $this->project->id, 'thread_id' => 'on-env-clone',
            'title' => 'A thread', 'state' => 'idle', 'needs_input' => false, 'last_user_message_at' => null, 'last_run_completed_at' => null,
            'seen_at' => '2026-11-14T11:59:55+00:00',
        ]])->and($all->json('meta.environments'))->toBe([[
            'environment_id' => 'env-clone', 'instance_id' => $tool->instance_id, 'power_state' => 'running', 'read_at' => '2026-11-14T11:59:55+00:00', 'read_error' => null,
        ]]);
        expect(threadsCall($this->phone, 'GET', 'threads?project='.$other->id)->assertOk()->json('data'))->toBe([])
            ->and(threadsCall($this->phone, 'GET', 'threads?instance='.$tool->instance_id)->assertOk()->json('data.0.thread_id'))->toBe('on-env-clone')
            ->and(threadsCall($this->phone, 'GET', 'threads?project='.$this->project->id)->assertOk()->json('data.0.thread_id'))->toBe('on-env-clone');
        threadsCall($this->phone, 'GET', 'threads?project=abc')->assertStatus(422);
        Http::assertNothingSent();
    });

    it('reads through only the one Instance it names, when its last read is older than ten seconds and it runs', function (): void {
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        $asleep = indexedClone($this->beast, $this->project, 'asleep', 'http://10.44.0.7:3841');
        $asleep->instance->runtime()->update(['power_state' => 'stopped']);
        $tool->forceFill(['threads_read_at' => now()->subSeconds(11)])->save();
        Http::fake(['http://10.44.0.7:3840/*' => Http::response(stockShell([stockThread('fresh')]))]);

        // A Project's list and the full list read only the index, however old its rows are.
        threadsCall($this->phone, 'GET', 'threads')->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('meta.environments.0.read_at', '2026-11-14T11:59:49+00:00');
        threadsCall($this->phone, 'GET', 'threads?project='.$this->project->id)->assertOk()->assertJsonPath('data', []);
        threadsCall($this->phone, 'GET', 'threads?instance='.$asleep->instance_id)->assertOk();
        Http::assertNothingSent();

        $response = threadsCall($this->phone, 'GET', 'threads?instance='.$tool->instance_id)->assertOk();

        Http::assertSentCount(1);
        expect($response->json('data.0.thread_id'))->toBe('fresh')->and($response->json('meta.environments.0.read_at'))->toBe('2026-11-14T12:00:00+00:00');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 12:00:09'));
        threadsCall($this->phone, 'GET', 'threads?instance='.$tool->instance_id)->assertOk();
        Http::assertSentCount(1);
    });

    it('lists rows built from the table exactly as the thread record shapes them', function (): void {
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        ConnThread::query()->create(['conn_environment_id' => $tool->id, 'instance_id' => $tool->instance_id, 'thread_id' => 't1', 'title' => 'One',
            'state' => 'waiting', 'needs_input' => true, 'last_user_message_at' => now()->subMinute(), 'seen_at' => now()]);
        ConnThread::query()->create(['conn_environment_id' => $tool->id, 'instance_id' => $tool->instance_id, 'thread_id' => 't2', 'title' => 'Two', 'seen_at' => now()]);

        $listed = threadsCall($this->phone, 'GET', 'threads')->assertOk()->json('data');

        expect($listed)->toBe(ConnThread::query()->orderBy('id')->get()->map(fn (ConnThread $thread): array => ConnThreadData::fromModel($thread)->toArray())->all());
    });
});

describe('the write-through', function (): void {
    it('records a thread Conn started at once, updates it on a repeat, and lists it', function (): void {
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        $tool->forceFill(['threads_read_at' => now()])->save();

        threadsCall($this->phone, 'POST', 'environments/env-clone/threads', ['thread_id' => 'conn-1', 'title' => 'Fix the login'])->assertCreated()
            ->assertJsonPath('data.thread_id', 'conn-1')->assertJsonPath('data.state', null)->assertJsonPath('data.seen_at', '2026-11-14T12:00:00+00:00');
        threadsCall($this->phone, 'POST', 'environments/env-clone/threads', ['thread_id' => 'conn-1', 'title' => 'Fix the login form'])->assertOk();

        expect(threadsCall($this->phone, 'GET', 'threads')->json('data.*.title'))->toBe(['Fix the login form'])
            ->and(json_encode(Activity::query()->where('description', 'conn:thread:record')->pluck('properties')->all()))->toContain('conn-1')->not->toContain('Fix the login');
        Http::assertNothingSent();
    });

    it('cuts a long title to 255 characters and keeps the state a read wrote', function (): void {
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        ConnThread::query()->create(['conn_environment_id' => $tool->id, 'instance_id' => $tool->instance_id, 'thread_id' => 'conn-1', 'title' => 'Old',
            'state' => 'running', 'seen_at' => now()->subMinute()]);

        threadsCall($this->phone, 'POST', 'environments/env-clone/threads', ['thread_id' => 'conn-1', 'title' => str_repeat('x', 5000)])->assertOk()
            ->assertJsonPath('data.state', 'running')->assertJsonPath('data.title', str_repeat('x', 255));
    });

    it('reports a failed write of a task copy\'s thread and never fails the turn', function (): void {
        Exceptions::fake();
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        Schema::drop('conn_threads');

        app(ConnThreadIndex::class)->recordThread($tool->instance, 'mcp:1', 'Orbit task 5 thread 9');

        Exceptions::assertReported(QueryException::class);
    });

    it('refuses a caller without node access, a host server and a missing body', function (): void {
        indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        $stranger = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'stranger', 'status' => 'active', 'platform' => 'linux',
            'public_ssh_host' => '10.44.0.78', 'wireguard_ip' => '10.44.0.78', 'user' => 'orbit']));
        ConnEnvironment::query()->create(['environment_id' => 'env-host', 'label' => 'beast', 'url' => 'http://10.44.0.7:3773', 'node_id' => $this->beast->id,
            'admin_session' => 'host', 'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now()]);

        threadsCall($stranger, 'POST', 'environments/env-clone/threads', ['thread_id' => 'x', 'title' => 'x'])->assertForbidden();
        threadsCall($this->phone, 'POST', 'environments/env-host/threads', ['thread_id' => 'x', 'title' => 'x'])->assertStatus(409)
            ->assertJsonPath('error.code', 'conn.thread_index_unsupported');
        threadsCall($this->phone, 'POST', 'environments/env-clone/threads', ['title' => 'x'])->assertStatus(422);
        expect(ConnThread::query()->count())->toBe(0);
    });

    it('records the thread of a task copy turn through the plug-in point, but not while Conn is off', function (): void {
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        $contribution = collect(app()->tagged(InstanceThreadContribution::class))->first(fn ($c): bool => $c instanceof ConnThreadIndex);

        $contribution->recordThread($tool->instance, 'mcp:1', 'Orbit task 5 thread 9');
        app(ExtensionStore::class)->set('conn', false);
        $contribution->recordThread($tool->instance, 'mcp:2', 'Orbit task 5 thread 10');

        expect(ConnThread::query()->pluck('title', 'thread_id')->all())->toBe(['mcp:1' => 'Orbit task 5 thread 9']);
    });
});

describe('sleep and destroy', function (): void {
    it('reads the server one last time before a sleep is admitted', function (): void {
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        Http::fake(['http://10.44.0.7:3840/*' => function () {
            expect(Operation::query()->where('kind', InstanceSleep::Kind)->count())->toBe(0);

            return Http::response(stockShell([stockThread('last')]));
        }]);

        app(InstanceSleep::class)->start($tool->instance, null, null);

        Http::assertSentCount(1);
        expect(ConnThread::query()->sole()->thread_id)->toBe('last')
            ->and(Operation::query()->where('kind', InstanceSleep::Kind)->count())->toBe(1);
    });

    it('bounds the last read to three seconds, and a failed read never stops the sleep', function (): void {
        Exceptions::fake();
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        $client = new class implements T3ServerClient
        {
            public ?float $timeout = null;

            public function describe(string $baseUrl): T3EnvironmentDescriptor
            {
                throw new LogicException('unused');
            }

            public function session(string $baseUrl, string $adminSession): T3AdminSession
            {
                throw new LogicException('unused');
            }

            public function shell(string $baseUrl, string $adminSession): array
            {
                throw new LogicException('unused');
            }

            public function shells(array $servers, float $timeoutSeconds): array
            {
                $this->timeout = $timeoutSeconds;

                throw new RuntimeException('the pool broke');
            }
        };
        app()->instance(T3ServerClient::class, $client);

        expect(app(InstanceSleep::class)->start($tool->instance, null, null)->kind)->toBe(InstanceSleep::Kind)
            ->and($client->timeout)->toBe(3.0);
        Exceptions::assertReported(RuntimeException::class);
    });

    it('drops the rows with the Instance\'s server when the Instance is destroyed', function (): void {
        $tool = indexedClone($this->beast, $this->project, 'clone', 'http://10.44.0.7:3840');
        app(ConnThreadIndex::class)->record($tool, 't1', 'One');

        app(ConnInstanceLifecycle::class)->projectDestroy($tool->instance);

        expect(ConnThread::query()->count())->toBe(0);
    });
});
