<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\AppInstance;
use App\Services\Apps\AppInstancePayloads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('publishes deployment state from the concrete app instance', function (): void {
    $app = App::factory()->create(['name' => 'docs']);
    $instance = AppInstance::factory()->create([
        'app_id' => $app->id,
        'name' => 'production',
        'deploy_warmup_paths' => ['/health'],
        'latest_deployment_status' => 'completed',
        'latest_deployment_run_id' => 42,
    ]);

    expect(app(AppInstancePayloads::class)->instance($instance))
        ->toMatchArray([
            'app' => 'docs',
            'name' => 'production',
            'deploy_warmup_paths' => ['/health'],
            'latest_deployment_status' => 'completed',
            'latest_deployment_run_id' => 42,
        ]);
});
