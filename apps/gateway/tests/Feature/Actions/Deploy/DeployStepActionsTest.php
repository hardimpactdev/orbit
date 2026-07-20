<?php

declare(strict_types=1);

use App\Actions\Deploy\AddDeployStep;
use App\Actions\Deploy\RemoveDeployStep;
use App\Models\AppInstance;
use App\Models\DeployStep;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('inserts deploy steps at the requested order and compacts after removal', function (): void {
    $app = Project::factory()->create();
    $instance = AppInstance::factory()->create(['app_id' => $app->id]);
    $addStep = app(AddDeployStep::class);
    $removeStep = app(RemoveDeployStep::class);

    $addStep->handle(
        appInstanceId: $instance->id,
        title: 'Install dependencies',
        command: 'composer install',
        timeoutSeconds: DeployStep::DEFAULT_TIMEOUT_SECONDS,
    );
    $second = $addStep->handle(
        appInstanceId: $instance->id,
        title: 'Run migrations',
        command: 'php artisan migrate --force',
        timeoutSeconds: 300,
    );
    $inserted = $addStep->handle(
        appInstanceId: $instance->id,
        title: 'Build assets',
        command: 'npm run build',
        timeoutSeconds: 120,
        order: $second->sort_order,
        retention: 5,
    );

    expect(
        DeployStep::query()
            ->where('app_instance_id', $instance->id)
            ->orderBy('sort_order')
            ->pluck('title', 'sort_order')
            ->all(),
    )
        ->toBe([
            1 => 'Install dependencies',
            2 => 'Build assets',
            3 => 'Run migrations',
        ])
        ->and($inserted->retention)
        ->toBe(5);

    $removeStep->handle($inserted);

    expect(
        DeployStep::query()
            ->where('app_instance_id', $instance->id)
            ->orderBy('sort_order')
            ->pluck('sort_order', 'title')
            ->all(),
    )->toBe([
        'Install dependencies' => 1,
        'Run migrations' => 2,
    ]);
});
