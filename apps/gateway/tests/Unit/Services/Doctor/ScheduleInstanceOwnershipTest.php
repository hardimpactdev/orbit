<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Schedule;
use App\Services\Doctor\DoctorReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('derives schedule doctor scope from every concrete app instance placement', function (): void {
    $node = Node::factory()->create([
        'name' => 'static-app-host',
        'status' => 'active',
    ]);
    $app = Project::factory()->static()->for($node, 'node')->create(['name' => 'docs']);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/srv/docs-production',
        ),
    ]);
    Schedule::factory()->forAppInstance($instance)->create();

    expect(app(DoctorReportRunner::class)->categoriesForNode($node))
        ->toContain('schedule');
});
