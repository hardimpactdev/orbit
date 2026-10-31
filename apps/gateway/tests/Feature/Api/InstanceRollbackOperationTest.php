<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Models\Activity;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Support\Orb220DeploymentApiFixture;

beforeEach(function (): void {
    enable_operation_queue();
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->asCaller = fn () => $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
});

function start_rollback(object $test, string $release = 'initial'): TestResponse
{
    return ($test->asCaller)()->call('POST', "/api/v1/instances/{$test->fixture->instance->id}/rollback", server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], content: json_encode(['release' => $release], JSON_THROW_ON_ERROR));
}

/** Queues a deploy of the fixture's Instance, as a person or as the automatic deployment tick. */
function queue_rollback_test_deploy(object $test, bool $automatic): Operation
{
    return $automatic
        ? app(StartInstanceDeployAction::class)->execute($test->fixture->instance, AutomaticDeployHistory::Trigger, null, str_repeat('b', 40))
        : Operation::query()->findOrFail(($test->asCaller)()->call('POST', "/api/v1/instances/{$test->fixture->instance->id}/deploy", server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{}')->assertAccepted()->json('data.id'));
}

/** @return list<int> */
function rollback_test_lock_holders(): array
{
    return Operation::query()->whereNotNull('lock_key')->orderBy('id')->pluck('id')->all();
}

describe('starting a rollback', function (): void {
    it('answers 202 with a queued operation that holds the Instance and records the release input', function (): void {
        start_rollback($this)->assertAccepted()
            ->assertJsonPath('data.kind', 'instance.rollback')
            ->assertJsonPath('data.target_type', 'instance')
            ->assertJsonPath('data.target_id', $this->fixture->instance->id)
            ->assertJsonPath('data.input.release', 'initial')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.poll_after_seconds', 2)
            ->assertJsonPath('data.triggered_by', $this->fixture->caller->name);

        $activity = Activity::query()->sole();

        expect(Operation::query()->sole()->lock_key)->toBe("instance:{$this->fixture->instance->id}")
            ->and($activity->command)->toBe('instance:rollback')
            ->and($activity->status)->toBe('succeeded')
            ->and($activity->properties?->get('input'))->toBe(['release' => 'initial'])
            ->and(InstanceDeployment::query()->count())->toBe(0)
            ->and($this->fixture->deployment->invocations)->toBe(0);
    });

    it('refuses an Instance that cannot be rolled back before it records anything', function (): void {
        $this->fixture->instance->update(['deployment_branch' => 'bad..branch']);

        start_rollback($this)->assertStatus(409)->assertJsonPath('error.code', 'deployment_config.unavailable');

        expect(Operation::query()->count())->toBe(0);
    });

    it('refuses a release that the Instance does not retain before it records anything', function (): void {
        start_rollback($this, 'release-gone')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rollback.release_not_retained')
            ->assertJsonPath('error.details.release', 'release-gone');

        expect(Operation::query()->count())->toBe(0)
            ->and(InstanceDeployment::query()->count())->toBe(0)
            ->and($this->fixture->deployment->invocations)->toBe(0);
    });

    it('refuses a second rollback while the first one holds the Instance', function (): void {
        $first = start_rollback($this)->json('data.id');

        start_rollback($this)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'operation.target_busy')
            ->assertJsonPath('error.details.operation_id', $first);

        expect(rollback_test_lock_holders())->toBe([$first]);
    });
});

describe('a rollback and a deploy of the same Instance', function (): void {
    it('cancels a deploy that waits in the queue and takes the Instance', function (bool $automatic): void {
        $deploy = queue_rollback_test_deploy($this, $automatic);

        $rollback = start_rollback($this)->assertAccepted()->json('data.id');
        $deploy->refresh();

        expect($deploy->status)->toBe(OperationStatus::Cancelled)
            ->and($deploy->error_code)->toBe('operation.superseded')
            ->and($deploy->cancel_requested_at)->not->toBeNull()
            ->and($deploy->finished_at)->not->toBeNull()
            ->and(rollback_test_lock_holders())->toBe([$rollback]);

        ($this->asCaller)()->getJson("/api/v1/operations/{$deploy->id}")->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.error_code', 'operation.superseded')
            ->assertJsonPath('data.cancel_requested', true);

        expect(DB::table('jobs')->count())->toBe(0)
            ->and(Operation::query()->findOrFail($rollback)->status)->toBe(OperationStatus::Queued);

    })->with(['manual' => false, 'automatic' => true]);

    it('tells the deploy channel and the orbit channel that the deploy ended, then that the rollback started', function (): void {
        $deploy = queue_rollback_test_deploy($this, automatic: false);
        Event::fake([RecordBroadcast::class]);

        $rollback = start_rollback($this)->assertAccepted()->json('data.id');

        $events = collect(Event::dispatched(RecordBroadcast::class))->map(static fn (array $arguments): RecordBroadcast => $arguments[0])
            ->filter(static fn (RecordBroadcast $event): bool => str_starts_with($event->type->value, 'operation.'));
        $orbit = $events->filter(static fn (RecordBroadcast $event): bool => $event->channel === 'orbit')
            ->map(static fn (RecordBroadcast $event): string => "{$event->type->value}:{$event->data['id']}:{$event->data['status']}")->values()->all();
        $private = $events->filter(static fn (RecordBroadcast $event): bool => $event->channel === "operation.{$deploy->id}")->values();

        expect($orbit)->toBe(["operation.updated:{$deploy->id}:cancelled", "operation.created:{$rollback}:queued"])
            ->and($private)->toHaveCount(1)
            ->and($private->first()->data['sequence'])->toBe($deploy->refresh()->sequence);
    });

    it('leaves a queued deploy and automatic deployment alone when the release is not retained', function (bool $automatic): void {
        $this->fixture->instance->update(['auto_deploy' => true]);
        $deploy = queue_rollback_test_deploy($this, $automatic);
        $state = $this->fixture->instance->fresh()->auto_deploy_state;

        start_rollback($this, 'release-gone')->assertStatus(422)->assertJsonPath('error.code', 'rollback.release_not_retained');

        expect($deploy->refresh()->status)->toBe(OperationStatus::Queued)
            ->and($deploy->error_code)->toBeNull()
            ->and($deploy->cancel_requested_at)->toBeNull()
            ->and(rollback_test_lock_holders())->toBe([$deploy->id])
            ->and(Operation::query()->where('kind', 'instance.rollback')->count())->toBe(0)
            ->and(InstanceDeployment::query()->count())->toBe(0)
            ->and($this->fixture->instance->fresh()->auto_deploy_state)->toBe($state)
            ->and(new AutomaticDeployHistory($this->fixture->instance->fresh())->pausedBy())->toBeNull();

        expect(DB::table('jobs')->count())->toBe(0)
            ->and($this->fixture->deployment->preparedCommits)->toBe([]);

    })->with(['manual' => false, 'automatic' => true]);

    it('refuses while a worker runs the deploy', function (bool $automatic): void {
        $deploy = queue_rollback_test_deploy($this, $automatic);
        expect(app(OperationStore::class)->claim($deploy, 'gateway:1'))->toBeTrue();

        start_rollback($this)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'operation.target_busy')
            ->assertJsonPath('error.details.operation_id', $deploy->id);

        expect($deploy->refresh()->status)->toBe(OperationStatus::Running)
            ->and($deploy->error_code)->toBeNull()
            ->and(rollback_test_lock_holders())->toBe([$deploy->id])
            ->and(Operation::query()->where('kind', 'instance.rollback')->count())->toBe(0);
    })->with(['manual' => false, 'automatic' => true]);

    it('refuses while a deploy that a lost worker started waits to resume', function (): void {
        $deploy = queue_rollback_test_deploy($this, automatic: false);
        $deploy->forceFill(['attempts' => 1])->save();

        start_rollback($this)->assertStatus(409)->assertJsonPath('error.details.operation_id', $deploy->id);

        expect($deploy->refresh()->status)->toBe(OperationStatus::Queued)
            ->and(rollback_test_lock_holders())->toBe([$deploy->id]);
    });

    it('leaves one holder when the deploy starts between the check and the cancel', function (): void {
        $deploy = queue_rollback_test_deploy($this, automatic: false);
        $claimed = null;
        DB::listen(static function (QueryExecuted $query) use ($deploy, &$claimed): void {
            // The worker claims the deploy right after the rollback found it queued. On the test's one connection the
            // claim joins the rollback's transaction, so the rollback's refused insert also undoes the claim. On the
            // Gateway, BEGIN IMMEDIATE runs the claim before or after the whole transaction.
            if ($claimed === null && str_contains($query->sql, '"kind" in')) {
                $claimed = app(OperationStore::class)->claim($deploy, 'gateway:1');
            }
        });

        start_rollback($this)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'operation.target_busy')
            ->assertJsonPath('error.details.operation_id', $deploy->id);

        // The refused insert undid the claim with the rest of the rollback's transaction, so the deploy is queued again.
        expect($claimed)->toBeTrue()
            ->and($deploy->refresh()->status)->toBe(OperationStatus::Queued)
            ->and($deploy->attempts)->toBe(0)
            ->and($deploy->error_code)->toBeNull()
            ->and($deploy->cancel_requested_at)->toBeNull()
            ->and(rollback_test_lock_holders())->toBe([$deploy->id])
            ->and(Operation::query()->where('kind', 'instance.rollback')->count())->toBe(0);
    });

    it('refuses a deploy while a rollback holds the Instance', function (): void {
        $rollback = start_rollback($this)->json('data.id');

        ($this->asCaller)()->call('POST', "/api/v1/instances/{$this->fixture->instance->id}/deploy", server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{}')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'operation.target_busy')
            ->assertJsonPath('error.details.operation_id', $rollback);
    });
});
