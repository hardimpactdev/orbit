<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Instances\InstanceRemovalKind;
use App\Domain\Instances\InstanceRemovalReaper;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Schedules\NativeSchedules;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-root-certificate');
    $this->app->instance(RemoteProgramRunner::class, Mockery::mock(RemoteProgramRunner::class, fn ($mock) => $mock->shouldReceive('run')
        ->andReturn(new ProgramResult('inspected', ['dependents' => []], ''))));
    app()->instance(NativeSchedules::class, new class
    {
        /** @param list<int> $instanceIds */
        public function removeForInstances(array $instanceIds): void {}
    });
    $this->root = sys_get_temp_dir().'/orbit-reaper-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/apps/fixture/default/.git', 0o755, true);
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'reaper-node', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key', 'ssh_host_fingerprint' => 'fixture']));
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-control-key']));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $this->project = Project::query()->create(['name' => 'Reaper fixture', 'slug' => 'reaper-fixture', 'type' => 'node-package',
        'repository_url' => 'https://github.com/example/reaper-fixture.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'default',
        'checkout_path' => $this->root.'/apps/fixture/default', 'status' => 'active', 'source_layout' => 'checkout', 'root' => 'public',
        'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    // Ends a removal run as failed at the runtime cleanup, as the projection of a failed run records it.
    $this->fail = function (Operation $operation): void {
        InstanceRemoval::query()->findOrFail($operation->input['removal_id'])->update(['status' => InstanceRemovalStatus::Failed,
            'current_step' => InstanceRemovalStep::RuntimeCleanup, 'failed_step' => InstanceRemovalStep::RuntimeCleanup, 'error_code' => 'instance.removal_incomplete']);
        $operation->update(['status' => OperationStatus::Failed, 'finished_at' => now()]);
        app(RoutePublicationHolds::class)->release($operation->id);
    };
    // The destroy that the operator ordered and that failed.
    $this->failed = function (bool $force = true) {
        $operation = app(StartInstanceDestroyAction::class)->execute($this->instance->fresh(), $force);
        ($this->fail)($operation);

        return InstanceRemoval::query()->findOrFail($operation->input['removal_id']);
    };
    $this->reap = fn (): array => app(InstanceRemovalReaper::class)->run();
    $this->stuckIssues = fn (): array => array_values(array_filter(
        app(FactDoctor::class)->report([$this->node->fresh()], [DoctorFamily::Instance])->nodes[0]->families[0]->issues,
        static fn ($issue): bool => $issue->code === 'instance.removal_stuck'));
    $this->refusals = fn (): array => array_values(array_filter(
        app(FactDoctor::class)->report([$this->node->fresh()], [DoctorFamily::Instance])->nodes[0]->families[0]->issues,
        static fn ($issue): bool => $issue->code === 'instance.removal_retry_refused'));
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->root);
});

describe('a failed destroy', function (): void {
    it('starts again with the same removal and force, keyed by the removal and its retry count', function (): void {
        $removal = ($this->failed)(true);
        $this->travel(5)->minutes();

        $started = ($this->reap)();

        expect($started)->toHaveCount(1)
            ->and($started[0]->kind)->toBe('instance.destroy')
            ->and($started[0]->input['removal_id'])->toBe($removal->id)
            ->and($started[0]->input['force'])->toBeTrue()
            ->and($started[0]->triggered_by)->toBe(InstanceRemovalReaper::Trigger)
            ->and($started[0]->idempotency_key)->toBe("removal-reaper:{$removal->id}:0")
            ->and($removal->fresh()->retry_count)->toBe(1)
            ->and($removal->fresh()->status)->toBe(InstanceRemovalStatus::Removing);
        expect(($this->reap)())->toBe([]);
    });

    it('waits 5 minutes after the failure, then 15 minutes after the first retry failed', function (): void {
        $removal = ($this->failed)();
        $this->travel(4)->minutes();
        expect(($this->reap)())->toBe([]);
        $this->travel(1)->minutes();
        $first = ($this->reap)();
        expect($first)->toHaveCount(1);

        ($this->fail)($first[0]);
        $this->travel(14)->minutes();
        expect(($this->reap)())->toBe([])->and($removal->fresh()->retry_count)->toBe(1);
        $this->travel(1)->minutes();
        $second = ($this->reap)();
        expect($second)->toHaveCount(1)->and($second[0]->idempotency_key)->toBe("removal-reaper:{$removal->id}:1")
            ->and($removal->fresh()->retry_count)->toBe(2);
    });

    it('defines the backoff in one place', function (): void {
        expect(array_map(InstanceRemovalReaper::delayMinutes(...), [0, 1, 2, 3, 4, 9]))->toBe([5, 15, 60, 360, 360, 360])
            ->and(InstanceRemovalReaper::MaxRetries)->toBe(3);
    });
});

