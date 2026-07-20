<?php

declare(strict_types=1);

use App\Data\Apps\DependencyAuditParsedResult;
use App\Enums\Apps\DependencyAuditManager;
use App\Enums\Apps\DependencyAuditStatus;
use App\Models\Node;
use App\Models\Project;
use App\Services\Apps\DependencyAudit\AppDependencyAuditAggregatePayload;
use App\Services\Apps\DependencyAudit\AppDependencyAuditSummaryStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('stores parsed summaries and aggregates app dependency audit posture', function (): void {
    $app = Project::factory()
        ->for(Node::factory(), 'node')
        ->create(['name' => 'docs']);

    $store = app(AppDependencyAuditSummaryStore::class);

    $store->recordParsed(
        app: $app,
        manager: DependencyAuditManager::Composer,
        parsed: new DependencyAuditParsedResult(
            status: DependencyAuditStatus::Findings,
            dangerCount: 1,
            warningCount: 2,
            severityCounts: ['high' => 1, 'medium' => 2],
            advisorySummary: [
                [
                    'package_name' => 'laravel/framework',
                    'severity' => 'high',
                    'title' => 'Framework advisory',
                ],
            ],
        ),
        auditedAt: Carbon::parse('2026-07-02 09:15:00', 'UTC'),
    );
    $store->recordParsed(
        app: $app,
        manager: DependencyAuditManager::Npm,
        parsed: new DependencyAuditParsedResult(
            status: DependencyAuditStatus::Clean,
            dangerCount: 0,
            warningCount: 0,
            severityCounts: [],
            advisorySummary: [],
        ),
        auditedAt: Carbon::parse('2026-07-02 10:30:00', 'UTC'),
    );

    $payload = app(AppDependencyAuditAggregatePayload::class)->forApp($app->refresh());
    $details = app(AppDependencyAuditAggregatePayload::class)->managerDetailsFor($app->refresh());

    expect($payload)
        ->toBe([
            'dependency_audit_status' => 'findings',
            'dependency_warning_count' => 2,
            'dependency_danger_count' => 1,
            'last_dependency_audit_at' => '2026-07-02T10:30:00+00:00',
        ])
        ->and($details)
        ->toHaveCount(2)
        ->and($details[0]['manager'])
        ->toBe('composer')
        ->and($details[0]['status'])
        ->toBe('findings')
        ->and($details[0]['severity_counts'])
        ->toBe(['high' => 1, 'medium' => 2])
        ->and($details[0]['advisory_summary'])
        ->toBe([
            [
                'package_name' => 'laravel/framework',
                'severity' => 'high',
                'title' => 'Framework advisory',
            ],
        ])
        ->and($details[1]['manager'])
        ->toBe('npm')
        ->and($details[1]['status'])
        ->toBe('clean');
});

it('stores failed and not applicable summaries distinctly from clean audits', function (): void {
    $app = Project::factory()
        ->for(Node::factory(), 'node')
        ->create(['name' => 'docs']);

    $store = app(AppDependencyAuditSummaryStore::class);

    $failed = $store->recordFailed(
        app: $app,
        manager: DependencyAuditManager::Composer,
        errorCode: 'missing_binary',
        message: 'Required composer binary is not available on the owning node.',
    );
    $notApplicable = $store->recordNotApplicable($app, DependencyAuditManager::Npm);

    expect($failed->status)
        ->toBe(DependencyAuditStatus::Failed)
        ->and($failed->error_code)
        ->toBe('missing_binary')
        ->and($failed->error_message)
        ->toBe('Required composer binary is not available on the owning node.')
        ->and($failed->audited_at)
        ->toBeNull()
        ->and($failed->failed_at)
        ->not
        ->toBeNull()
        ->and($notApplicable->status)
        ->toBe(DependencyAuditStatus::NotApplicable)
        ->and($notApplicable->error_code)
        ->toBe('missing_lockfile')
        ->and($notApplicable->danger_count)
        ->toBe(0)
        ->and($notApplicable->warning_count)
        ->toBe(0);
});
