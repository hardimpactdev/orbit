<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Doctor\DoctorFleetNodeProjection;
use App\Services\Doctor\DoctorProgressReportFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    expect($report)->toBe([
        'healthy' => false,
        'mode' => 'verify',
        'scope' => [
            'families' => ['node', 'tool'],
            'node' => 'gateway-agent-1',
            'role' => 'agent',
            'roles' => ['agent', 'gateway'],
            'self' => false,
            'app' => null,
            'instance' => null,
            'workspace' => null,
            'key' => null,
        ],
        'summary' => [
            'issues' => 0,
            'fixed' => 0,
            'adopted' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'failed' => 0,
            'planned' => 0,
        ],
        'issues' => [],
        'actions' => [],
        'progress' => [
            'state' => 'running',
            'families' => [
                ['family' => 'node', 'status' => 'queued'],
                ['family' => 'tool', 'status' => 'queued'],
            ],
        ],
    ]);
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

    $summary = app(DoctorFleetNodeProjection::class)->nodeSummary($node, [
        'healthy' => true,
        'summary' => ['issues' => 0],
        'scope' => ['families' => ['node']],
    ]);

    expect($summary['role'])
        ->toBe('app-dev')
        ->and($summary['roles'])
        ->toBe(['app-dev', 'database'])
        ->and($summary['node'])
        ->toBe('multi-role-1');
});
