<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Instance;
use App\Services\Apps\InstancePayloads;
use App\Services\Deploy\DeploymentRunLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('publishes deployment state from the concrete app instance', function (): void {
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'production',
        'adopted' => true,
        'deploy_warmup_paths' => ['/health'],
        'worker_enabled' => true,
        'worker_config' => ['workers' => 4, 'max_requests' => 500],
    ]);
    $run = app(DeploymentRunLifecycle::class)->completed(
        app(DeploymentRunLifecycle::class)->start($instance, Carbon::now()),
    );

    $payload = app(InstancePayloads::class)->instance($instance);

    expect($payload)
        ->toMatchArray([
            'app' => 'docs',
            'name' => 'production',
            'adopted' => true,
            'deploy_warmup_paths' => ['/health'],
            'latest_deployment_status' => 'completed',
            'latest_deployment_run_id' => $run->id,
            'worker_enabled' => true,
            'worker_config' => ['workers' => 4, 'max_requests' => 500],
        ])
        ->and($payload['runtime']['mode'])
        ->toBe('worker');
});

it('publishes null deployment state when the instance has no runs', function (): void {
    $instance = Instance::factory()->create();

    $payload = app(InstancePayloads::class)->instance($instance);

    expect($payload['latest_deployment_status'])
        ->toBeNull()
        ->and($payload['latest_deployment_run_id'])
        ->toBeNull();
});

it('uses an eager loaded latest run without adding payload queries', function (): void {
    $instance = Instance::factory()->create();
    app(DeploymentRunLifecycle::class)->failed(
        app(DeploymentRunLifecycle::class)->start($instance, Carbon::now()),
    );
    $instance = Instance::query()
        ->with(['app', 'runtimeMounts', 'latestDeploymentRun'])
        ->findOrFail($instance->id);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $payload = app(InstancePayloads::class)->instance($instance);
    $queries = DB::getQueryLog();

    DB::disableQueryLog();

    expect($payload['latest_deployment_status'])
        ->toBe('failed')
        ->and($queries)
        ->toBeEmpty();
});