describe('the reaper never starts', function (): void {
    it('while an Operation runs for the Instance', function (): void {
        ($this->failed)();
        $this->travel(5)->minutes();
        Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $this->instance->id,
            'node_id' => $this->node->id, 'input' => [], 'status' => OperationStatus::Running, 'state' => [], 'sequence' => 1, 'attempts' => 0]);

        expect(($this->reap)())->toBe([]);
    });

    it('while another Operation holds the Instance lock, which admission would cancel', function (): void {
        $removal = ($this->failed)();
        $this->travel(5)->minutes();
        Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'node', 'target_id' => $this->node->id,
            'node_id' => $this->node->id, 'lock_key' => 'instance:'.$this->instance->id, 'input' => [], 'status' => OperationStatus::Running,
            'state' => [], 'sequence' => 1, 'attempts' => 0]);

        expect(($this->reap)())->toBe([])->and($removal->fresh()->retry_count)->toBe(0);
    });

    it('while the operator turned it off', function (): void {
        $removal = ($this->failed)();
        $this->travel(5)->minutes();
        config(['instances.removal_reaper' => false]);

        expect(($this->reap)())->toBe([])->and($removal->fresh()->retry_count)->toBe(0);
        config(['instances.removal_reaper' => true]);
        expect(($this->reap)())->toHaveCount(1);
    });

    it('for an Instance that no removal was ordered for', function (): void {
        $this->travel(1)->days();

        expect(($this->reap)())->toBe([])->and(Operation::query()->count())->toBe(0)
            ->and($this->instance->fresh()->status)->toBe(InstanceState::Active);
    });

    it('for an Instance in an open transfer', function (): void {
        ($this->failed)();
        $this->travel(5)->minutes();
        DB::table('instance_transfers')->insert(['instance_id' => $this->instance->id, ...reaperOpenTransfer($this->node->id, 1)]);

        expect(InstanceTransfer::query()->where('instance_id', $this->instance->id)->open()->exists())->toBeTrue()
            ->and(($this->reap)())->toBe([]);
    });

    it('for an unregister, or an abandoned or completed removal', function (string $case): void {
        $removal = ($this->failed)();
        $this->travel(5)->minutes();
        // Each case is set on the row past its immutable-contract trigger: only the reaper's reading of the row is under test.
        DB::statement('DROP TRIGGER IF EXISTS instance_removals_immutable');
        DB::table('instance_removals')->where('id', $removal->id)->update(match ($case) {
            'unregister' => ['kind' => InstanceRemovalKind::Unregister->value],
            'abandoned', 'completed' => ['status' => $case, 'current_step' => null, 'failed_step' => null, 'error_code' => null],
        });

        expect(($this->reap)())->toBe([]);
    })->with(['unregister', 'abandoned', 'completed']);
});

describe('a stuck removal', function (): void {
    it('raises the Doctor issue and records one Activity entry after three failed retries; the issue goes with the Instance', function (): void {
        $removal = ($this->failed)();
        foreach ([5, 15, 60] as $minutes) {
            $this->travel($minutes)->minutes();
            $started = ($this->reap)();
            expect($started)->toHaveCount(1)->and(($this->stuckIssues)())->toBe([]);
            ($this->fail)($started[0]);
        }
        expect($removal->fresh()->retry_count)->toBe(3);

        ($this->reap)();
        ($this->reap)();

        $activities = Activity::query()->where('description', 'instance.removal_stuck')->get();
        expect($activities)->toHaveCount(1)
            ->and($activities[0]->subject_id)->toBe($this->instance->id)
            ->and($activities[0]->properties['failed_step'])->toBe('runtime_cleanup')
            ->and($activities[0]->properties['removal_error_code'])->toBe('instance.removal_incomplete');
        $issues = ($this->stuckIssues)();
        expect($issues)->toHaveCount(1)
            ->and($issues[0]->resourceId)->toBe($this->instance->id)
            ->and($issues[0]->observed)->toBe('runtime_cleanup: instance.removal_incomplete');

        // After 6 hours the reaper tries again; the stuck report stays one entry.
        $this->travel(6)->hours();
        expect(($this->reap)())->toHaveCount(1)
            ->and(Activity::query()->where('description', 'instance.removal_stuck')->count())->toBe(1);

        // The Instance row goes, as a successful run's projection deletes it; the member contract is not under test here.
        DB::statement('DROP TRIGGER IF EXISTS instance_removal_members_immutable');
        DB::table('instance_removal_members')->where('instance_id', $this->instance->id)->update(['row_deleted_at' => now()]);
        Schema::withoutForeignKeyConstraints(fn () => DB::table('instances')->where('id', $this->instance->id)->delete());
        expect(($this->stuckIssues)())->toBe([]);
    });
});

