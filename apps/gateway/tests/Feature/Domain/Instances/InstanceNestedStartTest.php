<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\WaitForGatewayReleaseIdleAction;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\NestedFleetIntent;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceStart;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

/**
 * A default and one running clone of it. `$nested` makes the Project a nested-fleet one on a switched-on Node, so both runtimes are
 * recorded in the nested project; `$switch` false turns the Node's switch off afterwards.
 *
 * @return array{0: Instance, 1: Instance}
 */
function startPair(bool $nested, bool $switch = true): array
{
    $node = containerDefaultNode();
    $node->update(['settings' => [...($node->settings ?? []), 'nested_fleet' => $nested]]);
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $project->forceFill(['nested_fleet' => $nested])->save();
    $default = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($default, $node->refresh())->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    $clone = Instance::query()->forceCreate(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'copy',
        'checkout_path' => '/workspace/copy', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($clone, $node, $default)->update(['power_state' => 'running']);
    if (! $switch) {
        $node->update(['settings' => [...$node->settings, 'nested_fleet' => false]]);
    }

    return [$default->refresh(), $clone->refresh()];
}

/** The shared Gateway env file that NestedFleetClubKey reads, with or without the key. */
function startClubKey(?string $value): void
{
    $base = sys_get_temp_dir().'/orbit-start-env-'.bin2hex(random_bytes(4));
    mkdir($base.'/shared', 0700, true);
    file_put_contents($base.'/shared/gateway.env', "APP_NAME=Orbit\n".($value === null ? '' : "ORBIT_NESTED_FLEET_CLUB_API_KEY={$value}\n"));
    chmod($base.'/shared/gateway.env', 0600);
    app()->instance(GatewayReleaseLayout::class, new GatewayReleaseLayout($base.'/current/apps/gateway'));
    app()->instance(GatewayReleaseLock::class, new GatewayReleaseLock($base.'/release.lock'));
    $sent = new ArrayObject;
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(fn (array $frame) => $sent->append($frame));
    $socket->shouldReceive('receive')->andReturnUsing(function () use ($sent): array {
        $frame = $sent[count($sent) - 1];

        return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'],
            'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => __FILE__, 'swamp.run_token' => 'fixture']);
}

function startRun(Operation $operation, string $status, array $result = [], ?string $errorCode = null): Operation
{
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'start-proof']])->save();

    return app(DirectOperation::class)->projection($operation)->apply($operation->refresh(), ['status' => $status, 'run_id' => 'start-proof',
        'error_code' => $errorCode, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => $result]);
}

it('takes the wake for a normal clone and the start only for a clone of a nested-fleet default', function (bool $nested, bool $switch, string $kind): void {
    [, $clone] = startPair($nested, $switch);

    $operation = app(InstanceWake::class)->start($clone, 'node:1', null);

    expect($operation->kind)->toBe($kind)
        ->and($operation->lock_key)->toBe(OperationStore::lockKey('instance', $clone->id));
    if ($kind === InstanceWake::Kind) {
        // A normal wake: the intent it always had, with no fleet and no Club key part.
        expect(array_keys($operation->input))->toBe(['container', 'marker', 'web'])
            ->and($operation->input['container'])->not->toHaveKey('club_key');
    }
})->with([
    'normal clone' => [false, false, InstanceWake::Kind],
    'nested-fleet clone' => [true, true, InstanceStart::Kind],
]);

it('gives the start the container with its Club key part and the clone\'s fleet, and no asleep marker', function (): void {
    [$default, $clone] = startPair(true);
    $runtime = $clone->runtime()->firstOrFail();

    $operation = app(InstanceWake::class)->start($clone, null, null);

    expect(array_keys($operation->input))->toBe(['container', 'fleet'])
        ->and($operation->input['container']['project'])->toBe(InstancePlacement::NestedProject)
        ->and($operation->input['container']['club_key']['directory'])->toBe('/run/orbit-club')
        ->and($operation->input['container']['config']['boot.autostart'])->toBe('false')
        ->and($operation->input['container']['clone'])->not->toBeNull()
        ->and($operation->input['fleet'])->toBe(app(NestedFleetIntent::class)->forClone($clone, $runtime))
        ->and($operation->input['fleet'])->toBe(app(InstanceDeploymentIntent::class)->snapshot($default)['fleet'])
        ->and(OperationRunBudgets::seconds($operation->forceFill(['state' => ['swamp_run_id' => 'r']])))->toBe(1200);
});

it('refuses a wake of a nested-fleet default by name and never starts it', function (): void {
    [$default] = startPair(true);

    expect(fn () => app(InstanceWake::class)->start($default, null, null))->toThrow(fn (ResourceOperationException $error) => expect([$error->errorCode, $error->getMessage()])
        ->toBe(['instance.sleep_unsupported', 'A nested-fleet default starts through its deployment; it does not sleep or wake.']));
    expect(fn () => app(InstanceStart::class)->start($default, null, null))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)
        ->toBe('instance.start_unsupported'));
    expect(Operation::query()->count())->toBe(0);
});

