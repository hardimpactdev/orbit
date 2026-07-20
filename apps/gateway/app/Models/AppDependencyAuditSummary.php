<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Apps\DependencyAuditManager;
use App\Enums\Apps\DependencyAuditStatus;
use Database\Factories\AppDependencyAuditSummaryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $app_id
 * @property DependencyAuditManager $manager
 * @property DependencyAuditStatus $status
 * @property int $danger_count
 * @property int $warning_count
 * @property array<string, int>|null $severity_counts
 * @property list<array<string, mixed>>|null $advisory_summary
 * @property Carbon|null $audited_at
 * @property Carbon|null $failed_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array<string, mixed>|null $diagnostics
 * @property-read Project $app
 */
class AppDependencyAuditSummary extends Model
{
    /** @use HasFactory<AppDependencyAuditSummaryFactory> */
    use HasFactory;

    #[Override]
    protected $table = 'app_dependency_audit_summaries';

    #[Override]
    protected $fillable = [
        'app_id',
        'manager',
        'status',
        'danger_count',
        'warning_count',
        'severity_counts',
        'advisory_summary',
        'audited_at',
        'failed_at',
        'error_code',
        'error_message',
        'diagnostics',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'manager' => DependencyAuditManager::class,
            'status' => DependencyAuditStatus::class,
            'danger_count' => 'integer',
            'warning_count' => 'integer',
            'severity_counts' => 'array',
            'advisory_summary' => 'array',
            'audited_at' => 'datetime',
            'failed_at' => 'datetime',
            'diagnostics' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'app_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function app(): BelongsTo
    {
        return $this->project();
    }
}