describe('a failed retry', function (): void {
    it('counts a start that admission refuses, waits the next delay from it, and reports the removal stuck after three', function (): void {
        $removal = ($this->failed)();
        // A refusal that only admission sees: the Node lost its app-dev role, so the placement has no destroy flow.
        $this->node->roles()->delete();
        $this->travel(5)->minutes();
        expect(($this->reap)())->toBe([])->and($removal->fresh()->retry_count)->toBe(1);
        $this->travel(14)->minutes();
        expect(($this->reap)())->toBe([])->and($removal->fresh()->retry_count)->toBe(1);
        $this->travel(1)->minutes();
        expect(($this->reap)())->toBe([])->and($removal->fresh()->retry_count)->toBe(2);
        $this->travel(60)->minutes();
        expect(($this->reap)())->toBe([])->and($removal->fresh()->retry_count)->toBe(3)
            ->and(Activity::query()->where('description', 'instance.removal_stuck')->count())->toBe(0);

        // The next pass records the Activity entry; Doctor reads the count itself.
        ($this->reap)();

        expect(Operation::query()->where('triggered_by', InstanceRemovalReaper::Trigger)->exists())->toBeFalse()
            ->and($removal->fresh()->status)->toBe(InstanceRemovalStatus::Failed)
            ->and(($this->stuckIssues)())->toHaveCount(1)
            ->and(Activity::query()->where('description', 'instance.removal_stuck')->count())->toBe(1);
    });

    it('starts a failed removal that no Operation carries, counting from the removal\'s last change', function (): void {
        $removal = ($this->failed)();
        Schema::withoutForeignKeyConstraints(fn () => Operation::query()->where('input->removal_id', $removal->id)->delete());
        $this->travel(4)->minutes();
        expect(($this->reap)())->toBe([]);
        $this->travel(1)->minutes();

        $started = ($this->reap)();

        expect($started)->toHaveCount(1)->and($started[0]->input['removal_id'])->toBe($removal->id)
            ->and($removal->fresh()->retry_count)->toBe(1);
    });

    it('never starts a removal whose dataset another one holds, and reports it stuck at once', function (): void {
        $removal = ($this->failed)();
        $removal->update(['error_code' => 'instance.removal_dataset_taken']);
        $this->travel(1)->days();

        expect(($this->reap)())->toBe([])->and(($this->reap)())->toBe([])->and($removal->fresh()->retry_count)->toBe(0);
        $issues = ($this->stuckIssues)();
        expect($issues)->toHaveCount(1)->and($issues[0]->observed)->toBe('runtime_cleanup: instance.removal_dataset_taken')
            ->and(Activity::query()->where('description', 'instance.removal_stuck')->count())->toBe(1);
    });
});

