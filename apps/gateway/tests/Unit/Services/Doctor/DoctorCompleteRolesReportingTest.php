<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Doctor\DoctorProgressReportFactory;
use App\Services\Doctor\DoctorReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('exposes all active roles in stable order on single-node doctor scope', function (): void {
    $node = Node::factory()->create(['name' => 'gateway-agent-1', 'status' => 'active']);
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::Agent->value,
        'status' => NodeRoleStatus::Active,
    ]);
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::Gateway->value,
        'status' => NodeRoleStatus::Active,
    ]);

    $report = app(DoctorProgressReportFactory::class)->report(
        target: $node,
        mode: 'verify',
        families: ['node', 'tool'],
        key: null,
        issues: [],
        actions: [],
        familyStatuses: ['node' => 'queued', 'tool' => 'queued'],
    );

    expect($report['scope']['role'])->toBe('agent')
        ->and($report['scope']['roles'])->toBe(['agent', 'gateway']);
});

it('exposes complete roles on fleet node summaries while keeping role=fleet on fleet scope', function (): void {
    $node = Node::factory()->create(['name' => 'multi-role-1', 'status' => 'active']);
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::AppDevelopment->value,
        'status' => NodeRoleStatus::Active,
    ]);
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::Database->value,
        'status' => NodeRoleStatus::Active,
    ]);

    $runner = app(DoctorReportRunner::class);
    $method = new ReflectionMethod(DoctorReportRunner::class, 'fleetNodeSummary');
    $method->setAccessible(true);

    $summary = $method->invoke($runner, $node, [
        'healthy' => true,
        'summary' => ['issues' => 0],
        'scope' => ['families' => ['node']],
    ]);

    expect($summary['role'])->toBe('app-dev')
        ->and($summary['roles'])->toBe(['app-dev', 'database'])
        ->and($summary['node'])->toBe('multi-role-1');
});
