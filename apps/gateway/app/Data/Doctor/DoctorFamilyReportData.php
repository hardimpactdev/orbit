<?php

declare(strict_types=1);

namespace App\Data\Doctor;

use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyStatus;
use App\Domain\Doctor\DoctorIssueKind;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DoctorFamilyReportData extends Data
{
    /** @var list<int|string> */
    #[Hidden]
    public array $observedResources = [];

    /** @var array<int|string, list<string>> */
    #[Hidden]
    public array $observedChecks = [];

    /** @var array<string, list<int|string>> */
    #[Hidden]
    public array $observedInventories = [];

    /** @param list<DoctorIssueData> $issues */
    public function __construct(
        public DoctorFamily $family,
        public DoctorFamilyStatus $status,
        public int $checked,
        public array $issues,
    ) {}

    /**
     * @param  list<DoctorIssueData>  $issues
     * @param  list<int|string>  $observedResources
     * @param  array<int|string, list<string>>  $observedChecks
     * @param  array<string, list<int|string>>  $observedInventories
     */
    public static function fromIssues(DoctorFamily $family, int $checked, array $issues, array $observedResources = [], array $observedChecks = [], array $observedInventories = []): self
    {
        $status = DoctorFamilyStatus::Healthy;
        if (array_any(
            $issues,
            static fn (DoctorIssueData $issue): bool => $issue->kind === DoctorIssueKind::Drift,
        )) {
            $status = DoctorFamilyStatus::Drift;
        }
        if (array_any(
            $issues,
            static fn (DoctorIssueData $issue): bool => $issue->kind === DoctorIssueKind::Unverifiable,
        )) {
            $status = DoctorFamilyStatus::Unverifiable;
        }

        $report = new self($family, $status, $checked, $issues);
        $report->observedResources = $observedResources;
        $report->observedChecks = $observedChecks;
        $report->observedInventories = $observedInventories;

        return $report;
    }
}
