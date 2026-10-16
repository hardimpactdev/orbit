<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\OperationDispatcher;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepStatus;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Activity;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\Orb220DeploymentApiFixture;

beforeEach(function (): void {
    enable_operation_queue();
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->deployUrl = "/api/v1/instances/{$this->fixture->instance->id}/deploy";
    $this->asCaller = fn () => $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
});

function start_deploy(object $test): TestResponse
{
    return ($test->asCaller)()->call('POST', $test->deployUrl, server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], content: '{}');
}

describe('starting a deploy', function (): void {
    it('answers 202 with a queued operation that holds the Instance and queues its job', function (): void {
        $response = start_deploy($this);

        $response->assertAccepted()
            ->assertJsonPath('data.kind', 'instance.deploy')
            ->assertJsonPath('data.target_type', 'instance')
            ->assertJsonPath('data.target_id', $this->fixture->instance->id)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.sequence', 1)
            ->assertJsonPath('data.poll_after_seconds', 2)
            ->assertJsonPath('data.triggered_by', $this->fixture->caller->name)
            ->assertJsonMissingPath('data.steps');

        $operation = Operation::query()->sole();

        expect($operation->lock_key)->toBe("instance:{$this->fixture->instance->id}")
            ->and($operation->node_id)->toBe($this->fixture->owner->id)
            ->and(DB::table('jobs')->sole()->queue)->toBe(GatewayQueue::Operations->value)
            ->and(Activity::query()->sole()->status)->toBe('succeeded')
            ->and(InstanceDeployment::query()->count())->toBe(0)
            ->and($this->fixture->deployment->invocations)->toBe(0);
    });

    it('refuses a second deploy while the first one holds the Instance', function (): void {
        $first = start_deploy($this)->json('data.id');

        start_deploy($this)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'operation.target_busy')
            ->assertJsonPath('error.details.operation_id', $first);

        expect(Operation::query()->count())->toBe(1);
    });

    it('refuses without the Gateway queue and records nothing', function (): void {
        config(['orbit.gateway_queue.connection' => '']);

        start_deploy($this)->assertStatus(503)->assertJsonPath('error.code', 'operation.queue_unavailable');

        expect(Operation::query()->count())->toBe(0);
    });

    it('refuses an Instance that cannot be deployed before it records anything', function (): void {
        $this->fixture->instance->update(['deployment_branch' => 'bad..branch']);

        start_deploy($this)->assertStatus(409)->assertJsonPath('error.code', 'deployment_config.unavailable');

        expect(Operation::query()->count())->toBe(0);
    });

    it('fails the record and answers 503 when the job cannot be queued', function (): void {
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('Connection refused'));

        start_deploy($this)->assertStatus(503)->assertJsonPath('error.code', 'operation.dispatch_failed');

        $operation = Operation::query()->sole();
        expect($operation->status)->toBe(OperationStatus::Failed)
            ->and($operation->error_code)->toBe('operation.dispatch_failed')
            ->and($operation->lock_key)->toBeNull();
    });
});

