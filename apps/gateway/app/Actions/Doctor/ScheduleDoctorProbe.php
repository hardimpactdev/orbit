<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\DoctorResourceKind;
use App\Domain\Doctor\DoctorResources;
use App\Domain\Doctor\ScheduleDoctorIssueCode;
use App\Domain\Doctor\ScheduleInspectionData;
use App\Domain\Doctor\ScheduleStateInspector;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Collection;

final readonly class ScheduleDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private ScheduleStateInspector $inspector,
        private ScheduleTargetResolver $targets,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Schedule;
    }

    /** @return list<DoctorResources> */
    public function resources(Node $node): array
    {
        return [...DoctorResources::groups($this->rows($node)->modelKeys()), new DoctorResources(DoctorResourceKind::Inventory)];
    }

    /** @return Collection<int, Schedule> */
    private function rows(Node $node): Collection
    {
        return Schedule::query()->where('host_node_id', $node->id)->orderBy('id')
            ->get();
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $schedules = $this->rows($context->node);
        $schedules = $context->select($schedules);

        if (! $context->inspection->reachable && $schedules->isNotEmpty()) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Schedule, $schedules->count(), [
                new DoctorIssueData(
                    ScheduleDoctorIssueCode::NodeUnreachable,
                    DoctorIssueKind::Unverifiable,
                    'schedule',
                    DoctorResources::nodeCheckId($context->node->id),
                    null,
                    'Schedule state cannot be inspected because the Node is unreachable.',
                    'reachable',
                    'unreachable',
                ),
            ]);
        }

        $issues = [];

        foreach ($schedules as $schedule) {
            try {
                $target = $this->targets->forInspection($schedule);

                if ($target->node->id !== $schedule->host_node_id) {
                    $issues[] = $this->issue($schedule, ScheduleDoctorIssueCode::PlacementMismatch);

                    continue;
                }

                $inspection = $this->inspector->inspect($schedule);
                $issues = [...$issues, ...$this->inspectionIssues($schedule, $inspection)];
            } catch (\Throwable) {
                $issues[] = $this->issue(
                    $schedule,
                    ScheduleDoctorIssueCode::InspectionFailed,
                    DoctorIssueKind::Unverifiable,
                );
            }
        }

        $knownIds = $context->resources?->kind === DoctorResourceKind::Inventory
            ? Schedule::query()->where('host_node_id', $context->node->id)->pluck('id')->all()
            : $schedules->pluck('id')->all();
        if (! $context->inspection->reachable || ($context->resources !== null && $context->resources->kind !== DoctorResourceKind::Inventory) || ! $this->scansForOrphans($context, $knownIds !== [])) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Schedule, $schedules->count(), $issues, array_values($schedules->modelKeys()), $context->inspection->reachable ? [DoctorResources::nodeCheckId($context->node->id) => [ScheduleDoctorIssueCode::NodeUnreachable->value]] : []);
        }

        $inventories = [];
        try {
            $orphans = $this->inspector->orphanIds($context->node, array_values(array_filter(
                $knownIds,
                is_string(...),
            )));
            $inventories[ScheduleDoctorIssueCode::OrphanArtifact->value] = $orphans;
            foreach ($orphans as $id) {
                $issues[] = new DoctorIssueData(
                    ScheduleDoctorIssueCode::OrphanArtifact,
                    DoctorIssueKind::Drift,
                    'schedule',
                    $id,
                    null,
                    'An Orbit Schedule artifact has no matching Schedule intent.',
                    'absent',
                    'present',
                );
            }
        } catch (\Throwable) {
            $issues[] = new DoctorIssueData(
                ScheduleDoctorIssueCode::InspectionFailed,
                DoctorIssueKind::Unverifiable,
                'schedule',
                DoctorResources::nodeCheckId($context->node->id, DoctorResourceKind::Inventory),
                null,
                'Schedule artifact inventory could not be inspected.',
                'verifiable',
                'unverifiable',
            );
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Schedule, $schedules->count(), $issues, array_values($schedules->modelKeys()), [DoctorResources::nodeCheckId($context->node->id) => [ScheduleDoctorIssueCode::NodeUnreachable->value], DoctorResources::nodeCheckId($context->node->id, DoctorResourceKind::Inventory) => [ScheduleDoctorIssueCode::InspectionFailed->value]], observedInventories: $inventories);
    }

    /**
     * The orphan scan needs SSH and passwordless sudo. A Node with no role, such as an operator laptop,
     * has no Orbit-managed sudo, so Doctor scans it only while it hosts a Schedule. An unreachable
     * Node without Schedules has nothing this family can report.
     */
    private function scansForOrphans(DoctorNodeContext $context, bool $hasSchedules): bool
    {
        if ($hasSchedules) {
            return true;
        }

        return $context->inspection->reachable && $context->node->roles()->exists();
    }

    /** @return list<DoctorIssueData> */
    private function inspectionIssues(Schedule $schedule, ScheduleInspectionData $inspection): array
    {
        if (! $inspection->artifactsPresent) {
            return [$this->issue($schedule, ScheduleDoctorIssueCode::ArtifactMissing)];
        }

        $checks = [
            [ScheduleDoctorIssueCode::ArtifactPermissionsMismatch, $inspection->permissionsMatch],
            [ScheduleDoctorIssueCode::SpecificationMismatch, $inspection->specificationMatch],
            [ScheduleDoctorIssueCode::TimerStateMismatch, $inspection->timerStateMatch],
            [ScheduleDoctorIssueCode::CalendarMismatch, $inspection->calendarMatch],
            [ScheduleDoctorIssueCode::ExecutionContextMismatch, $inspection->executionContextMatch],
            [ScheduleDoctorIssueCode::CompletionCallbackMismatch, $inspection->completionCallbackMatch],
        ];
        $issues = [];

        foreach ($checks as [$code, $matches]) {
            if (! $matches) {
                $issues[] = $this->issue($schedule, $code);
            }
        }

        return $issues;
    }

    private function issue(
        Schedule $schedule,
        ScheduleDoctorIssueCode $code,
        DoctorIssueKind $kind = DoctorIssueKind::Drift,
    ): DoctorIssueData {
        return new DoctorIssueData(
            $code,
            $kind,
            'schedule',
            $schedule->id,
            $schedule->name,
            'Schedule intent does not match its bounded host observation.',
            $kind === DoctorIssueKind::Unverifiable ? 'verifiable' : 'matching',
            $kind === DoctorIssueKind::Unverifiable ? 'unverifiable' : 'mismatch',
        );
    }
}