it('still refuses to put a nested-fleet clone to sleep', function (): void {
    [, $clone] = startPair(true);

    expect(fn () => app(InstanceSleep::class)->start($clone, null, null))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)
        ->toBe('instance.sleep_unsupported'));
});

it('refuses a start that the start does not allow, before any run', function (string $case, string $code): void {
    [, $clone] = $case === 'normal clone' ? startPair(false) : startPair(true, $case !== 'switch off');
    $target = match ($case) {
        'normal clone' => $clone,
        'lifecycle' => tap($clone)->update(['status' => 'checkout_prepared']),
        'switch off' => $clone,
        default => tap($clone, fn () => Operation::query()->create(['kind' => $case, 'target_type' => 'instance', 'target_id' => $clone->id,
            'node_id' => $clone->node_id, 'lock_key' => OperationStore::lockKey('instance', $clone->id), 'status' => 'running', 'input' => [], 'state' => []])),
    };
    $before = Operation::query()->count();

    expect(fn () => app(InstanceStart::class)->start($target->refresh(), null, null))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe($code));
    expect(Operation::query()->count())->toBe($before);
})->with([
    ['normal clone', 'instance.start_unsupported'], ['switch off', 'instance.start_unsupported'], ['lifecycle', 'instance.lifecycle_busy'],
    ['instance.deploy', 'operation.target_busy'], ['instance.destroy', 'operation.target_busy'],
]);

it('joins an open start, and keys it by the wake request\'s Idempotency-Key', function (): void {
    [, $clone] = startPair(true);
    $idempotency = app(OperationIdempotency::class);

    $first = $idempotency->under(InstanceWake::Kind, 'key-1', 'print-1', fn () => app(InstanceWake::class)->start($clone, null, null));
    $replayed = $idempotency->under(InstanceWake::Kind, 'key-1', 'print-1', fn () => app(InstanceWake::class)->start($clone, null, null));
    $joined = app(InstanceWake::class)->start($clone, null, null);

    expect([$first->kind, $first->idempotency_key])->toBe([InstanceStart::Kind, 'key-1'])
        ->and($replayed->id)->toBe($first->id)
        ->and($joined->id)->toBe($first->id)
        ->and(Operation::query()->count())->toBe(1);
});

it('reads the Club key at the run\'s start and fails before the run without one', function (): void {
    [, $clone] = startPair(true);
    $operation = app(InstanceStart::class)->start($clone, null, null);

    startClubKey(null);
    expect(fn () => app(InstanceStart::class)->input($operation))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)
        ->toBe('instance.nested_club_key_missing'));

    SwampNodeFixture::ensure();
    startClubKey('fake-nested-club-key-0123456789');
    $input = app(InstanceStart::class)->input($operation);
    expect($input['execution_secrets'])->toBe(['club_key' => ['vault' => 'orbit-control-inputs', 'key' => 'nested-fleet-club-api-key']])
        ->and($input['intent']['container']['club_key']['available'])->toBeTrue()
        ->and($input['intent']['fleet'])->toBe($operation->input['fleet'])
        ->and(json_encode($input))->not->toContain('fake-nested-club-key');
});

it('records a started copy as running with what it did with the key and the fleet\'s seconds', function (): void {
    [, $clone] = startPair(true);
    $clone->runtime()->update(['power_state' => 'stopped']);

    $operation = startRun(app(InstanceStart::class)->start($clone, null, null), 'succeeded', ['commit' => null, 'club_key' => 'changed', 'fleet_seconds' => 91.4]);

    expect($operation->status->value)->toBe('succeeded')
        ->and($operation->result)->toBe(['power_state' => 'running', 'nested_fleet_club_key' => 'written', 'fleet_seconds' => 91.4])
        ->and($clone->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running);
});

it('keeps the power state of a failed start and turns a fleet failure into the redacted failure detail', function (): void {
    [, $clone] = startPair(true);
    $clone->runtime()->update(['power_state' => 'stopped']);

    $operation = startRun(app(InstanceStart::class)->start($clone, null, null), 'failed', ['error_code' => 'instance.container_fleet_resume_failed',
        'club_key' => 'unchanged', 'fleet_seconds' => 301.2,
        'detail' => "Check Doctor: Readiness check 'doctor' failed after 300 s: password=hunter2-secret-value"], 'instance.container_fleet_resume_failed');

    expect($operation->error_code)->toBe('instance.container_fleet_resume_failed')
        ->and($operation->result)->toBe(['error_code' => 'instance.container_fleet_resume_failed', 'nested_fleet_club_key' => 'unchanged', 'fleet_seconds' => 301.2])
        ->and($operation->state['native_failure']['detail'])->toStartWith("Check Doctor: Readiness check 'doctor' failed after 300 s")
        ->and($operation->state['native_failure']['detail'])->not->toContain('hunter2-secret-value')
        ->and($clone->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
});

it('holds a Gateway release while a start is open', function (): void {
    expect(WaitForGatewayReleaseIdleAction::BlockingKindPatterns)->toContain(InstanceStart::Kind);
});
