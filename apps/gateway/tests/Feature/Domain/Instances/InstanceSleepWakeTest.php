<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\WaitForGatewayReleaseIdleAction;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

/** A running development clone of a container default, with its own T3 port. */
function sleepingClone(bool $taskOwned = false): Instance
{
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $default = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($default, $node)->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    $owner = $taskOwned ? Task::query()->forceCreate(['project_id' => $project->id, 'title' => 'task', 'brief' => 'Fixture task.', 'status' => 'running'])->id : null;
    $clone = Instance::query()->forceCreate(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'clone', 'task_workspace_owner_id' => $owner,
        'checkout_path' => '/workspace/clone', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($clone, $node, $default)->update(['power_state' => 'running']);

    return $clone->refresh();
}

/** A default does not sleep in this part of the plan. */
it('refuses to put a default Instance to sleep', function (): void {
    sleepingClone();
    $default = Instance::query()->where('name', 'default')->firstOrFail();

    expect(fn () => app(InstanceSleep::class)->start($default, null, null))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('instance.sleep_unsupported'));
});

function powerRun(Operation $operation, string $status, array $result = []): Operation
{
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'power-proof']])->save();

    return app(DirectOperation::class)->projection($operation)->apply($operation->refresh(), ['status' => $status, 'run_id' => 'power-proof',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => $result]);
}

function activationPoll(Instance $instance, string $uri = '/'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])->withHeader('X-Forwarded-Uri', $uri)
        ->get('/api/v1/runtime-activations/app-instance/'.$instance->id);
}

it('admits a sleep with the container intent and the asleep marker under the Instance lock', function (): void {
    $clone = sleepingClone();

    $operation = app(InstanceSleep::class)->start($clone, 'node:1', null);

    expect($operation->kind)->toBe('instance.sleep')
        ->and($operation->lock_key)->toBe(OperationStore::lockKey('instance', $clone->id))
        ->and($operation->input['marker'])->toBe('/data/caddy/orbit/hibernation/app-instance-'.$clone->id.'.asleep')
        ->and($operation->input['container']['name'])->toBe('instance-'.$clone->id)
        ->and($operation->input['container']['clone']['t3']['unit'])->toBe(InstanceContainer::T3Unit)
        ->and(OperationRunBudgets::seconds($operation->forceFill(['state' => ['swamp_run_id' => 'r']])))->toBe(120);
});

it('refuses a sleep that the Instance does not allow, before any run', function (string $case, string $code): void {
    $clone = sleepingClone(taskOwned: $case === 'task');
    match ($case) {
        'checkout' => $clone->runtime()->delete(),
        'nested' => $clone->project->forceFill(['nested_fleet' => true])->save(),
        'production' => $clone->node->roles()->update(['role' => RoleName::AppProd]),
        'lifecycle' => $clone->update(['status' => 'checkout_prepared']),
        'task' => null,
        default => Operation::query()->create(['kind' => $case, 'target_type' => 'instance', 'target_id' => $clone->id,
            'node_id' => $clone->node_id, 'lock_key' => OperationStore::lockKey('instance', $clone->id), 'status' => 'running', 'input' => [], 'state' => []]),
    };

    expect(fn () => app(InstanceSleep::class)->start($clone->refresh(), null, null))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe($code));
    expect(Operation::query()->where('kind', InstanceSleep::Kind)->count())->toBe(0);
})->with([
    ['checkout', 'operation.kind_unavailable'], ['nested', 'instance.sleep_unsupported'],
    ['production', 'instance.sleep_unsupported'], ['lifecycle', 'instance.lifecycle_busy'],
    ['task', 'instance.lifecycle_busy'], ['instance.deploy', 'operation.target_busy'], ['instance.wake', 'operation.target_busy'],
]);

it('records a sleeping container as stopped and says that the command did not check T3', function (): void {
    $clone = sleepingClone();

    $operation = powerRun(app(InstanceSleep::class)->start($clone, null, null), 'succeeded', ['commit' => null]);

    expect($operation->status->value)->toBe('succeeded')
        ->and($operation->result)->toBe(['power_state' => 'stopped', 't3_turn' => 'not_checked'])
        ->and($operation->lock_key)->toBeNull()
        ->and($clone->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
});

it('keeps the power state after a failed sleep or wake, with the phase error code', function (string $kind, string $state, string $code): void {
    $clone = sleepingClone();
    $clone->runtime()->update(['power_state' => $state]);
    $action = $kind === 'sleep' ? app(InstanceSleep::class) : app(InstanceWake::class);

    $operation = powerRun($action->start($clone, null, null), 'failed', ['error_code' => $code]);

    expect($operation->status->value)->toBe('failed')
        ->and($operation->error_code)->toBe($code)
        ->and($clone->runtime()->firstOrFail()->power_state->value)->toBe($state);
})->with([
    ['sleep', 'running', 'instance.sleep_route_unready'],
    ['wake', 'stopped', 'instance.container_start_failed'],
    ['wake', 'stopped', 'instance.web_unready'],
]);

/** The wake removed the marker once the app answered; only T3 stayed silent. */
it('records a wake whose T3 stayed silent as failed but running, because the container runs and its site serves', function (): void {
    $clone = sleepingClone();
    $clone->runtime()->update(['power_state' => 'stopped']);
    powerRun(app(InstanceSleep::class)->start($clone, null, null), 'succeeded');

    $wake = app(InstanceWake::class)->start($clone, null, null);
    expect($wake->input['web'])->toBe(['port' => InstanceContainer::WebPort, 'ready_seconds' => 30, 'settle_seconds' => 5])
        ->and($wake->input['marker'])->toBe(InstanceWake::markerPath($clone));

    $operation = powerRun($wake, 'failed', ['error_code' => 'instance.t3_unready']);

    expect($operation->status->value)->toBe('failed')
        ->and($operation->error_code)->toBe('instance.t3_unready')
        ->and($clone->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running);
});

it('admits one wake per Instance, which a second caller joins, and records it awake', function (): void {
    $clone = sleepingClone();
    $clone->runtime()->update(['power_state' => 'stopped']);

    $first = app(InstanceWake::class)->start($clone, 'node:1', null);
    $second = app(InstanceWake::class)->start($clone, 'node:2', null);

    expect($second->id)->toBe($first->id)
        ->and(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1)
        ->and(OperationRunBudgets::seconds((clone $first)->forceFill(['state' => ['swamp_run_id' => 'r']])))->toBe(300);

    $done = powerRun($first, 'succeeded', ['commit' => null]);

    expect($done->result)->toBe(['power_state' => 'running'])
        ->and($clone->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running);
});

it('wakes a default too, but never a nested-fleet Instance', function (): void {
    $clone = sleepingClone();
    $default = Instance::query()->where('name', 'default')->firstOrFail();

    expect(app(InstanceWake::class)->start($default, null, null)->kind)->toBe('instance.wake');

    $clone->project->forceFill(['nested_fleet' => true])->save();
    expect(fn () => app(InstanceWake::class)->start($clone->refresh(), null, null))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('instance.sleep_unsupported'));
});

