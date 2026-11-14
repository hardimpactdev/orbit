<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Actions\AdmitConnEnvironmentRestartAction;
use Orbit\Extensions\Conn\Domain\ConnEnvironmentRestart;
use Orbit\Extensions\Conn\Domain\ConnEnvironmentRestartProjection;
use Orbit\Extensions\Conn\Domain\T3ServerClient;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

uses(LazilyRefreshDatabase::class);

afterEach(fn () => Carbon\Carbon::setTestNow());
require_once __DIR__.'/../../Domain/Instances/ContainerInstanceHelpers.php';

const RestartRun = '6a0f3c2e-1b4d-4e8a-9c7f-5d2e8b1a0c43';

beforeEach(function (): void {
    Queue::fake();
    Carbon\Carbon::setTestNow('2026-11-14 12:00:00.250');
    Http::preventStrayRequests();
    app(ExtensionStore::class)->set('conn', true);
    $this->node = containerDefaultNode();
    $this->project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $this->project->forceFill(['slug' => 'shop'])->save();
    $this->default = Instance::query()->create(['node_id' => $this->node->id, 'project_id' => $this->project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($this->default, $this->node)->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
});

/** A running container Instance with its T3 server recorded in the Conn layer at `http://10.44.0.7:<port>`. */
function restartServer(Instance $default, string $name, int $port, bool $environment = true): Instance
{
    $instance = Instance::query()->forceCreate(['node_id' => $default->node_id, 'project_id' => $default->project_id, 'name' => $name,
        'checkout_path' => '/workspace/'.$name, 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($instance, $default->node, $default)->update(['power_state' => 'running']);
    if ($environment) {
        $record = ConnEnvironment::query()->create(['environment_id' => 'env-'.$name, 'label' => 'shop / '.$name, 'url' => 'http://10.44.0.7:'.$port,
            'node_id' => $default->node_id, 'admin_session' => 'gateway-session-'.$name, 'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now()->subDays(1)]);
        $record->forceFill(['instance_id' => $instance->id])->save();
    }

    return $instance;
}

/** @param list<array<string, mixed>> $threads */
function restartT3(int $port, string $label, array $threads = [], mixed $shell = null): array
{
    return [
        'http://10.44.0.7:'.$port.'/.well-known/t3/environment' => Http::response(['environmentId' => 'env', 'label' => $label, 'serverVersion' => '0.1.7']),
        'http://10.44.0.7:'.$port.'/api/orchestration/shell' => $shell ?? Http::response(['schemaVersion' => 2, 'threads' => $threads, 'archivedThreads' => [], 'projects' => []]),
    ];
}

/** @param array<string, mixed> $thread */
function restartThread(array $thread = []): array
{
    return ['id' => 't1', 'status' => 'completed', 'activeRunId' => null, 'pendingRuntimeRequest' => null, 'latestUserMessageAt' => null, 'latestRunCompletedAt' => null, ...$thread];
}

function restartOne(Instance $instance): array
{
    return app(ConnEnvironmentRestart::class)->instance($instance->refresh(), 'node:1', 'request-1')->toArray();
}

it('answers current when T3 already shows the machine name, and reads the label with no session', function (): void {
    $clone = restartServer($this->default, 'clone', 3840);
    Http::fake(restartT3(3840, 'clone.shop'));

    expect(restartOne($clone))->toMatchArray(['answer' => 'current', 'reason' => null, 'operation_id' => null])
        ->and(Operation::query()->where('kind', AdmitConnEnvironmentRestartAction::Kind)->count())->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.44.0.7:3840/.well-known/t3/environment' && ! $request->hasHeader('Authorization'));
});

it('answers busy and restarts nothing while a run works or any runtime request is pending', function (array $thread): void {
    $clone = restartServer($this->default, 'clone', 3840);
    Http::fake(restartT3(3840, 'instance-'.$clone->id, [restartThread($thread)]));

    expect(restartOne($clone))->toMatchArray(['answer' => 'busy', 'reason' => 't3_working', 'operation_id' => null])
        ->and(Operation::query()->where('kind', AdmitConnEnvironmentRestartAction::Kind)->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/orchestration/shell')
        && $request->hasHeader('Authorization', 'Bearer gateway-session-clone') && $request->hasHeader('x-t3-orchestration-protocol', '2'));
})->with([
    'a running turn' => [['status' => 'running']],
    'a non-blocking request' => [['status' => 'completed', 'pendingRuntimeRequest' => ['id' => 'r', 'kind' => 'question', 'createdAt' => '2026-11-14T08:00:00.000Z', 'blocking' => false]]],
    'a blocking request' => [['status' => 'waiting', 'pendingRuntimeRequest' => ['id' => 'r', 'kind' => 'approval', 'createdAt' => '2026-11-14T08:00:00.000Z', 'blocking' => true]]],
]);

it('skips a sleeping container as frozen or stopped by its last power Operation, and reads no T3', function (?string $sleep, string $reason): void {
    $clone = restartServer($this->default, 'clone', 3840);
    InstanceRuntime::query()->where('instance_id', $clone->id)->update(['power_state' => 'stopped']);
    if ($sleep !== null) {
        Operation::query()->forceCreate(['kind' => InstanceSleep::Kind, 'target_type' => 'instance', 'target_id' => $clone->id, 'node_id' => $clone->node_id,
            'input' => [], 'state' => [], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1, 'result' => ['sleep' => $sleep],
            'finished_at' => now()->subHour()]);
    }
    Http::fake();

    expect(restartOne($clone))->toMatchArray(['answer' => 'skipped', 'reason' => $reason]);
    Http::assertNothingSent();
})->with(['frozen by a sleep' => ['frozen', 'frozen'], 'stopped by a full swap' => ['stopped', 'stopped'], 'no sleep recorded' => [null, 'stopped']]);

it('skips an Instance without a Conn server row as no_t3', function (): void {
    $clone = restartServer($this->default, 'clone', 3840, environment: false);
    Http::fake();

    expect(restartOne($clone))->toMatchArray(['answer' => 'skipped', 'reason' => 'no_t3']);
    Http::assertNothingSent();
});

it('skips a T3 whose label or threads cannot be read as t3_unreadable', function (string $case): void {
    $clone = restartServer($this->default, 'clone', 3840);
    Http::fake(match ($case) {
        'label unreachable' => ['*' => fn () => throw new ConnectionException('down')],
        'shell refused' => restartT3(3840, 'instance-'.$clone->id, shell: Http::response(['reason' => 'invalid_credential'], 401)),
        'shell malformed' => restartT3(3840, 'instance-'.$clone->id, shell: Http::response(['projects' => []])),
    });

    expect(restartOne($clone))->toMatchArray(['answer' => 'skipped', 'reason' => 't3_unreadable'])
        ->and(Operation::query()->where('kind', AdmitConnEnvironmentRestartAction::Kind)->count())->toBe(0);
})->with(['label unreachable', 'shell refused', 'shell malformed']);

it('admits one restart on the Instance lock with an intent that holds no session', function (): void {
    $clone = restartServer($this->default, 'clone', 3840);
    $runtime = InstanceRuntime::query()->where('instance_id', $clone->id)->firstOrFail();
    $environment = ConnEnvironment::query()->where('instance_id', $clone->id)->firstOrFail();
    Http::fake(restartT3(3840, 'instance-'.$clone->id, [restartThread(['status' => 'completed', 'latestRunCompletedAt' => '2026-11-14T08:00:00.000Z'])]));

    $answer = restartOne($clone);

    $operation = Operation::query()->where('kind', AdmitConnEnvironmentRestartAction::Kind)->sole();
    expect($answer)->toMatchArray(['answer' => 'restarting', 'reason' => null])
        ->and($answer['operation_id'])->toBe($operation->id)
        ->and($operation->target_type)->toBe('instance')->and($operation->target_id)->toBe($clone->id)
        ->and($operation->node_id)->toBe($runtime->node_id)
        ->and($operation->lock_key)->toBe('instance:'.$clone->id)
        ->and($operation->triggered_by)->toBe('node:1')
        ->and($operation->input)->toBe(['instance_id' => $clone->id, 'conn_environment_id' => $environment->id,
            'intent' => ['instance_id' => $clone->id, 'url' => 'http://10.44.0.7:3840', 'name' => 'clone.shop', 'read_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'container' => ['project' => $runtime->incus_project, 'name' => $runtime->container_name]]])
        ->and(json_encode($operation->input))->not->toContain('gateway-session');
});

it('answers busy with the refusal when other work holds the Instance', function (): void {
    $clone = restartServer($this->default, 'clone', 3840);
    Http::fake(restartT3(3840, 'instance-'.$clone->id));
    app(InstanceSleep::class)->start($clone, null, null);

    expect(restartOne($clone))->toMatchArray(['answer' => 'busy', 'reason' => 'operation.target_busy']);
});

it('walks a Node by Instance ID, reads every label at once and each container\'s threads right before its own admission', function (): void {
    $first = restartServer($this->default, 'first', 3841);
    $second = restartServer($this->default, 'second', 3842);
    $events = [];
    Operation::created(function (Operation $operation) use (&$events): void {
        $events[] = 'admit:'.$operation->target_id;
    });
    $routes = [...restartT3(3841, 'instance-'.$first->id), ...restartT3(3842, 'instance-'.$second->id),
        ...restartT3(3840, 'default.shop')];
    // The default's server is not recorded in this fixture: it answers no_t3 and is never read.
    Http::fake(function (Request $request) use (&$events, $routes) {
        $events[] = 'read:'.parse_url($request->url(), PHP_URL_PORT).parse_url($request->url(), PHP_URL_PATH);

        return $routes[$request->url()];
    });

    $answers = array_map(static fn ($decision): array => $decision->toArray(), app(ConnEnvironmentRestart::class)->node(Node::query()->findOrFail($first->node_id), null, null));

    expect(array_column($answers, 'instance_id'))->toBe([$this->default->id, $first->id, $second->id])
        ->and(array_column($answers, 'answer'))->toBe(['skipped', 'restarting', 'restarting'])
        ->and($events)->toBe([
            // Every label at once, then each Instance's threads right before its own admission.
            'read:3841/.well-known/t3/environment', 'read:3842/.well-known/t3/environment',
            'read:3841/api/orchestration/shell', 'admit:'.$first->id, 'read:3842/api/orchestration/shell', 'admit:'.$second->id,
        ]);
});

it('records a run that ends with T3 inactive as failed with its code, and a good report as restarted', function (string $status, array $report, array|string $result, ?string $code): void {
    $clone = restartServer($this->default, 'clone', 3840);
    Http::fake(restartT3(3840, 'instance-'.$clone->id));
    $operation = Operation::query()->findOrFail(restartOne($clone)['operation_id']);
    $operation->forceFill(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => RestartRun]])->save();

    $projected = app(ConnEnvironmentRestartProjection::class)->apply($operation, ['run_id' => RestartRun, 'status' => $status,
        'started_at' => now()->subSeconds(20)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'error_code' => $status === 'succeeded' ? null : 'operation.native_failed', 'result' => $report]);

    $read = $operation->input['intent']['read_at'];
    $result = $result === 'window' ? ['answer' => 'restarted', 'read_at' => $read, 'restart_at' => '2026-11-14T12:00:07.500000Z',
        'window_ms' => (int) round(CarbonImmutable::parse($read)->diffInMilliseconds(CarbonImmutable::parse('2026-11-14T12:00:07.500000Z'), false))] : $result;
    expect($projected->status->value)->toBe($status)->and($projected->result)->toBe($result)->and($projected->error_code)->toBe($code);
})->with([
    'T3 inactive' => ['failed', ['answer' => 'failed', 'error_code' => 'conn.t3_inactive'], ['answer' => 'failed', 'error_code' => 'conn.t3_inactive'], 'conn.t3_inactive'],
    'no code of its own' => ['failed', [], ['answer' => 'failed', 'error_code' => 'conn.t3_restart_failed'], 'conn.t3_restart_failed'],
    'restarted, with its window from the read' => ['succeeded', ['answer' => 'restarted', 'restart_at' => '2026-11-14T12:00:07.500000Z'], 'window', null],
    'interrupted' => ['interrupted', [], ['answer' => 'failed', 'error_code' => 'conn.t3_restart_failed'], 'conn.t3_restart_failed'],
    'stopped after the read' => ['succeeded', ['answer' => 'skipped', 'reason' => 'stopped'], ['answer' => 'skipped', 'reason' => 'stopped'], null],
]);

it('skips a runtime without a T3 port as no_t3, even with a Conn server row', function (): void {
    InstanceRuntime::query()->where('instance_id', $this->default->id)->update(['t3_port' => null]);
    $record = ConnEnvironment::query()->create(['environment_id' => 'env-default', 'label' => 'shop / default', 'url' => 'http://10.44.0.7:3800',
        'node_id' => $this->node->id, 'admin_session' => 'gateway-session-default', 'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now()]);
    $record->forceFill(['instance_id' => $this->default->id])->save();
    Http::fake();

    expect(restartOne($this->default))->toMatchArray(['answer' => 'skipped', 'reason' => 'no_t3']);
    Http::assertNothingSent();
});

it('refuses a malformed restarted report as an invalid result', function (array $report): void {
    $clone = restartServer($this->default, 'clone', 3840);
    Http::fake(restartT3(3840, 'instance-'.$clone->id));
    $operation = Operation::query()->findOrFail(restartOne($clone)['operation_id']);
    $operation->forceFill(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => RestartRun]])->save();

    expect(fn () => app(ConnEnvironmentRestartProjection::class)->apply($operation, ['run_id' => RestartRun, 'status' => 'succeeded',
        'started_at' => now()->subSeconds(20)->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null, 'result' => $report]))
        ->toThrow(ResourceOperationException::class, 'restart report');
})->with(['no answer' => [[]], 'restarted without its time' => [['answer' => 'restarted']], 'skipped without a reason' => [['answer' => 'skipped']]]);

it('answers busy with instance.lifecycle_not_active for an Instance that is not active', function (): void {
    $clone = restartServer($this->default, 'clone', 3840);
    $clone->forceFill(['status' => 'source_resolved'])->save();
    Http::fake(restartT3(3840, 'instance-'.$clone->id));

    expect(restartOne($clone))->toMatchArray(['answer' => 'busy', 'reason' => 'instance.lifecycle_not_active']);
});

it('answers busy while another Conn Operation holds the server', function (): void {
    $clone = restartServer($this->default, 'clone', 3840);
    $environment = ConnEnvironment::query()->where('instance_id', $clone->id)->firstOrFail();
    Operation::query()->forceCreate(['kind' => 'conn.environment_renew', 'target_type' => 'conn_environment', 'target_id' => $environment->id, 'node_id' => $clone->node_id,
        'input' => [], 'state' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1, 'lock_key' => 'conn_environment:'.$environment->id]);
    Http::fake(restartT3(3840, 'instance-'.$clone->id));

    expect(restartOne($clone))->toMatchArray(['answer' => 'busy', 'reason' => 'conn_operation'])
        ->and(Operation::query()->where('kind', AdmitConnEnvironmentRestartAction::Kind)->count())->toBe(0);
});

it('answers skipped with deadline for the Instances left when the walk passes its deadline', function (): void {
    $first = restartServer($this->default, 'first', 3841);
    $second = restartServer($this->default, 'second', 3842);
    $routes = [...restartT3(3841, 'instance-'.$first->id), ...restartT3(3842, 'instance-'.$second->id)];
    // The first container's thread read takes the walk past its deadline.
    Http::fake(function (Request $request) use ($routes) {
        if (str_ends_with($request->url(), ':3841/api/orchestration/shell')) {
            Carbon\Carbon::setTestNow(now()->addSeconds(121));
        }

        return $routes[$request->url()];
    });

    $answers = array_map(static fn ($decision): array => $decision->toArray(), app(ConnEnvironmentRestart::class)->node(Node::query()->findOrFail($first->node_id), null, null));

    expect(array_column($answers, 'answer', 'instance_id'))->toBe([$this->default->id => 'skipped', $first->id => 'restarting', $second->id => 'skipped'])
        ->and($answers[2]['reason'])->toBe('deadline');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), ':3842/api/orchestration/shell'));
});

it('admits at most a few restarts per call; the rest answer next_run', function (): void {
    $instances = [restartServer($this->default, 'first', 3841), restartServer($this->default, 'second', 3842), restartServer($this->default, 'third', 3843)];
    $routes = [];
    foreach ($instances as $index => $instance) {
        $routes = [...$routes, ...restartT3(3841 + $index, 'instance-'.$instance->id)];
    }
    Http::fake($routes);
    $walk = new ConnEnvironmentRestart(app(T3ServerClient::class), app(AdmitConnEnvironmentRestartAction::class), restartsPerCall: 2);

    $answers = array_map(static fn ($decision): array => $decision->toArray(), $walk->node(Node::query()->findOrFail($this->node->id), null, null));

    expect(array_column($answers, 'answer'))->toBe(['skipped', 'restarting', 'restarting', 'skipped'])->and($answers[3]['reason'])->toBe('next_run');
});

it('answers failed for an Instance whose read throws unexpectedly, and goes on with the next', function (): void {
    $first = restartServer($this->default, 'first', 3841);
    $second = restartServer($this->default, 'second', 3842);
    $routes = [...restartT3(3841, 'instance-'.$first->id), ...restartT3(3842, 'instance-'.$second->id)];
    Http::fake(function (Request $request) use ($routes) {
        if (str_ends_with($request->url(), ':3841/api/orchestration/shell')) {
            throw new RuntimeException('unexpected');
        }

        return $routes[$request->url()];
    });

    $answers = array_map(static fn ($decision): array => $decision->toArray(), app(ConnEnvironmentRestart::class)->node(Node::query()->findOrFail($first->node_id), null, null));

    expect(array_slice($answers, 1))->toBe([
        ['instance_id' => $first->id, 'instance' => 'first', 'answer' => 'failed', 'reason' => 'conn.restart_admission_failed', 'operation_id' => null],
        ['instance_id' => $second->id, 'instance' => 'second', 'answer' => 'restarting', 'reason' => null,
            'operation_id' => Operation::query()->where('kind', AdmitConnEnvironmentRestartAction::Kind)->sole()->id],
    ]);
});

describe('API', function (): void {
    beforeEach(function (): void {
        $this->operator = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'linux',
            'public_ssh_host' => '10.44.0.77', 'wireguard_ip' => '10.44.0.77', 'user' => 'orbit']));
        $this->operator->accessibleNodes()->attach($this->node->id);
        $this->outsider = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'mini', 'status' => 'active', 'platform' => 'linux',
            'public_ssh_host' => '10.44.0.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit']));
    });

    it('refuses a caller without access to the Instance\'s or the target Node', function (string $path): void {
        $clone = restartServer($this->default, 'clone', 3840);
        Http::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.9'])->postJson('/api/v1/conn/'.str_replace(['{instance}', '{node}'], [$clone->id, $this->node->id], $path))
            ->assertForbidden();
        Http::assertNothingSent();
        expect(Operation::query()->where('kind', AdmitConnEnvironmentRestartAction::Kind)->count())->toBe(0);
    })->with(['instances/{instance}/t3-restart', 'nodes/{node}/t3-restart']);

    it('answers 200 for one Instance when it admits no restart', function (): void {
        $clone = restartServer($this->default, 'clone', 3840);
        Http::fake(restartT3(3840, 'clone.shop'));

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.77'])->postJson("/api/v1/conn/instances/{$clone->id}/t3-restart")
            ->assertOk()->assertJsonPath('data.restarts.0', ['instance_id' => $clone->id, 'instance' => 'clone', 'answer' => 'current', 'reason' => null, 'operation_id' => null]);
    });

    it('answers 200 for a Node walk that admits a restart, naming its Operation', function (): void {
        $clone = restartServer($this->default, 'clone', 3840);
        Http::fake(restartT3(3840, 'instance-'.$clone->id));

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.77'])->postJson("/api/v1/conn/nodes/{$this->node->id}/t3-restart")->assertOk();

        expect(array_column($response->json('data.restarts'), 'answer'))->toBe(['skipped', 'restarting'])
            ->and($response->json('data.restarts.1.operation_id'))->toBe(Operation::query()->where('kind', AdmitConnEnvironmentRestartAction::Kind)->sole()->id);
    });
});
