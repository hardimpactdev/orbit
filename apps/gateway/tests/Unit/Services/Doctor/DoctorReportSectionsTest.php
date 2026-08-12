<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorTargetScope;
use App\Enums\DoctorIssueDisposition;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Doctor\DoctorFleetNodeProjection;
use App\Services\Doctor\DoctorFleetTargetProbe;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Doctor\DoctorProgressReportFactory;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorReportSections;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('keeps repeated report section rules out of report assemblers', function (): void {
    $runner = new ReflectionClass(DoctorReportRunner::class);
    $progress = new ReflectionClass(DoctorProgressReportFactory::class);
    $fleetProjection = new ReflectionClass(DoctorFleetNodeProjection::class);

    expect($runner->hasMethod('reportScope'))
        ->toBeFalse()
        ->and($runner->hasMethod('nodeRoles'))
        ->toBeFalse()
        ->and($runner->hasMethod('summary'))
        ->toBeFalse()
        ->and($runner->hasMethod('dispositionCounts'))
        ->toBeFalse()
        ->and($runner->hasMethod('serializeIssues'))
        ->toBeFalse()
        ->and($progress->hasMethod('summary'))
        ->toBeFalse()
        ->and($fleetProjection->hasProperty('nodeRoleAssignments'))
        ->toBeFalse();
});

it('owns the exact node and fleet scope sections', function (): void {
    $node = Node::factory()->create(['name' => 'doctor-sections-1', 'status' => 'active']);
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::Gateway->value,
        'status' => NodeRoleStatus::Active,
    ]);
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::Agent->value,
        'status' => NodeRoleStatus::Active,
    ]);
    $sections = app(DoctorReportSections::class);

    expect($sections->nodeScope(
        families: ['node', 'tool'],
        node: $node,
        key: 'node.updates',
        scope: DoctorTargetScope::from(
            app: 'docs',
            workspace: 'feature',
            instanceId: 42,
            instance: 'docs-web',
        ),
    ))
        ->toBe([
            'families' => ['node', 'tool'],
            'node' => 'doctor-sections-1',
            'role' => 'agent',
            'roles' => ['agent', 'gateway'],
            'self' => false,
            'app' => 'docs',
            'instance' => 'docs-web',
            'workspace' => 'feature',
            'key' => 'node.updates',
        ])
        ->and($sections->fleetScope(collect([$node]), ['node'], null))
        ->toBe([
            'families' => ['node'],
            'node' => null,
            'role' => 'fleet',
            'self' => false,
            'app' => null,
            'workspace' => null,
            'key' => null,
            'targets' => ['doctor-sections-1'],
        ]);
});

it('preserves the final and progress summary rules', function (): void {
    $actions = [
        ['status' => 'completed'],
        ['status' => 'created'],
        ['mode' => 'restore', 'status' => 'completed'],
        ['mode' => 'adopt', 'status' => 'updated'],
        ['status' => 'skipped'],
        ['status' => 'conflict'],
        ['status' => 'failed'],
        ['status' => 'planned'],
    ];
    $sections = app(DoctorReportSections::class);

    expect($sections->summary([['key' => 'one']], $actions))
        ->toBe([
            'issues' => 1,
            'fixed' => 1,
            'adopted' => 1,
            'skipped' => 1,
            'conflicts' => 1,
            'failed' => 1,
            'planned' => 1,
        ])
        ->and($sections->progressSummary('restore', [['key' => 'one']], $actions))
        ->toBe([
            'issues' => 1,
            'fixed' => 2,
            'adopted' => 1,
            'skipped' => 1,
            'conflicts' => 1,
            'failed' => 1,
            'planned' => 1,
        ])
        ->and($sections->progressSummary('adopt', [['key' => 'one']], $actions))
        ->toBe([
            'issues' => 1,
            'fixed' => 1,
            'adopted' => 3,
            'skipped' => 1,
            'conflicts' => 1,
            'failed' => 1,
            'planned' => 1,
        ]);
});

it('counts issue dispositions and serializes issues without reordering them', function (): void {
    $issueFactory = app(DoctorIssueFactory::class);
    $issues = array_map(
        static fn (string $key) => $issueFactory->fromArray([
            'family' => 'node',
            'node' => 'doctor-sections-1',
            'key' => $key,
            'kind' => DriftKind::Unverifiable->value,
            'summary' => $key,
            'detail' => [],
        ]),
        [
            'node.updates',
            'node.remote_shell_probe_failed',
            'node.platform_unsupported',
            'node.runtime_container_stopped',
        ],
    );
    $sections = app(DoctorReportSections::class);

    expect($sections->dispositions($issues))
        ->toBe([
            DoctorIssueDisposition::GenuineDrift->value => 1,
            DoctorIssueDisposition::BlockedInspection->value => 1,
            DoctorIssueDisposition::InvalidIntent->value => 1,
            DoctorIssueDisposition::RuntimeIncident->value => 1,
        ])
        ->and(array_column($sections->serializeIssues($issues), 'key'))
        ->toBe([
            'node.updates',
            'node.remote_shell_probe_failed',
            'node.platform_unsupported',
            'node.runtime_container_stopped',
        ]);
});

it('uses operator as the complete role set when a node has no active roles', function (): void {
    $node = Node::factory()->create(['status' => 'active']);

    expect(app(DoctorReportSections::class)->roles($node))->toBe(['operator']);
});

it('preserves the local executor failed report without disposition counts', function (): void {
    $node = Node::factory()->create([
        'name' => 'local-executor-failed',
        'status' => 'active',
    ]);
    $method = new ReflectionMethod(DoctorFleetTargetProbe::class, 'localExecutorFailedReport');
    $method->setAccessible(true);

    $report = $method->invoke(
        app(DoctorFleetTargetProbe::class),
        $node,
        ['node'],
        null,
        new RemoteLocalExecutorTransportFailed('agent push unavailable'),
    );

    expect($report)
        ->toBe([
            'healthy' => false,
            'mode' => 'verify',
            'scope' => [
                'families' => ['node'],
                'node' => 'local-executor-failed',
                'role' => 'operator',
                'roles' => ['operator'],
                'self' => false,
                'app' => null,
                'instance' => null,
                'workspace' => null,
                'key' => null,
            ],
            'summary' => [
                'issues' => 1,
                'fixed' => 0,
                'adopted' => 0,
                'skipped' => 0,
                'conflicts' => 0,
                'failed' => 0,
                'planned' => 0,
            ],
            'issues' => [[
                'family' => 'node',
                'node' => 'local-executor-failed',
                'key' => 'node.local_executor_probe_failed',
                'code' => 'node.local_executor_probe_failed',
                'kind' => 'unverifiable',
                'summary' => "Doctor probe failed on node 'local-executor-failed': agent push unavailable",
                'detail' => ['error' => 'agent push unavailable'],
                'disposition' => 'blocked_inspection',
                'restore_action' => null,
                'restorable' => false,
                'adoptable' => false,
            ]],
            'actions' => [],
        ])
        ->and($report['summary'])
        ->not->toHaveKey('dispositions');
});