it('starts one wake from Route visits behind the asleep marker, shows the failure page after a failed wake, and retries on request', function (): void {
    $clone = sleepingClone();
    $clone->runtime()->update(['power_state' => 'stopped']);
    powerRun(app(InstanceSleep::class)->start($clone, null, null), 'succeeded');

    activationPoll($clone, '/dashboard')->assertStatus(401)->assertHeader('X-Orbit-Runtime-Activation-State', 'pending')
        ->assertHeader('Retry-After', '1')->assertSee('aria-label="Orbit"', false)->assertDontSee('Waking');
    activationPoll($clone, '/dashboard')->assertStatus(401)->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');

    $wakes = Operation::query()->where('kind', InstanceWake::Kind)->get();
    expect($wakes)->toHaveCount(1)->and($wakes->sole()->triggered_by)->toBe('node:'.$clone->node_id);

    powerRun($wakes->sole(), 'failed', ['error_code' => 'instance.container_start_failed']);

    activationPoll($clone, '/dashboard')->assertStatus(503)->assertHeader('X-Orbit-Runtime-Activation-State', 'failed')
        ->assertSee('The wake failed (instance.container_start_failed). Try again.')->assertSee('Try again');
    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1);

    activationPoll($clone, '/dashboard?orbit-wake-retry=1')->assertStatus(401);
    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(2);
});

it('keeps the progress page while other lifecycle work holds the Instance', function (): void {
    $clone = sleepingClone();
    Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $clone->id, 'node_id' => $clone->node_id,
        'lock_key' => OperationStore::lockKey('instance', $clone->id), 'status' => 'running', 'input' => [], 'state' => []]);

    activationPoll($clone)->assertStatus(401)->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');
    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(0);
});

it('holds Gateway release idle until sleep and wake settle', function (string $kind): void {
    Sleep::fake(syncWithCarbon: true);
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => 1,
        'status' => 'running', 'input' => [], 'state' => []]);
    Sleep::whenFakingSleep(fn () => $operation->update(['status' => 'succeeded']));
    app(WaitForGatewayReleaseIdleAction::class)->execute();
    Sleep::assertSleptTimes(1);
})->with(['instance.sleep', 'instance.wake']);

it('renders a running container site as before plus only the asleep check, which calls nothing while the marker is missing', function (): void {
    $clone = sleepingClone();
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $clone->project_id, 'node_id' => $clone->node_id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'clone.tool.test', 'status' => 'pending']);
    $renderer = app(DevelopmentCaddyConfigRenderer::class);

    $site = app(DevelopmentSiteRepository::class)->workloadSite($clone, $route);
    $before = $renderer->render(collect([new DevelopmentSite(...[...get_object_vars($site), 'asleepMarker' => null])]));
    $after = $renderer->render(collect([$site]));

    $marker = '/data/caddy/orbit/hibernation/app-instance-'.$clone->id.'.asleep';
    expect($site->asleepMarker)->toBe($marker)
        ->and(substr_count($after, 'forward_auth'))->toBe(1)
        ->and(substr_count($before, 'forward_auth'))->toBe(0)
        ->and($after)->toContain('try_files /app-instance-'.$clone->id.'.asleep')
        ->and($after)->toContain('uri /api/v1/runtime-activations/app-instance/'.$clone->id)
        ->and($after)->not->toContain('.awake');

    // Removing the one inserted block gives back the site as it was rendered before: the proxy, its order and its options stay.
    $start = strpos($after, '@orbit_asleep {');
    $end = strpos($after, 'reverse_proxy', $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $lineStart = strrpos(substr($after, 0, $start), PHP_EOL) + 1;
    $lineEnd = strrpos(substr($after, 0, $end), PHP_EOL) + 1;
    expect(substr($after, 0, $lineStart).substr($after, $lineEnd))->toBe($before);
});

it('gives a production or checkout site no asleep check', function (): void {
    $site = new DevelopmentSite(nodeId: 1, nodeAddress: '10.44.0.7', scope: 'app-instance-9', checkoutPath: '', documentRoot: '',
        phpVersion: null, domain: 'x.test', localHttpUpstream: '10.253.0.2:8080');

    expect(app(DevelopmentCaddyConfigRenderer::class)->render(collect([$site])))->not->toContain('orbit_asleep');
});
