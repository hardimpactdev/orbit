<?php

declare(strict_types=1);

use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Models\UpdateLease;
use App\Services\Operations\FleetUpdateLease;
use App\Services\Operations\FleetUpdateVerifier;
use App\Services\Operations\GatewayCliArtifactRelay;
use App\Services\Operations\GatewayServiceUpdater;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdateLeaseHeartbeatProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(GatewayCliArtifactRelay::class, new class extends GatewayCliArtifactRelay {
        #[Override]
        public function stage(OperationRun $operationRun, OperationUpdatePlan $plan): void
        {
            //
        }

        #[Override]
        public function cleanup(OperationRun $operationRun): void
        {
            //
        }
    });
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('loads the immutable update plan and writes runner start events', function (): void {
    $run = updateRunnerCommandRun();
    app()->instance(GatewayServiceUpdater::class, new UpdateRunnerCommandNoopGatewayUpdater);
    app()->instance(FleetUpdateVerifier::class, new UpdateRunnerCommandNoopFleetVerifier);

    app(OperationUpdatePlanStore::class)->create(
        $run,
        updateRunnerCommandSnapshot(
            targetVersion: '1.2.3',
            gatewayImage: 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ),
    );

    $this
        ->artisan('orbit:update-runner', ['--operation-run-id' => $run->id])
        ->expectsOutputToContain("Update runner started for operation run {$run->id}.")
        ->assertSuccessful();

    $run->refresh();
    $event = $run->events()->firstOrFail();

    expect($run->status)
        ->toBe(OperationStatus::Succeeded)
        ->and($run->events()->pluck('event_type')->last())
        ->toBe('complete')
        ->and($event->event_type)
        ->toBe('step')
        ->and($event->payload)
        ->toMatchArray([
            'key' => 'runner',
            'status' => 'running',
            'message' => 'Update runner started',
        ])
        ->and($event->metadata)
        ->toMatchArray([
            'target_version' => '1.2.3',
            'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'manifest_version' => '1.2.3',
        ]);
});

it('claims the reserved fleet lease passed by the launcher and releases it after the run', function (): void {
    $run = updateRunnerCommandRun();
    $reservation = app(FleetUpdateLease::class)->reserve($run);
    $reservationOwnerToken = $reservation->owner_token;
    $heartbeat = new class extends UpdateLeaseHeartbeatProcess {
        public ?string $operationRunId = null;

        public ?int $fleetLeaseId = null;

        #[Override]
        public function whileRunning(
            OperationRun $operationRun,
            UpdateLease $fleetLease,
            int $ttlSeconds,
            callable $callback,
        ): mixed {
            $this->operationRunId = $operationRun->id;
            $this->fleetLeaseId = $fleetLease->id;

            return $callback();
        }
    };
    app()->instance(UpdateLeaseHeartbeatProcess::class, $heartbeat);
    app()->instance(GatewayServiceUpdater::class, new UpdateRunnerCommandNoopGatewayUpdater);
    app()->instance(FleetUpdateVerifier::class, new UpdateRunnerCommandNoopFleetVerifier);

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerCommandSnapshot());

    $this
        ->artisan('orbit:update-runner', [
            '--operation-run-id' => $run->id,
            '--fleet-lease-id' => $reservation->id,
        ])
        ->assertSuccessful();

    expect($run->refresh()->status)
        ->toBe(OperationStatus::Succeeded)
        ->and($heartbeat->operationRunId)
        ->toBe($run->id)
        ->and($heartbeat->fleetLeaseId)
        ->toBe($reservation->id)
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())
        ->toBe(0)
        ->and($reservation->refresh()->owner_token)
        ->not->toBe($reservationOwnerToken);
});

