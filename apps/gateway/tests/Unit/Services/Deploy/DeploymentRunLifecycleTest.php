<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Instance;
use App\Services\Deploy\DeploymentRunLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('keeps the newest deployment run authoritative when an older run finishes later', function (): void {
    $app = App::factory()->create();
    $instance = Instance::factory()->create(['app_id' => $app->id]);
    $lifecycle = app(DeploymentRunLifecycle::class);

    $olderRun = $lifecycle->start($instance, Carbon::now());
    $newerRun = $lifecycle->start($instance, Carbon::now());

    $completedNewerRun = $lifecycle->completed($newerRun);
    $failedOlderRun = $lifecycle->failed($olderRun);

    expect($completedNewerRun->status)
        ->toBe('completed')
        ->and($failedOlderRun->status)
        ->toBe('failed')
        ->and($instance->refresh()->latest_deployment_run_id)
        ->toBe($newerRun->id)
        ->and($instance->latest_deployment_status)
        ->toBe('completed');
});

it('does not replace a terminal deployment run result', function (): void {
    $app = App::factory()->create();
    $instance = Instance::factory()->create(['app_id' => $app->id]);
    $lifecycle = app(DeploymentRunLifecycle::class);

    $run = $lifecycle->completed($lifecycle->start($instance, Carbon::now()));
    $sameRun = $lifecycle->failed($run);

    expect($sameRun->status)
        ->toBe('completed')
        ->and($sameRun->exit_code)
        ->toBe(0)
        ->and($instance->refresh()->latest_deployment_status)
        ->toBe('completed');
});