describe('a refused start is visible at once', function (): void {
    it('is what admission does with a removal without saved intent: it refuses with instance.removal_conflict', function (): void {
        $removal = ($this->failed)(true);
        DB::statement('DROP TRIGGER IF EXISTS instance_removals_native_intent_immutable');
        DB::table('instance_removals')->where('id', $removal->id)->update(['native_intent' => null]);

        expect(fn () => app(StartInstanceDestroyAction::class)->execute($this->instance->fresh(), true, InstanceRemovalReaper::Trigger))
            ->toThrow(fn (ResourceOperationException $refusal) => expect($refusal->errorCode)->toBe('instance.removal_conflict'));
    });

    it('flags a removal without saved intent (shaped like 2186 from 12 November) stuck at once, and starts nothing', function (): void {
        $removal = ($this->failed)(true);
        // An older removal: failed at the runtime cleanup with force, but its row holds no saved intent, so admission can only
        // refuse it with instance.removal_conflict. The intent's contract trigger forbids the change; only the reaper's reading is
        // under test.
        DB::statement('DROP TRIGGER IF EXISTS instance_removals_native_intent_immutable');
        DB::table('instance_removals')->where('id', $removal->id)->update(['native_intent' => null]);
        $this->travel(5)->minutes();

        expect(($this->reap)())->toBe([])->and(($this->reap)())->toBe([]);

        $removal = $removal->fresh();
        expect($removal->start_refusal)->toBe('instance.removal_intent_missing')
            ->and($removal->retry_count)->toBe(0)
            ->and($removal->stuck_at)->not->toBeNull()
            ->and(Operation::query()->where('triggered_by', InstanceRemovalReaper::Trigger)->exists())->toBeFalse();
        $issues = ($this->stuckIssues)();
        expect($issues)->toHaveCount(1)
            ->and($issues[0]->observed)->toBe('runtime_cleanup: instance.removal_incomplete; start refused: instance.removal_intent_missing');
        $activities = Activity::query()->where('description', 'instance.removal_stuck')->get();
        expect($activities)->toHaveCount(1)
            ->and($activities[0]->properties['start_refusal'])->toBe('instance.removal_intent_missing');
        // A day later still nothing starts, and the report stays one entry.
        $this->travel(1)->days();
        expect(($this->reap)())->toBe([])->and(Activity::query()->where('description', 'instance.removal_stuck')->count())->toBe(1);
    });

    it('records each refused start in Activity and Doctor with its error code, and clears it once a start is admitted', function (): void {
        $removal = ($this->failed)();
        $this->node->roles()->delete();
        $this->travel(5)->minutes();

        expect(($this->reap)())->toBe([]);

        expect($removal->fresh()->start_refusal)->toBe('operation.kind_unavailable');
        $entries = Activity::query()->where('description', 'instance.removal_retry_refused')->get();
        expect($entries)->toHaveCount(1)
            ->and($entries[0]->error_code)->toBe('operation.kind_unavailable')
            ->and($entries[0]->status)->toBe('failed')
            ->and($entries[0]->command)->toBe(InstanceRemovalReaper::Trigger)
            ->and($entries[0]->subject_id)->toBe($this->instance->id)
            ->and($entries[0]->properties['removal_id'])->toBe($removal->id)
            ->and($entries[0]->properties['retry_count'])->toBe(1);
        $issues = ($this->refusals)();
        expect($issues)->toHaveCount(1)->and($issues[0]->resourceId)->toBe($this->instance->id)
            ->and($issues[0]->observed)->toBe('operation.kind_unavailable');

        // The Node has its role again: the next start is admitted, and the refusal goes from the removal and from Doctor.
        $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
        $this->travel(15)->minutes();
        expect(($this->reap)())->toHaveCount(1)
            ->and($removal->fresh()->start_refusal)->toBeNull()
            ->and(($this->refusals)())->toBe([]);
    });

    it('counts and records a start that fails with an unexpected error, not only in the log', function (): void {
        $removal = ($this->failed)();
        // Admission calls the Schedule removal before its transaction; here it fails as a bug in an older intent would.
        app()->instance(NativeSchedules::class, new class
        {
            /** @param list<int> $instanceIds */
            public function removeForInstances(array $instanceIds): void
            {
                throw new RuntimeException('Undefined array key "members"');
            }
        });
        $this->travel(5)->minutes();

        expect(($this->reap)())->toBe([]);

        expect($removal->fresh()->retry_count)->toBe(1)
            ->and($removal->fresh()->start_refusal)->toBe('instance.removal_retry_failed');
        $entry = Activity::query()->where('description', 'instance.removal_retry_refused')->sole();
        expect($entry->error_code)->toBe('instance.removal_retry_failed')
            ->and($entry->properties['message'])->toContain('Undefined array key');
        expect(($this->refusals)())->toHaveCount(1);
    });
});

it('registers the reaper on the schedule every five minutes without overlapping', function (): void {
    config(['queue.gateway.connection' => null]);
    Artisan::call('schedule:list');
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, InstanceRemovalReaper::Trigger)
        || $event->description === InstanceRemovalReaper::Trigger);

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/5 * * * *');
    if ($event->command !== null) {
        expect($event->withoutOverlapping)->toBeTrue();
    }
});

/** @return array<string, mixed> */
function reaperOpenTransfer(int $nodeId, int $routeId): array
{
    return ['id' => (string) Str::uuid(), 'source_node_id' => $nodeId, 'destination_node_id' => $nodeId,
        'destination_name' => 'moved', 'destination_path' => '/srv/moved', 'destination_domain' => 'moved.test', 'source_layout' => 'checkout',
        'source_path' => '/srv/default', 'source_route_id' => $routeId, 'status' => 'running', 'current_step' => 'source-captured'];
}
