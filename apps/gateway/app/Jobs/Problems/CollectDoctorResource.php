<?php

declare(strict_types=1);

namespace App\Jobs\Problems;

use App\Actions\Doctor\RunDoctorAction;
use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorInspectionScope;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\DoctorResources;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Problems\ProblemCollection;
use App\Domain\Problems\ProblemCollector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Throwable;
use UnexpectedValueException;

final class CollectDoctorResource extends ProblemCollectionJob
{
    /** @param list<int> $nodeIds */
    public function __construct(string $collection, array $nodeIds, public int $nodeId, public DoctorFamily $family, public DoctorResources $resources)
    {
        parent::__construct($collection, $nodeIds);
    }

    public function handle(RunDoctorAction $doctor, ProblemCollector $collector, CommandDeadline $deadline): void
    {
        $contexts = [];
        foreach ($this->nodeIds as $id) {
            $stored = Cache::get(ProblemCollection::contextKey($this->collection, $id));
            if ($stored !== null && ! is_array($stored)) {
                throw new UnexpectedValueException('Invalid cached Doctor observation.');
            }
            $node = Node::query()->find($id);
            if ($node instanceof Node) {
                $contexts[$id] = $stored === null
                    ? new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null), inspectionFailed: true)
                    : new DoctorNodeContext($node, NodeInspectionData::fromArray(Arr::array($stored, 'inspection')), inspectionFailed: Arr::boolean($stored, 'failed'));
            }
        }
        $context = $contexts[$this->nodeId] ?? null;
        if (! $context instanceof DoctorNodeContext) {
            $node = Node::query()->find($this->nodeId);
            if (! $node instanceof Node) {

                return;
            }
            $context = new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null), inspectionFailed: true);
        }
        $context = new DoctorNodeContext($context->node, $context->inspection, $context->inspectionFailed, new DoctorInspectionScope($contexts), $this->resources);
        $report = $deadline->within(self::ProbeSeconds, fn () => $doctor->inspectFamily($context, $this->family));
        $collector->recordDoctor(DoctorReportData::fromNodes([DoctorNodeReportData::fromFamilies($this->nodeId, $context->node->name, [$report])]));
    }

    public function failed(?Throwable $exception): void
    {
        app(ProblemCollector::class)->recordDoctor(DoctorReportData::fromNodes([
            DoctorNodeReportData::fromFamilies($this->nodeId, (string) $this->nodeId, [
                DoctorFamilyReportData::fromIssues($this->family, 0, $this->resources->failures($this->family, $this->nodeId)),
            ]),
        ]));
    }
}
