<?php

declare(strict_types=1);

use App\Models\DeploymentRun;
use App\Models\Instance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('has no latest deployment run before the first deployment starts', function (): void {
    $instance = Instance::factory()->create();

    expect($instance->latestDeploymentRun)->toBeNull();
});

it('uses the greatest run id to break latest deployment timestamp ties', function (): void {
    Carbon::setTestNow('2026-08-15 12:00:00');

    try {
        $instance = Instance::factory()->create();
        $olderRun = DeploymentRun::query()->create([
            'instance_id' => $instance->id,
            'status' => 'completed',
            'exit_code' => 0,
            'started_at' => Carbon::now(),
        ]);
        $newerRun = DeploymentRun::query()->create([
            'instance_id' => $instance->id,
            'status' => 'failed',
            'exit_code' => 1,
            'started_at' => Carbon::now(),
        ]);
    } finally {
        Carbon::setTestNow();
    }

    expect($olderRun->created_at)
        ->toEqual($newerRun->created_at)
        ->and($instance->latestDeploymentRun->is($newerRun))
        ->toBeTrue()
        ->and($instance->latestDeploymentRun->status)
        ->toBe('failed');
});

it('eager loads latest deployment runs for many instances in one relation query', function (): void {
    $first = Instance::factory()->create();
    $second = Instance::factory()->create();

    DeploymentRun::query()->create([
        'instance_id' => $first->id,
        'status' => 'completed',
        'exit_code' => 0,
        'started_at' => Carbon::now(),
    ]);
    DeploymentRun::query()->create([
        'instance_id' => $second->id,
        'status' => 'failed',
        'exit_code' => 1,
        'started_at' => Carbon::now(),
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $instances = Instance::query()
        ->with('latestDeploymentRun')
        ->whereKey([$first->id, $second->id])
        ->orderBy('id')
        ->get();
    $statuses = $instances->pluck('latestDeploymentRun.status')->all();
    $queries = DB::getQueryLog();

    DB::disableQueryLog();

    expect($queries)
        ->toHaveCount(2)
        ->and($instances->every->relationLoaded('latestDeploymentRun'))
        ->toBeTrue()
        ->and($statuses)
        ->toBe(['completed', 'failed']);
});
