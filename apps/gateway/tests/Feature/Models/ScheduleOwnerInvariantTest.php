<?php

declare(strict_types=1);

use App\Exceptions\ScheduleOwnerInvariantViolation;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\ScheduleRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists an orbit schedule with no owner foreign keys and a derived gateway target', function (): void {
    $schedule = Schedule::factory()
        ->orbit()
        ->create([
            'name' => 'gateway-maintenance',
            'schedule_key' => 'orbit:gateway:gateway-maintenance',
        ]);

    expect($schedule->scope)
        ->toBe('orbit')
        ->and($schedule->instance_id)
        ->toBeNull()
        ->and($schedule->node_id)
        ->toBeNull()
        ->and($schedule->liveTargetName())
        ->toBe('gateway')
        ->and($schedule->target_name)
        ->toBe('gateway')
        ->and($schedule->schedule_key)
        ->toBe('orbit:gateway:gateway-maintenance')
        ->and($schedule->app)
        ->toBeNull();
});

it('persists a node schedule with only node_id and a derived node target', function (): void {
    $node = Node::factory()->create(['name' => 'app-1']);

    $schedule = Schedule::factory()
        ->forNode($node)
        ->create([
            'name' => 'backup',
            'schedule_key' => 'node:app-1:backup',
        ]);

    expect($schedule->scope)
        ->toBe('node')
        ->and($schedule->node?->is($node))
        ->toBeTrue()
        ->and($schedule->instance_id)
        ->toBeNull()
        ->and($schedule->liveTargetName())
        ->toBe('app-1')
        ->and($schedule->target_name)
        ->toBe('app-1')
        ->and($schedule->schedule_key)
        ->toBe('node:app-1:backup')
        ->and($schedule->app)
        ->toBeNull();
});

it('persists an instance schedule with only instance_id and derives App plus the live target', function (): void {
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create(['name' => 'production']);

    $schedule = Schedule::factory()
        ->forInstance($instance)
        ->create([
            'name' => 'laravel-scheduler',
            'schedule_key' => 'instance:docs.production:laravel-scheduler',
        ]);

    expect($schedule->scope)
        ->toBe('instance')
        ->and($schedule->instance?->is($instance))
        ->toBeTrue()
        ->and($schedule->node_id)
        ->toBeNull()
        ->and($schedule->app?->is($app))
        ->toBeTrue()
        ->and($app->schedules()->first()?->is($schedule))
        ->toBeTrue()
        ->and($schedule->liveTargetName())
        ->toBe('docs.production')
        ->and($schedule->target_name)
        ->toBe('docs.production')
        ->and($schedule->schedule_key)
        ->toBe('instance:docs.production:laravel-scheduler');
});

it('rejects contradictory schedule owners', function (string $name, Closure $factory): void {
    expect($factory)->toThrow(ScheduleOwnerInvariantViolation::class);
})->with([
    'orbit-with-node_id' => [
        'orbit-with-node_id',
        function (): void {
            $node = Node::factory()->create();
            Schedule::factory()
                ->orbit()
                ->create([
                    'node_id' => $node->id,
                ]);
        },
    ],
    'orbit-with-instance_id' => [
        'orbit-with-instance_id',
        function (): void {
            $instance = Instance::factory()->create();
            Schedule::factory()
                ->orbit()
                ->create([
                    'instance_id' => $instance->id,
                ]);
        },
    ],
    'node-with-instance_id' => [
        'node-with-instance_id',
        function (): void {
            $node = Node::factory()->create();
            $instance = Instance::factory()->create();
            Schedule::factory()
                ->forNode($node)
                ->create([
                    'instance_id' => $instance->id,
                ]);
        },
    ],
    'instance-with-node_id' => [
        'instance-with-node_id',
        function (): void {
            $instance = Instance::factory()->create();
            $node = Node::factory()->create();
            Schedule::factory()
                ->forInstance($instance)
                ->create([
                    'node_id' => $node->id,
                ]);
        },
    ],
    'two-owners' => [
        'two-owners',
        function (): void {
            $instance = Instance::factory()->create();
            $node = Node::factory()->create();
            Schedule::factory()->create([
                'scope' => 'instance',
                'instance_id' => $instance->id,
                'node_id' => $node->id,
            ]);
        },
    ],
    'wrong-scope-for-fk' => [
        'wrong-scope-for-fk',
        function (): void {
            $instance = Instance::factory()->create();
            Schedule::factory()->create([
                'scope' => 'node',
                'instance_id' => $instance->id,
                'node_id' => null,
            ]);
        },
    ],
    'legacy-app-scope' => [
        'legacy-app-scope',
        function (): void {
            $instance = Instance::factory()->create();
            Schedule::factory()->create([
                'scope' => 'app',
                'instance_id' => $instance->id,
                'node_id' => null,
            ]);
        },
    ],
]);

it('keeps lock identity aligned to the closed owner per scope', function (): void {
    $node = Node::factory()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create(['name' => 'production']);

    $instanceSchedule = Schedule::factory()
        ->forInstance($instance)
        ->create([
            'name' => 'nightly',
            'schedule_key' => 'instance:docs.production:nightly',
        ]);
    $nodeSchedule = Schedule::factory()
        ->forNode($node)
        ->create([
            'name' => 'backup',
            'schedule_key' => 'node:app-1:backup',
        ]);
    $orbitSchedule = Schedule::factory()
        ->orbit()
        ->create([
            'name' => 'maintenance',
            'schedule_key' => 'orbit:gateway:maintenance',
        ]);

    $instanceLock = ScheduleLock::factory()->create(['schedule_key' => $instanceSchedule->schedule_key]);
    $nodeLock = ScheduleLock::factory()->create(['schedule_key' => $nodeSchedule->schedule_key]);
    $orbitLock = ScheduleLock::factory()->create(['schedule_key' => $orbitSchedule->schedule_key]);

    expect($instanceSchedule->schedule_key)
        ->toBe('instance:docs.production:nightly')
        ->and($nodeSchedule->schedule_key)
        ->toBe('node:app-1:backup')
        ->and($orbitSchedule->schedule_key)
        ->toBe('orbit:gateway:maintenance')
        ->and($instanceLock->schedule_key)
        ->toBe($instanceSchedule->schedule_key)
        ->and($nodeLock->schedule_key)
        ->toBe($nodeSchedule->schedule_key)
        ->and($orbitLock->schedule_key)
        ->toBe($orbitSchedule->schedule_key)
        ->and(ScheduleLock::query()->pluck('schedule_key')->sort()->values()->all())
        ->toBe([
            'instance:docs.production:nightly',
            'node:app-1:backup',
            'orbit:gateway:maintenance',
        ]);
});

it('retains historical run labels after the owner instance is deleted', function (): void {
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create(['name' => 'development']);
    $schedule = Schedule::factory()
        ->forInstance($instance)
        ->create([
            'name' => 'nightly',
            'schedule_key' => 'instance:docs.development:nightly',
        ]);

    $run = ScheduleRun::factory()->create([
        'schedule_key' => $schedule->schedule_key,
        'target_name' => $schedule->liveTargetName(),
    ]);

    $instance->delete();

    expect(Schedule::query()->find($schedule->id))
        ->toBeNull()
        ->and($run->fresh())
        ->not
        ->toBeNull()
        ->and($run->fresh()?->schedule_key)
        ->toBe('instance:docs.development:nightly')
        ->and($run->fresh()?->target_name)
        ->toBe('docs.development');
});
