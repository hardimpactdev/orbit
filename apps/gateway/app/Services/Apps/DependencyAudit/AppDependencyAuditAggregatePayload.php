<?php

declare(strict_types=1);

namespace App\Services\Apps\DependencyAudit;

use App\Enums\Apps\DependencyAuditStatus;
use App\Models\App;
use App\Models\AppDependencyAuditSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final readonly class AppDependencyAuditAggregatePayload
{
    /**
     * @return array{
     *     dependency_audit_status: string,
     *     dependency_warning_count: int,
     *     dependency_danger_count: int,
     *     last_dependency_audit_at: string|null,
     * }
     */
    public function forApp(App $app): array
    {
        $summaries = $this->summariesFor($app);

        if ($summaries->isEmpty()) {
            return [
                'dependency_audit_status' => DependencyAuditStatus::Unknown->value,
                'dependency_warning_count' => 0,
                'dependency_danger_count' => 0,
                'last_dependency_audit_at' => null,
            ];
        }

        return [
            'dependency_audit_status' => $this->aggregateStatus($summaries)->value,
            'dependency_warning_count' => (int) $summaries->sum('warning_count'),
            'dependency_danger_count' => (int) $summaries->sum('danger_count'),
            'last_dependency_audit_at' => $this->latestAuditedAt($summaries),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function managerDetailsFor(App $app): array
    {
        $details = $this
            ->summariesFor($app)
            ->sortBy(fn (AppDependencyAuditSummary $summary): string => $summary->manager->value)
            ->map(fn (AppDependencyAuditSummary $summary): array => [
                'manager' => $summary->manager->value,
                'status' => $summary->status->value,
                'danger_count' => $summary->danger_count,
                'warning_count' => $summary->warning_count,
                'severity_counts' => $summary->severity_counts ?? [],
                'advisory_summary' => $summary->advisory_summary ?? [],
                'audited_at' => $summary->audited_at?->toIso8601String(),
                'failed_at' => $summary->failed_at?->toIso8601String(),
                'error_code' => $summary->error_code,
                'error_message' => $summary->error_message,
                'diagnostics' => $summary->diagnostics,
            ])
            ->values()
            ->all();

        /** @var list<array<string, mixed>> $details */
        return $details;
    }

    /**
     * @return Collection<int, AppDependencyAuditSummary>
     */
    private function summariesFor(App $app): Collection
    {
        if ($app->relationLoaded('dependencyAuditSummaries')) {
            return $app->dependencyAuditSummaries->toBase();
        }

        return $app->dependencyAuditSummaries()->get()->toBase();
    }

    /**
     * @param  Collection<int, AppDependencyAuditSummary>  $summaries
     */
    private function aggregateStatus(Collection $summaries): DependencyAuditStatus
    {
        $statuses = $summaries
            ->map(fn (AppDependencyAuditSummary $summary): DependencyAuditStatus => $summary->status)
            ->unique()
            ->values();

        if ($statuses->contains(
            fn (DependencyAuditStatus $status): bool => $status === DependencyAuditStatus::Findings,
        )) {
            return DependencyAuditStatus::Findings;
        }

        if ($statuses->contains(
            fn (DependencyAuditStatus $status): bool => $status === DependencyAuditStatus::Failed,
        )) {
            return DependencyAuditStatus::Failed;
        }

        if ($statuses->contains(
            fn (DependencyAuditStatus $status): bool => $status === DependencyAuditStatus::Unsupported,
        )) {
            return DependencyAuditStatus::Unsupported;
        }

        $applicable = $statuses->reject(
            fn (DependencyAuditStatus $status): bool => in_array(
                $status,
                [
                    DependencyAuditStatus::NotApplicable,
                    DependencyAuditStatus::Unknown,
                ],
                true,
            ),
        );

        if (
            $applicable->isNotEmpty()
            && $applicable->every(
                fn (DependencyAuditStatus $status): bool => $status === DependencyAuditStatus::Clean,
            )
        ) {
            return DependencyAuditStatus::Clean;
        }

        if ($statuses->every(
            fn (DependencyAuditStatus $status): bool => $status === DependencyAuditStatus::NotApplicable,
        )) {
            return DependencyAuditStatus::NotApplicable;
        }

        return DependencyAuditStatus::Unknown;
    }

    /**
     * @param  Collection<int, AppDependencyAuditSummary>  $summaries
     */
    private function latestAuditedAt(Collection $summaries): ?string
    {
        $latest = null;

        foreach ($summaries as $summary) {
            $candidate = $summary->audited_at ?? $summary->failed_at;

            if (! $candidate instanceof Carbon) {
                continue;
            }

            if ($latest === null || $candidate->greaterThan($latest)) {
                $latest = $candidate;
            }
        }

        return $latest?->toIso8601String();
    }
}
