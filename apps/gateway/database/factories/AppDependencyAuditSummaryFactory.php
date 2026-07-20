<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Apps\DependencyAuditManager;
use App\Enums\Apps\DependencyAuditStatus;
use App\Models\AppDependencyAuditSummary;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppDependencyAuditSummary>
 */
class AppDependencyAuditSummaryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'app_id' => Project::factory(),
            'manager' => DependencyAuditManager::Composer,
            'status' => DependencyAuditStatus::Clean,
            'danger_count' => 0,
            'warning_count' => 0,
            'severity_counts' => [],
            'advisory_summary' => [],
            'audited_at' => now(),
            'failed_at' => null,
            'error_code' => null,
            'error_message' => null,
            'diagnostics' => null,
        ];
    }

    public function findings(): static
    {
        return $this->state(fn (): array => [
            'status' => DependencyAuditStatus::Findings,
            'danger_count' => 2,
            'warning_count' => 14,
            'severity_counts' => [
                'high' => 2,
                'medium' => 14,
            ],
        ]);
    }
}