describe('running a deploy', function (): void {
    it('runs the deployment as named steps and ends with its result', function (): void {
        $id = start_deploy($this)->json('data.id');

        expect(run_operation_jobs())->toBe(1);

        $show = ($this->asCaller)()->getJson("/api/v1/operations/{$id}");
        $deployment = InstanceDeployment::query()->sole();

        $show->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.current_step', null)
            ->assertJsonPath('data.poll_after_seconds', null)
            ->assertJsonPath('data.attempts', 1)
            ->assertJsonPath('data.result', [
                'deployment_id' => $deployment->id,
                'release' => 'fresh',
                'selected_release' => 'fresh',
                'commit' => str_repeat('a', 40),
            ]);

        expect(array_column($show->json('data.steps'), 'name'))->toBe([
            'source_preparation',
            'environment_sync',
            'before_activation.prepare',
            'activation',
            'php_refresh',
            'process_restart',
            'after_activation.finish',
            'cleanup',
        ])
            ->and(array_unique(array_column($show->json('data.steps'), 'status')))->toBe(['succeeded'])
            ->and(array_column($show->json('data.steps'), 'resumable'))->toBe([true, true, false, true, true, true, false, true])
            ->and($deployment->status)->toBe('succeeded')
            ->and($deployment->triggered_by)->toBe($this->fixture->caller->name)
            ->and(array_column($deployment->events ?? [], 'type'))->toContain('phase', 'output')
            ->and(Operation::query()->sole()->lock_key)->toBeNull();
    });

    it('keeps a bounded UTF-8 tail of a step that wrote more than 8 KiB', function (): void {
        $id = start_deploy($this)->json('data.id');
        run_operation_jobs();

        $prepare = collect(($this->asCaller)()->getJson("/api/v1/operations/{$id}")->json('data.steps'))->firstWhere('name', 'before_activation.prepare');

        expect($prepare['output_truncated'])->toBeTrue()
            ->and(mb_check_encoding($prepare['output'], 'UTF-8'))->toBeTrue()
            ->and($prepare['output'])->toContain("\u{FFFD}");
    });

    it('returns only the steps that changed after a sequence', function (): void {
        $id = start_deploy($this)->json('data.id');
        run_operation_jobs();
        $full = ($this->asCaller)()->getJson("/api/v1/operations/{$id}")->json('data');
        $cleanup = collect($full['steps'])->firstWhere('name', 'cleanup');

        $delta = ($this->asCaller)()->getJson("/api/v1/operations/{$id}?after_sequence=".($cleanup['sequence'] - 1));

        $delta->assertOk()->assertJsonPath('data.sequence', $full['sequence']);
        expect(array_column($delta->json('data.steps'), 'name'))->toBe(['cleanup'])
            ->and(($this->asCaller)()->getJson("/api/v1/operations/{$id}?after_sequence={$full['sequence']}")->json('data.steps'))->toBe([]);
    });

    it('fails at the step that failed with the deployment error code', function (): void {
        $this->fixture->deployment->failPreparation = true;
        $id = start_deploy($this)->json('data.id');
        run_operation_jobs();

        $show = ($this->asCaller)()->getJson("/api/v1/operations/{$id}");
        $show->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failed_step', 'source_preparation')
            ->assertJsonPath('data.error_code', 'deployment.prepare_failed')
            ->assertJsonPath('data.result.selected_release', 'initial');

        $steps = collect($show->json('data.steps'))->keyBy('name');
        expect($steps['source_preparation']['status'])->toBe('failed')
            ->and($steps['source_preparation']['error_code'])->toBe('deployment.prepare_failed')
            ->and($steps['environment_sync']['status'])->toBe('pending')
            ->and(InstanceDeployment::query()->sole()->failed_step)->toBe('preparation')
            ->and(Operation::query()->sole()->lock_key)->toBeNull();

        start_deploy($this)->assertAccepted();
    });

    it('keeps the cleanup reserve, so a deploy that runs out of time in activation still records the release it selected', function (): void {
        $now = 1_000.0;
        $deadline = new CommandDeadline(static function () use (&$now): float {
            return $now;
        });
        app()->instance(CommandDeadline::class, $deadline);
        $this->fixture->deployment->readsAsRemoteCommand = true;
        // The switch of `current` is a remote command that runs until the forward deadline stops it.
        $this->fixture->deployment->duringActivation = static function () use (&$now, $deadline): void {
            $now = 1_930.0;
            $now += $deadline->cap(30.0);

            throw $deadline->exceeded();
        };
        $id = start_deploy($this)->json('data.id');
        run_operation_jobs();

        $operation = Operation::query()->findOrFail($id);
        expect($operation->status)->toBe(OperationStatus::Failed)
            ->and($operation->failed_step)->toBe('activation')
            ->and($operation->error_code)->toBe('deployment.deadline_exceeded')
            ->and($operation->result['selected_release'] ?? null)->toBe('fresh')
            ->and(InstanceDeployment::query()->sole()->selected_release)->toBe('fresh');
    });

    it('a second copy of the job does nothing', function (): void {
        $operation = Operation::query()->findOrFail(start_deploy($this)->json('data.id'));
        app(OperationDispatcher::class)->dispatch($operation);

        expect(run_operation_jobs())->toBe(2)
            ->and($this->fixture->deployment->invocations)->toBe(1)
            ->and($operation->refresh()->attempts)->toBe(1);
    });

    it('broadcasts each change on the operation channel and status changes on orbit, without output', function (): void {
        Event::fake([RecordBroadcast::class]);
        $id = start_deploy($this)->json('data.id');
        run_operation_jobs();

        $events = collect(Event::dispatched(RecordBroadcast::class))->map(static fn (array $arguments): RecordBroadcast => $arguments[0])
            ->filter(static fn (RecordBroadcast $event): bool => str_starts_with($event->type->value, 'operation.'));
        $private = $events->filter(static fn (RecordBroadcast $event): bool => $event->channel === "operation.{$id}");
        $orbit = $events->filter(static fn (RecordBroadcast $event): bool => $event->channel === 'orbit');
        $sequences = $private->map(static fn (RecordBroadcast $event): int => $event->data['sequence'])->values()->all();

        expect($orbit->map(static fn (RecordBroadcast $event): string => $event->type->value.':'.$event->data['status'])->values()->all())
            ->toBe(['operation.created:queued', 'operation.updated:running', 'operation.updated:succeeded'])
            ->and($sequences)->toBe(array_values(array_unique($sequences)))
            ->and($sequences)->toBe(collect($sequences)->sort()->values()->all())
            ->and(array_keys($private->first()->data))->toBe(['id', 'kind', 'target_type', 'target_id', 'status', 'sequence', 'current_step'])
            ->and($private->last()->data['sequence'])->toBe(Operation::query()->sole()->sequence);
    });
});

