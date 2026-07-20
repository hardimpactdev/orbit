<?php

declare(strict_types=1);

use App\Models\AppInstance;
use App\Models\Project;
use App\Services\Apps\AppInstancePayloads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('publishes deployment state from the concrete app instance', function (): void {
    $app = Project::factory()->create(['name' => 'docs']);
    $instance = AppInstance::factory()->create([
        'app_id' => $app->id,
        'name' => 'production',
        'adopted' => true,
        'deploy_warmup_paths' => ['/health'],
        'latest_deployment_status' => 'completed',
        'latest_deployment_run_id' => 42,
        'worker_enabled' => true,
        'worker_config' => ['workers' => 4, 'max_requests' => 500],
    ]);

    $payload = app(AppInstancePayloads::class)->instance($instance);

    expect($payload)
        ->toMatchArray([
            'project' => 'docs',
            'name' => 'production',
            'adopted' => true,
            'deploy_warmup_paths' => ['/health'],
            'latest_deployment_status' => 'completed',
            'latest_deployment_run_id' => 42,
            'worker_enabled' => true,
            'worker_config' => ['workers' => 4, 'max_requests' => 500],
        ])
        ->and($payload['runtime']['mode'])
        ->toBe('worker');
});
