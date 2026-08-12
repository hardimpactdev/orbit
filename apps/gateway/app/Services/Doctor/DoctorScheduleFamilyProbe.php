<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Schedules\SchedulesProbe;
use Illuminate\Support\Collection;

final readonly class DoctorScheduleFamilyProbe
{
    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private DoctorFamilyProbeRunner $familyProbeRunner,
        private SchedulesProbe $schedulesProbe,
        private NodeRoleAssignments $nodeRoleAssignments,
        private DoctorNodeScheduleTargets $scheduleTargets,
        private DoctorIssueFactory $doctorIssueFactory,
        private DoctorScheduleNodeResolver $scheduleNodeResolver,
    ) {}

    /**
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return list<DoctorIssue>
     */
    public function probe(Node $node, ?string $key, ?callable $onFamilyProgress): array
    {
        $gateway = $this->nodeRoleAssignments->nodeIsGateway($node);
        $schedules = $gateway
            ? $this->gatewaySchedules()
            : $this->nodeSchedules($node);
        $targets = $gateway
            ? collect([$node])->concat($schedules)->values()
            : $schedules;

        return $this->familyProbeRunner->run(
            node: $node,
            family: 'schedule',
            total: $targets->count(),
            key: $key,
            onFamilyProgress: $onFamilyProgress,
            probe: function (callable $addIssue, callable $advance) use ($targets): void {
                foreach ($targets as $target) {
                    if ($target instanceof Node) {
                        $this->probeGateway($target, $addIssue);
                        $advance();

                        continue;
                    }

                    $this->probeSchedule($target, $addIssue);
                    $advance();
                }
            },
        );
    }

    /**
     * @param  callable(DoctorIssue): void  $addIssue
     */
    private function probeGateway(Node $gateway, callable $addIssue): void
    {
        $snapshot = $this->schedulesProbe->introspectGateway($gateway);

        foreach ($this->schedulesProbe->diffGateway($gateway, $snapshot) as $entry) {
            $addIssue($this->gatewayIssue($entry, $gateway));
        }
    }

    /**
     * @param  callable(DoctorIssue): void  $addIssue
     */
    private function probeSchedule(Schedule $schedule, callable $addIssue): void
    {
        $snapshot = $this->schedulesProbe->introspect($schedule);

        foreach ($this->schedulesProbe->diff($schedule, $snapshot) as $entry) {
            $addIssue($this->scheduleIssue($entry, $schedule));
        }
    }

    /**
     * @return Collection<int, Schedule>
     */
    private function gatewaySchedules(): Collection
    {
        /** @mago-expect lint:inline-variable-return */
        $schedules = Schedule::query()
            ->with(['app', 'instance', 'node'])
            ->where('enabled', true)
            ->where('status', 'expected')
            ->orderBy('id')
            ->get()
            ->values();

        /** @var Collection<int, Schedule> $schedules */
        return $schedules;
    }

    /**
     * @return Collection<int, Schedule>
     */
    private function nodeSchedules(Node $node): Collection
    {
        /** @mago-expect lint:inline-variable-return */
        $schedules = $this->scheduleTargets
            ->expectedFor($node)
            ->with(['app', 'instance', 'node'])
            ->orderBy('id')
            ->get()
            ->values();

        /** @var Collection<int, Schedule> $schedules */
        return $schedules;
    }

    private function scheduleIssue(DriftEntry $entry, Schedule $schedule): DoctorIssue
    {
        return $this->doctorIssueFactory->fromDriftEntry(
            $entry,
            $this->scheduleNodeResolver->nameFor($schedule),
            detail: [
                ...($entry->detail ?? []),
                'schedule_key' => $schedule->schedule_key,
            ],
        );
    }

    private function gatewayIssue(DriftEntry $entry, Node $gateway): DoctorIssue
    {
        return $this->doctorIssueFactory->fromDriftEntry(
            $entry,
            $gateway->name,
            detail: $entry->detail ?? [],
        );
    }
}
