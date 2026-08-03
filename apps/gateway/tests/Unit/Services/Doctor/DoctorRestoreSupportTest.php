<?php

declare(strict_types=1);

use App\Enums\DoctorIssueDisposition;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Doctor\DoctorIssueCatalog;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorRestoreSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('registers every catalog genuine_drift code in DoctorRestoreSupport with matching action ids', function (): void {
    $missing = [];
    $mismatched = [];

    foreach (DoctorIssueCatalog::definitions() as $code => $definition) {
        if ($definition->disposition !== DoctorIssueDisposition::GenuineDrift) {
            continue;
        }

        if (! DoctorRestoreSupport::supports($code)) {
            $missing[] = $code;

            continue;
        }

        if ($definition->restoreAction !== DoctorRestoreSupport::actionId($code)) {
            $mismatched[] = $code;
        }
    }

    expect($missing)
        ->toBeEmpty('Genuine drift without DoctorRestoreSupport: '.implode(', ', $missing))
        ->and($mismatched)
        ->toBeEmpty('restore_action mismatch vs DoctorRestoreSupport: '.implode(', ', $mismatched));
});

it('only marks support-registered codes restorable', function (): void {
    expect(DoctorIssueCatalog::isRestorable('schedule.heartbeat_stale'))
        ->toBeFalse()
        ->and(DoctorIssueCatalog::isRestorable('workspace.path_missing'))
        ->toBeFalse()
        ->and(DoctorIssueCatalog::isRestorable('node.wireguard_peer_extra'))
        ->toBeFalse()
        ->and(DoctorIssueCatalog::isRestorable('node.access_permission_invalid'))
        ->toBeFalse()
        ->and(DoctorIssueCatalog::isRestorable('schedule.runtime_hibernator_missing'))
        ->toBeTrue()
        ->and(DoctorIssueCatalog::isRestorable('node.role_baseline_mismatch'))
        ->toBeTrue();
});

it('routes schedule hibernator restore without a schedule_key', function (): void {
    $node = Node::factory()->create(['name' => 'gateway', 'status' => 'active']);
    $runner = app(DoctorReportRunner::class);

    $method = new ReflectionMethod(DoctorReportRunner::class, 'applyScheduleIssue');
    $method->setAccessible(true);

    foreach (DoctorRestoreSupport::scheduleGatewayCodes() as $code) {
        if (! str_contains($code, 'hibernator')) {
            continue;
        }

        // Without gateway swarm this may fail or return action; must not return null for missing schedule_key.
        $result = $method->invoke(
            $runner,
            $node,
            $code,
            [],
            [
                'family' => 'schedule',
                'key' => $code,
                'code' => $code,
                'kind' => DriftKind::Missing->value,
            ],
        );

        expect($result)
            ->not->toBeNull("Expected schedule apply routing for {$code}")
            ->and($result['key'] ?? null)
            ->toBe($code)
            ->and($result['mode'] ?? null)
            ->toBe('restore');
    }
});

it('does not treat unsupported findings as restore candidates that poison multi-pass', function (): void {
    $node = Node::factory()->create(['name' => 'app-1', 'status' => 'active']);
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    // Synthetic annotation path: workspace genuine is no longer restorable.
    $runner = app(DoctorReportRunner::class);
    $annotate = new ReflectionMethod(DoctorReportRunner::class, 'annotateIssue');
    $annotate->setAccessible(true);

    $issue = $annotate->invoke($runner, [
        'family' => 'workspace',
        'node' => $node->name,
        'key' => 'workspace.path_missing',
        'code' => 'workspace.path_missing',
        'kind' => DriftKind::Missing->value,
        'summary' => 'Workspace path missing.',
        'detail' => ['workspace' => 'x', 'app' => 'y'],
    ]);

    expect($issue['restorable'] ?? null)
        ->toBeFalse()
        ->and($issue['disposition'] ?? null)
        ->toBe(DoctorIssueDisposition::RuntimeIncident->value)
        ->and($issue['restore_action'] ?? null)
        ->toBeNull();
});
