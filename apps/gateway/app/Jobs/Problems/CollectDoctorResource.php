<?php

declare(strict_types=1);

namespace App\Jobs\Problems;

use App\Actions\Doctor\RunDoctorAction;
use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorInspectionScope;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Problems\ProblemCollection;
use App\Domain\Problems\ProblemCollector;
use App\Models\Node;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use UnexpectedValueException;

final class CollectDoctorResource extends ProblemCollectionJob
{
    /** @param list<int> $nodeIds */
    public function __construct(string $collection, array $nodeIds, public int $nodeId, public DoctorFamily $family, public int|string $resourceId)
    {
        parent::__construct($collection, $nodeIds);
    }

    public function handle(RunDoctorAction $doctor, ProblemCollector $collector): void
    {
        $contexts = [];
        foreach ($this->nodeIds as $id) {
            $stored = Cache::get(ProblemCollection::contextKey($this->collection, $id));
            if ($stored === null) {
                continue;
            }
            if (! is_array($stored)) {
                throw new UnexpectedValueException('Invalid cached Doctor observation.');
            }
            $node = Node::query()->find($id);
            if ($node instanceof Node) {
                $contexts[$id] = new DoctorNodeContext($node, NodeInspectionData::fromArray(Arr::array($stored, 'inspection')), inspectionFailed: Arr::boolean($stored, 'failed'));
            }
        }
        $context = $contexts[$this->nodeId] ?? null;
        if (! $context instanceof DoctorNodeContext) {
            $node = Node::query()->find($this->nodeId);
            if (! $node instanceof Node) {
                Cache::put($this->completionKey(), true, now()->addHour());

                return;
            }
            $context = new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null), inspectionFailed: true);
        }
        $context = new DoctorNodeContext($context->node, $context->inspection, $context->inspectionFailed, new DoctorInspectionScope($contexts), $this->resourceId);
        $report = $doctor->inspectFamily($context, $this->family);
        if (is_int($this->resourceId)) {
            $report->issues = array_values(array_filter($report->issues, fn (DoctorIssueData $issue): bool => $issue->resourceId === null || (string) $issue->resourceId === (string) $this->resourceId));
        }
        $collector->recordDoctor(DoctorReportData::fromNodes([DoctorNodeReportData::fromFamilies($this->nodeId, $context->node->name, [$report])]));
        Cache::put($this->completionKey(), true, now()->addHour());
    }

    public function completionKey(): string
    {
        return 'problems:collection:'.$this->collection.':done:'.$this->nodeId.':'.$this->family->value.':'.$this->resourceId;
    }

    public function recordFailure(ProblemCollector $collector): void
    {
        $resourceId = $this->resourceId;
        if ($this->family === DoctorFamily::DatabaseConnection) {
            $resourceId = (int) substr((string) $resourceId, 11);
        } elseif ($resourceId === 'inventory' || ($this->family === DoctorFamily::Tool && is_string($resourceId) && str_starts_with($resourceId, 'instance:'))) {
            $resourceId = null;
        }
        $issue = DoctorIssueData::fromInternal($this->family, $this->family->value.'.inspection_failed', DoctorIssueKind::Unverifiable, $resourceId, null, 'Doctor collection did not complete on Node '.$this->nodeId.'.', 'completed within five seconds', 'failed');
        $collector->recordDoctor(DoctorReportData::fromNodes([DoctorNodeReportData::fromFamilies($this->nodeId, (string) $this->nodeId, [DoctorFamilyReportData::fromIssues($this->family, 0, [$issue])])]));
    }
}