describe('cancelling', function (): void {
    it('cancels a queued operation at once and frees the Instance', function (): void {
        $id = start_deploy($this)->json('data.id');

        ($this->asCaller)()->call('POST', "/api/v1/operations/{$id}/cancel", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_requested', true);

        expect(run_operation_jobs())->toBe(1)
            ->and($this->fixture->deployment->invocations)->toBe(0)
            ->and(Operation::query()->sole()->lock_key)->toBeNull()
            ->and(InstanceDeployment::query()->count())->toBe(0);
    });

    it('stops a running deploy before its next step', function (): void {
        $id = start_deploy($this)->json('data.id');
        $this->fixture->deployment->duringPrepare = static function () use ($id): void {
            app(CancelOperationAction::class)->execute(Operation::query()->findOrFail($id));
        };
        run_operation_jobs();

        $operation = Operation::query()->sole();
        $steps = $operation->steps()->get()->keyBy('name');

        expect($operation->status)->toBe(OperationStatus::Cancelled)
            ->and($steps['source_preparation']->status)->toBe(OperationStepStatus::Succeeded)
            ->and($steps['environment_sync']->status)->toBe(OperationStepStatus::Cancelled)
            ->and($steps['cleanup']->status)->toBe(OperationStepStatus::Cancelled)
            ->and($this->fixture->deployment->activations)->toBe(0)
            ->and(InstanceDeployment::query()->sole()->error_code)->toBe('deployment.cancelled');
    });

    it('finishes a deploy whose new release is already live when the cancel arrives during activation', function (): void {
        $id = start_deploy($this)->json('data.id');
        $this->fixture->deployment->duringActivation = static function () use ($id): void {
            app(CancelOperationAction::class)->execute(Operation::query()->findOrFail($id));
        };
        run_operation_jobs();

        $operation = Operation::query()->sole();
        expect($operation->status)->toBe(OperationStatus::Succeeded)
            ->and($operation->cancel_requested_at)->not->toBeNull()
            ->and($operation->steps()->pluck('status', 'name')->map(static fn (OperationStepStatus $status): string => $status->value)->unique()->values()->all())->toBe(['succeeded'])
            ->and($this->fixture->deployment->steps)->toBe(['prepare', 'finish'])
            ->and(InstanceDeployment::query()->sole()->status)->toBe('succeeded');
    });

    it('leaves a finished operation as it is', function (): void {
        $id = start_deploy($this)->json('data.id');
        run_operation_jobs();
        $sequence = Operation::query()->sole()->sequence;

        ($this->asCaller)()->call('POST', "/api/v1/operations/{$id}/cancel", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.status', 'succeeded');

        expect(Operation::query()->sole()->sequence)->toBe($sequence);
    });
});

describe('reading operations', function (): void {
    it('lists operations newest first with filters and without steps', function (): void {
        $first = start_deploy($this)->json('data.id');
        run_operation_jobs();
        $second = start_deploy($this)->json('data.id');

        ($this->asCaller)()->getJson('/api/v1/operations')->assertOk()
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.1.id', $first)
            ->assertJsonMissingPath('data.0.steps');
        ($this->asCaller)()->getJson('/api/v1/operations?status=queued')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second);
        ($this->asCaller)()->getJson("/api/v1/operations?target_type=instance&target_id={$this->fixture->instance->id}&kind=instance.deploy")
            ->assertOk()->assertJsonCount(2, 'data');
        ($this->asCaller)()->getJson('/api/v1/operations?status=sleeping')->assertStatus(422);
    });

    it('lists nothing for a well-formed kind or target type that no operation has', function (string $query): void {
        start_deploy($this);

        ($this->asCaller)()->getJson("/api/v1/operations?{$query}")->assertOk()->assertJsonPath('data', []);
    })->with([
        'kind' => 'kind=instance.rollback',
        'target type' => 'target_type=route',
    ]);

    it('refuses a kind or target type in another form', function (string $query, string $field, string $message): void {
        ($this->asCaller)()->getJson("/api/v1/operations?{$query}")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath("error.details.{$field}.0", $message);
    })->with([
        'kind without a verb' => ['kind=instance', 'kind', 'The kind field format is invalid.'],
        'kind in upper case' => ['kind=Instance.Deploy', 'kind', 'The kind field format is invalid.'],
        'kind with three parts' => ['kind=instance.deploy.now', 'kind', 'The kind field format is invalid.'],
        'kind longer than its column' => ['kind=instance.'.str_repeat('a', 56), 'kind', 'The kind field must not be greater than 64 characters.'],
        'target type with a verb' => ['target_type=instance.deploy', 'target_type', 'The target type field format is invalid.'],
        'target type in upper case' => ['target_type=Instance', 'target_type', 'The target type field format is invalid.'],
        'target type longer than its column' => ['target_type='.str_repeat('a', 33), 'target_type', 'The target type field must not be greater than 32 characters.'],
    ]);

    it('needs access to the target Node, and lists only operations the caller can access', function (): void {
        $id = start_deploy($this)->json('data.id');
        $stranger = Node::query()->create([
            'name' => 'stranger',
            'status' => 'active',
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.230',
            'wireguard_ip' => '10.44.0.230',
            'user' => 'orbit',
        ]);
        $other = Node::query()->create([
            'name' => 'other',
            'status' => 'active',
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.231',
            'wireguard_ip' => '10.44.0.231',
            'user' => 'orbit',
        ]);
        $stranger->accessibleNodes()->attach($other->id);

        $this->withServerVariables(['REMOTE_ADDR' => $stranger->wireguard_ip])->getJson("/api/v1/operations/{$id}")
            ->assertStatus(403)->assertJsonPath('error.code', 'node_access.required');
        $this->withServerVariables(['REMOTE_ADDR' => $stranger->wireguard_ip])->getJson('/api/v1/operations')
            ->assertOk()->assertJsonPath('data', []);
        ($this->asCaller)()->getJson('/api/v1/operations/999999')->assertNotFound();
    });
});
