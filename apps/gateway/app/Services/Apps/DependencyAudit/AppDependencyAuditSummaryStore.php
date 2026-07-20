<?php

declare(strict_types=1);

namespace App\Services\Apps\DependencyAudit;

use App\Data\Apps\DependencyAuditParsedResult;
use App\Enums\Apps\DependencyAuditManager;
use App\Enums\Apps\DependencyAuditStatus;
use App\Models\AppDependencyAuditSummary;
use App\Models\Project;
use Illuminate\Support\Carbon;

final readonly class AppDependencyAuditSummaryStore
{
    public function recordParsed(
        Project $app,
        DependencyAuditManager $manager,
        DependencyAuditParsedResult $parsed,
        ?Carbon $auditedAt = null,
    ): AppDependencyAuditSummary {
        return $this->upsert($app, $manager, [
            'status' => $parsed->status,
            'danger_count' => $parsed->dangerCount,
            'warning_count' => $parsed->warningCount,
            'severity_counts' => $parsed->severityCounts,
            'advisory_summary' => $parsed->advisorySummary,
            'audited_at' => $auditedAt ?? now(),
            'failed_at' => null,
            'error_code' => null,
            'error_message' => null,
            'diagnostics' => null,
        ]);
    }

    public function recordNotApplicable(Project $app, DependencyAuditManager $manager): AppDependencyAuditSummary
    {
        return $this->upsert($app, $manager, [
            'status' => DependencyAuditStatus::NotApplicable,
            'danger_count' => 0,
            'warning_count' => 0,
            'severity_counts' => [],
            'advisory_summary' => [],
            'audited_at' => now(),
            'failed_at' => null,
            'error_code' => 'missing_lockfile',
            'error_message' => "No supported lockfile detected for {$manager->value}.",
            'diagnostics' => null,
        ]);
    }

    public function recordUnsupported(
        Project $app,
        DependencyAuditManager $manager,
        string $errorCode,
        string $message,
        ?array $diagnostics = null,
    ): AppDependencyAuditSummary {
        return $this->upsert($app, $manager, [
            'status' => DependencyAuditStatus::Unsupported,
            'danger_count' => 0,
            'warning_count' => 0,
            'severity_counts' => [],
            'advisory_summary' => [],
            'audited_at' => now(),
            'failed_at' => null,
            'error_code' => $errorCode,
            'error_message' => $message,
            'diagnostics' => $diagnostics,
        ]);
    }

    public function recordFailed(
        Project $app,
        DependencyAuditManager $manager,
        string $errorCode,
        string $message,
        ?array $diagnostics = null,
    ): AppDependencyAuditSummary {
        return $this->upsert($app, $manager, [
            'status' => DependencyAuditStatus::Failed,
            'danger_count' => 0,
            'warning_count' => 0,
            'severity_counts' => [],
            'advisory_summary' => [],
            'audited_at' => null,
            'failed_at' => now(),
            'error_code' => $errorCode,
            'error_message' => $message,
            'diagnostics' => $diagnostics,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(Project $app, DependencyAuditManager $manager, array $attributes): AppDependencyAuditSummary
    {
        /** @var AppDependencyAuditSummary $summary */
        $summary = AppDependencyAuditSummary::query()->updateOrCreate(
            [
                'app_id' => $app->id,
                'manager' => $manager,
            ],
            $attributes,
        );

        return $summary->refresh();
    }
}