it('does not mutate the operation when a duplicate runner cannot claim the reservation', function (): void {
    $run = updateRunnerCommandRun();
    $fleetLease = app(FleetUpdateLease::class);
    $reservation = $fleetLease->reserve($run);
    $claimed = $fleetLease->claim($run, $reservation->id);

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerCommandSnapshot());

    $this
        ->artisan('orbit:update-runner', [
            '--operation-run-id' => $run->id,
            '--fleet-lease-id' => $reservation->id,
        ])
        ->expectsOutputToContain('Update lease owner token mismatch.')
        ->assertFailed();

    expect($run->refresh()->status)
        ->toBe(OperationStatus::Queued)
        ->and($run->events()->count())
        ->toBe(0)
        ->and($claimed->refresh()->active_resource_key)
        ->toBe('fleet:update-all')
        ->and($claimed->owner_token)
        ->not->toBe($reservation->owner_token);
});

it('fails the operation after its unclaimed fleet reservation expires', function (): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $run = updateRunnerCommandRun();
    $reservation = app(FleetUpdateLease::class)->reserve($run);
    app(OperationUpdatePlanStore::class)->create($run, updateRunnerCommandSnapshot());

    Carbon::setTestNow('2026-06-02 10:02:01');

    $this
        ->artisan('orbit:update-runner', [
            '--operation-run-id' => $run->id,
            '--fleet-lease-id' => $reservation->id,
        ])
        ->expectsOutputToContain('Fleet update reservation expired before the runner claimed it.')
        ->assertFailed();

    expect($run->refresh()->status)
        ->toBe(OperationStatus::Failed)
        ->and($reservation->refresh()->active_resource_key)
        ->toBeNull();
});

it('fails fast when the operation run has no persisted update plan or deferred start payload', function (): void {
    $run = updateRunnerCommandRun();

    $this
        ->artisan('orbit:update-runner', ['--operation-run-id' => $run->id])
        ->expectsOutputToContain('Deferred update start request payload was not found on the operation run.')
        ->assertFailed();

    expect($run->refresh()->status)
        ->toBe(OperationStatus::Failed)
        ->and($run->events()->where('event_type', 'step')->count())
        ->toBeGreaterThan(0);
});

it('fails fast when the operation run is already terminal', function (): void {
    $run = updateRunnerCommandRun();

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerCommandSnapshot());
    app(OperationRunRecorder::class)->succeeded($run->id, result: ['done' => true]);

    $this
        ->artisan('orbit:update-runner', ['--operation-run-id' => $run->id])
        ->expectsOutputToContain("Operation run [{$run->id}] is already terminal.")
        ->assertFailed();

    expect($run->refresh()->status)->toBe(OperationStatus::Succeeded)->and($run->events()->count())->toBe(0);
});

it('requires an operation run id', function (): void {
    $this
        ->artisan('orbit:update-runner')
        ->expectsOutputToContain('The --operation-run-id option is required.')
        ->assertFailed();
});

function updateRunnerCommandRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

class UpdateRunnerCommandNoopGatewayUpdater extends GatewayServiceUpdater
{
    #[Override]
    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        //
    }
}

final class UpdateRunnerCommandNoopFleetVerifier extends FleetUpdateVerifier
{
    public function __construct() {}

    #[Override]
    public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        //
    }
}

/**
 * @param  array<string, mixed>  $manifestOverrides
 */
function updateRunnerCommandSnapshot(
    string $targetVersion = '1.2.3',
    string $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    array $manifestOverrides = [],
): OperationUpdatePlanSnapshot {
    $manifest = array_replace_recursive([
        'version' => $targetVersion,
        'source' => 'github-release',
        'images' => [
            'gateway' => $gatewayImage,
        ],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => "https://github.com/hardimpactdev/orbit/releases/download/v{$targetVersion}/orbit-linux-amd64",
                'sha256' => str_repeat('b', 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'caddy:2-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
        ],
    ], $manifestOverrides);

    return new OperationUpdatePlanSnapshot(
        targetVersion: $targetVersion,
        gatewayImage: $gatewayImage,
        manifestSource: 'github-release',
        manifestVersion: $targetVersion,
        manifestSnapshot: $manifest,
        cliArtifacts: $manifest['cli_artifacts'],
        roleImages: $manifest['role_images'],
    );
}
